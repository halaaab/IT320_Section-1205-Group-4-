<?php
$tab = $_GET['tab'] ?? 'sell';
session_start();

require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Category.php';
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/PickupLocation.php';

if (empty($_SESSION['providerId'])) {
header('Location: ../shared/login.php');
exit;
}

if (isset($_GET['logout'])) {
session_destroy();
header('Location: ../shared/landing.php');
exit;
}

$providerId = $_SESSION['providerId'];

$itemModel = new Item();
$categoryModel = new Category();
$providerModel = new Provider();
$locationModel = new PickupLocation();

$categories = $categoryModel->getAll();

$itemFilter = [];
if ($tab === 'sell') {
$itemFilter['listingType'] = 'sell';
} elseif ($tab === 'donate') {
$itemFilter['listingType'] = 'donate';
}

$items = $itemModel->getByProvider($providerId, $itemFilter);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$photoUrl = '';

if (!empty($_FILES['itemPhoto']['name'])) {
$uploadDir = '../../uploads/';
if (!is_dir($uploadDir)) {
mkdir($uploadDir, 0777, true);
}

$fileName = time() . '_' . basename($_FILES['itemPhoto']['name']);
$targetPath = $uploadDir . $fileName;

if (move_uploaded_file($_FILES['itemPhoto']['tmp_name'], $targetPath)) {
$photoUrl = '../../uploads/' . $fileName;
}
}

$data = [
'categoryId' => $_POST['categoryId'],
'pickupLocationId' => $_POST['pickupLocationId'],
'itemName' => $_POST['itemName'],
'description' => $_POST['itemDetails'],
'photoUrl' => $photoUrl,
'expiryDate' => $_POST['expiryDate'],
'listingType' => $_POST['itemType'],
'price' => ($_POST['itemType'] === 'sell') ? ($_POST['price'] ?? 0) : 0,
'quantity' => 1,
'pickupTimes' => $_POST['pickupTimes'] ?? [],
];

$itemModel->create($providerId, $data);

header('Location: provider-items.php?tab=' . urlencode($tab));
exit;
}

$provider = $providerModel->findById($providerId);
$locations = $locationModel->getByProvider($providerId);
$defaultLocation = !empty($locations) ? $locations[0] : null;

$providerName = $provider['businessName'] ?? 'Provider';
$providerEmail = $provider['email'] ?? '';
$providerPhone = $provider['phoneNumber'] ?? '';
$providerLogo = $provider['businessLogo'] ?? '';

$firstName = explode(' ', $providerName)[0];
?>


<!DOCTYPE html>
<html lang="en">
<head>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Playfair Display', serif; background: #f4f7fc; min-height: 100vh; display: flex; flex-direction: column; }

    /* ── NAVBAR ── */
    nav.navbar {
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 40px; height: 72px;
      background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%);
      position: sticky; top: 0; z-index: 100;
      box-shadow: 0 2px 16px rgba(26,58,107,0.18);
    }
    .nav-left { display: flex; align-items: center; gap: 0; }
    .nav-logo { height: 90px; }
    .nav-search-wrap { position: relative; }
    .nav-search-wrap svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); opacity: 0.6; pointer-events: none; }
    .nav-search-wrap input { background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.4); border-radius: 50px; padding: 10px 18px 10px 40px; color: #fff; font-size: 14px; outline: none; width: 260px; font-family: 'Playfair Display', serif; transition: width 0.3s, background 0.2s; }
    .nav-search-wrap input::placeholder { color: rgba(255,255,255,0.6); }
    .nav-search-wrap input:focus { width: 320px; background: rgba(255,255,255,0.25); }
    .nav-right { display: flex; align-items: center; gap: 14px; }
    .nav-provider-info { display: flex; align-items: center; gap: 14px; }
    .nav-provider-logo { width: 46px; height: 46px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #fff; overflow: hidden; flex-shrink: 0; }
    .nav-provider-logo img { width: 100%; height: 100%; object-fit: cover; }
    .nav-provider-text { display: flex; flex-direction: column; }
    .nav-provider-name { font-size: 15px; font-weight: 700; color: #fff; }
    .nav-provider-email { font-size: 12px; color: rgba(255,255,255,0.75); }

    /* ── LAYOUT ── */
    .page-body { display: flex; flex: 1; }

    /* ── SIDEBAR ── */
    .sidebar { width: 240px; min-height: calc(100vh - 72px); background: linear-gradient(180deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%); display: flex; flex-direction: column; padding: 36px 24px 28px; flex-shrink: 0; }
    .sidebar-welcome { color: rgba(255,255,255,0.75); font-size: 17px; font-weight: 400; margin-bottom: 4px; }
    .sidebar-name { color: rgba(255,255,255,0.55); font-size: 38px; font-weight: 700; line-height: 1.1; margin-bottom: 36px; }
    .sidebar-nav { display: flex; flex-direction: column; gap: 16px; flex: 1; }
    .sidebar-link { display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,0.75); text-decoration: none; font-size: 16px; font-weight: 400; padding: 10px 8px; border-radius: 0; transition: color 0.2s; background: none !important; -webkit-tap-highlight-color: transparent; }
    .sidebar-link:hover { color: #fff; }
    .sidebar-link.active { color: #fff !important; font-weight: 700; border-bottom: 2px solid rgba(255,255,255,0.5); background: none !important; padding-bottom: 6px; }
    .sidebar-link svg { flex-shrink: 0; opacity: 0.8; }
    .sidebar-link.active svg { opacity: 1; }
    .sidebar-logout { margin-top: 24px; background: #fff; color: #1a3a6b; border: none; border-radius: 50px; padding: 12px 0; font-size: 15px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; width: 100%; text-align: center; transition: background 0.2s; }
    .sidebar-logout:hover { background: #e8f0ff; }
    .sidebar-footer { margin-top: 24px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,0.15); display: flex; flex-direction: column; gap: 10px; align-items: center; }
    .sidebar-footer-social { display: flex; align-items: center; justify-content: center; gap: 8px; }
    .sidebar-social-icon { width: 28px; height: 28px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,0.4); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,0.8); font-size: 11px; font-weight: 700; text-decoration: none; transition: background 0.2s; }
    .sidebar-social-icon:hover { background: rgba(255,255,255,0.15); }
    .sidebar-footer-copy { color: rgba(255,255,255,0.45); font-size: 10px; display: flex; align-items: center; justify-content: center; gap: 4px; flex-wrap: wrap; }center; gap: 4px; flex-wrap: wrap; }
   .segmented{
display:flex;
justify-content:flex-start;
gap:16px;
margin-top:70px;
margin-left:8px;
margin-bottom:24px;
}

.seg-btn{
min-width:180px;
padding:10px 20px;
border-radius:18px;
border:1.8px solid #ea8b2c;
background:#fff;
color:#183482;
font-size:19px;
font-family:'Playfair Display',serif;
cursor:pointer;
text-decoration:none;
text-align:center;
display:inline-block;
transition:background .2s,color .2s;
}

.seg-btn.active{
background:#f6811f;
color:#fff;
border-color:#f6811f;
}

.seg-btn:not(.active):hover{
background:#fff8f2;
}
.main{
flex:1;
padding:40px 40px;
overflow-y:auto;
}

.segmented{
display:flex;
justify-content:flex-start;
gap:16px;
margin-top:32px;
margin-left:8px;
margin-bottom:28px;
}

.seg-btn{
min-width:180px;
padding:10px 20px;
border-radius:18px;
border:1.8px solid #ea8b2c;
background:#fff;
color:#183482;
font-size:19px;
font-family:'Playfair Display',serif;
cursor:pointer;
text-decoration:none;
text-align:center;
display:inline-block;
transition:background .2s,color .2s;
}

.seg-btn.active{
background:#f6811f;
color:#fff;
border-color:#f6811f;
}

.seg-btn:not(.active):hover{
background:#fff8f2;
}

.add-item-wrap{
margin-top:18px;
}

.add-item-wrap h1 { font-size: 34px; font-weight: 700; font-family: 'Playfair Display', serif; background: linear-gradient(90deg, #143496 0%, #66a1d9 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; display: inline-block; }
    .add-item-wrap h1 span { -webkit-text-fill-color: transparent; }

.add-item-card{
width:100%;
max-width:760px;
background:#f7fbff;
border:1.5px solid #cfdbea;
border-radius:26px;
padding:28px 26px 30px;
box-shadow:0 8px 28px rgba(26,58,107,0.06);
}

.provider-preview-logo{
width:140px;
height:70px;
margin:0 auto 18px;
display:flex;
align-items:center;
justify-content:center;
overflow:hidden;
}

.provider-preview-logo img{
max-width:100%;
max-height:100%;
object-fit:contain;
}

.add-item-form{
display:flex;
flex-direction:column;
gap:18px;
}

.form-input,
.form-select,
.form-textarea,
.form-date,
.form-time{
width:100%;
border:1.5px solid #cfdbea;
border-radius:999px;
padding:13px 18px;
font-family:'Playfair Display', serif;
font-size:18px;
color:#183482;
background:#fff;
outline:none;
}

.form-textarea{
border-radius:22px;
resize:none;
min-height:110px;
}

.form-input:focus,
.form-select:focus,
.form-textarea:focus,
.form-date:focus,
.form-time:focus{
border-color:#ea8b2c;
box-shadow:0 0 0 3px rgba(234,139,44,0.10);
}

.section-label{
font-size:16px;
font-weight:700;
color:#183482;
margin-bottom:8px;
}

.icon-label{
display:flex;
align-items:center;
gap:10px;
font-size:16px;
font-weight:700;
color:#183482;
margin-bottom:10px;
}

.icon-label span.icon{
color:#ea8b2c;
font-size:28px;
line-height:1;
}

.type-row{
display:flex;
align-items:center;
gap:24px;
flex-wrap:wrap;
}

.type-option{
display:flex;
align-items:center;
gap:10px;
font-size:18px;
color:#183482;
cursor:pointer;
}

.type-option input{
accent-color:#ea8b2c;
width:18px;
height:18px;
}
.type-option input:focus{
outline:none !important;
box-shadow:none !important;
}

.price-wrap{
display:none;
}

.time-picker-row{
display:flex;
gap:12px;
align-items:center;
flex-wrap:wrap;
}

.add-time-btn{
border:none;
background:#ea8b2c;
color:#fff;
border-radius:999px;
padding:10px 18px;
font-family:'Playfair Display', serif;
font-size:16px;
cursor:pointer;
}

.add-time-btn:hover{
background:#d87917;
}

.time-list{
display:flex;
flex-wrap:wrap;
gap:10px;
margin-top:12px;
}

.time-chip{
display:inline-flex;
align-items:center;
gap:8px;
background:#fff;
border:1.5px solid #cfdbea;
color:#183482;
border-radius:999px;
padding:8px 14px;
font-size:15px;
}

.time-chip button{
border:none;
background:transparent;
color:#ea8b2c;
cursor:pointer;
font-size:16px;
line-height:1;
}

.form-grid{
display:grid;
grid-template-columns:1fr 1fr;
gap:16px;
}

.submit-row{
display:flex;
justify-content:center;
margin-top:8px;
}

.add-btn{
min-width:160px;
border:none;
background:#f6811f;
color:#fff;
border-radius:999px;
padding:12px 30px;
font-size:22px;
font-family:'Playfair Display', serif;
cursor:pointer;
}

.add-btn:hover{
background:#df7413;
}

@media (max-width: 760px){
.form-grid{
grid-template-columns:1fr;
}

.add-item-title{
font-size:32px;
}

.add-item-card{
padding:22px 18px 24px;
}
}
.field-error{
color:#d64545;
font-size:13px;
margin-top:6px;
display:none;
}

.field-error.show{
display:block;
}

.form-input.error,
.form-textarea.error,
.form-select.error,
.form-date.error,
.form-time.error{
border:1.5px solid #d64545 !important;
}
.provider-preview-logo img{
    border-radius:8px;
}
/* ── ORDERS SHELL ── */
.orders-shell{background:#fff;border:1.8px solid #d2dce8;border-radius:28px;padding:24px;box-shadow:0 2px 12px rgba(26,58,107,.06)}
/* ── ORDER ROW ── */
.order-row{background:#f5f8fc;border:1.6px solid #d2dce8;border-radius:22px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;margin:14px 0;text-decoration:none;color:inherit;transition:box-shadow .2s}
.order-row:hover{box-shadow:0 4px 18px rgba(26,58,107,.1)}
.order-left{display:flex;align-items:center;gap:16px}
.logo-box{width:130px;height:100px;border-radius:20px;border:1.4px solid #d2dce8;background:#fff;display:flex;align-items:center;justify-content:center;padding:8px;text-align:center;font-size:26px;color:#c85a3a;font-weight:700;font-family:'Playfair Display',serif;flex-shrink:0;line-height:1.1;text-transform:uppercase}
.order-info h3{margin:0 0 6px;font-size:20px;color:#183482;font-weight:700}
.info-line{display:flex;align-items:center;gap:8px;color:#4166ad;font-size:15px;margin:5px 0}
.info-line svg{flex-shrink:0}
.order-right{display:flex;flex-direction:column;align-items:flex-end;gap:12px;flex-shrink:0}
.order-total{color:#ea8b2c;font-size:22px;font-weight:700}
.rial-sm{font-size:14px;margin-right:2px}
.cancel-btn{display:inline-flex;align-items:center;justify-content:center;background:#f7a15d;color:#fff;border:none;border-radius:14px;padding:10px 28px;font-size:18px;font-family:'Playfair Display',serif;cursor:pointer;transition:background .2s;text-decoration:none}
.cancel-btn:hover{background:#e08a45}
.view-item-btn{
display:inline-block;
margin-top:10px;
background:#183482;
color:#fff;
padding:8px 18px;
border-radius:20px;
text-decoration:none;
font-size:14px;
font-family:'Playfair Display', serif;
font-weight:600;
border:none;
cursor:pointer;
transition:background .2s ease, transform .2s ease;
}

.view-item-btn:hover{
background:#10275f;
transform:translateY(-1px);
}
.form-select{
width:100% !important;
height:48px !important;
border:1.4px solid #d7dee9 !important;
border-radius:14px !important;
padding:0 42px 0 16px !important;
font-family:'Playfair Display', serif !important;
font-size:15px !important;
color:#183482 !important;
background-color:#fff !important;

appearance:none !important;
-webkit-appearance:none !important;
-moz-appearance:none !important;

background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' fill='%23183482' viewBox='0 0 16 16'%3E%3Cpath d='M3.204 5h9.592L8 11 3.204 5z'/%3E%3C/svg%3E") !important;
background-repeat:no-repeat !important;
background-position:right 14px center !important;
background-size:12px !important;

outline:none !important;
box-shadow:none !important;
cursor:pointer !important;
}

.form-select:hover{
border-color:#ea8b2c !important;
}

.form-select:focus{
border-color:#ea8b2c !important;
box-shadow:0 0 0 2px rgba(234,139,44,0.10) !important;
}
.logo-box img{
width:100%;
height:100%;
object-fit:cover;
border-radius:18px;
}
    </style>
  <title>RePlate – My Items</title>
</head>
<body>
  <nav class="navbar">
    <div class="nav-left">
      <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
    </div>
    <div class="nav-right">
      <div class="nav-search-wrap">
        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" placeholder="Search......"/>
      </div>
      <div class="nav-provider-info">
        <div class="nav-provider-logo">
          <?php if ($providerLogo): ?>
            <img src="<?= htmlspecialchars($providerLogo) ?>" alt="<?= htmlspecialchars($providerName) ?>"/>
          <?php else: ?>
            <?= mb_strtoupper(mb_substr($providerName, 0, 1)) ?>
          <?php endif; ?>
        </div>
        <div class="nav-provider-text">
          <span class="nav-provider-name"><?= htmlspecialchars($providerName) ?></span>
          <span class="nav-provider-email"><?= htmlspecialchars($providerEmail) ?></span>
        </div>
      </div>
    </div>
  </nav>
<div class="page-body">
    <aside class="sidebar">
      <p class="sidebar-welcome">Welcome Back ,</p>
      <p class="sidebar-name"><?= htmlspecialchars($firstName) ?></p>
      <nav class="sidebar-nav">
        <a href="provider-dashboard.php" class="sidebar-link ">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          DashBoard
        </a>
        <a href="provider-items.php" class="sidebar-link active">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 3H8a2 2 0 00-2 2v2h12V5a2 2 0 00-2-2z"/></svg>
          Items
        </a>
        <a href="provider-orders.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
          Orders
        </a>
        <a href="provider-profile.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
      </nav>
      <button class="sidebar-logout" onclick="window.location.href='provider-dashboard.php?logout=1'">Log out</button>
      <div class="sidebar-footer">
        <div class="sidebar-footer-social">
          <a href="#" class="sidebar-social-icon">in</a>
          <a href="#" class="sidebar-social-icon">&#120143;</a>
          <a href="#" class="sidebar-social-icon">&#9834;</a>
          
        </div>
        <div class="sidebar-footer-copy">
          <span>© 2026</span>
          <img src="../../images/Replate-white.png" alt="" style="height:40px;object-fit:contain;opacity:0.45;"/>
          <span>All rights reserved.</span>
        </div>
      </div>
    </aside>
    <main class="main">
    <div class="segmented">
     <a class="seg-btn <?= $tab==='sell'?'active':'' ?>" href="provider-items.php?tab=sell">Sell</a>
<a class="seg-btn <?= $tab==='donate'?'active':'' ?>" href="provider-items.php?tab=donate">Donate</a>
<a class="seg-btn <?= $tab==='all'?'active':'' ?>" href="provider-items.php?tab=all">All</a>

    </div>
    <div class="items-list">
<?php if (!$items): ?>
<div style="text-align:center;padding:32px 12px;color:#6d7da0;font-size:22px;">
No <?= htmlspecialchars($tab) ?> items yet.
</div>
<?php endif; ?>

<?php foreach ($items as $item): ?>
<?php
$categoryName = 'Unknown category';
if (!empty($item['categoryId'])) {
$cat = $categoryModel->findById((string)$item['categoryId']);
if ($cat && !empty($cat['name'])) {
$categoryName = $cat['name'];
}
}

$priceText = ($item['listingType'] ?? '') === 'donate'
? 'Free'
: number_format((float)($item['price'] ?? 0), 2) . ' SAR';
?>
<a class="order-row" href="item-details.php?id=<?= htmlspecialchars((string)$item['_id']) ?>">
<div class="order-left">
<div class="logo-box">
<?php if (!empty($item['photoUrl'])): ?>
<img src="<?= htmlspecialchars($item['photoUrl']) ?>" alt="<?= htmlspecialchars($item['itemName'] ?? 'Item') ?>">
<?php else: ?>
<span style="font-size:13px;color:#8ea0bf;">No Image</span>
<?php endif; ?>
</div>

<div class="order-info">
<h3><?= htmlspecialchars($item['itemName'] ?? 'Item') ?></h3>

<div class="info-line">
<span><?= htmlspecialchars($item['description'] ?? 'No description available.') ?></span>
</div>

<div class="info-line">
<span>Category: <?= htmlspecialchars($categoryName) ?></span>
</div>
</div>
</div>

<div class="order-right">
<div class="order-total">
<?= htmlspecialchars($priceText) ?>
</div>

<span class="view-item-btn">View Item</span>
</div>
</a>
<?php endforeach; ?>
</div>

  <div class="add-item-wrap">
<h1><span>Add</span> Item</h1>

<div class="add-item-card">
<div class="provider-preview-logo">
<?php if (!empty($providerLogo)): ?>
<img src="<?= htmlspecialchars($providerLogo) ?>" alt="<?= htmlspecialchars($providerName) ?>">
<?php else: ?>
<div style="font-size:34px;font-weight:700;color:#183482;">
<?= htmlspecialchars($providerName) ?>
</div>
<?php endif; ?>
</div>

<form class="add-item-form" method="POST" action="" enctype="multipart/form-data">

<div>
<div class="section-label">Select type</div>
<div class="type-row">
<label class="type-option">
<input type="radio" name="itemType" value="donate">
<span>Donate</span>
</label>

<label class="type-option">
<input type="radio" name="itemType" value="sell">
<span>Sell</span>
</label>
</div>
</div>

<div class="price-wrap" id="priceWrap">
<div class="section-label">Price</div>
<input class="form-input" type="number" name="price" id="priceInput" step="0.01" min="0" placeholder="Enter price">
<div class="field-error" id="priceError"></div>
</div>

<input class="form-input" type="text" name="itemName" id="itemName" placeholder="Item Name..." required>
<div class="field-error" id="itemNameError"></div>

<textarea class="form-textarea" name="itemDetails" id="itemDetails" placeholder="Item details ..." required></textarea>
<div class="field-error" id="itemDetailsError"></div>

<div>
<div class="icon-label">
<span class="icon"></span>
<span>Upload Item Photo</span>
</div>
<input class="form-input" type="file" name="itemPhoto" id="itemPhoto" accept="image/*" required>
<div class="field-error" id="itemPhotoError"></div>
<div>
<div class="icon-label">
<span class="icon"></span>
<span>Category</span>
</div>
<select class="form-select" name="categoryId" id="category" required>
<option value="">Select category</option>

<?php foreach ($categories as $cat): ?>
<option value="<?= htmlspecialchars((string)$cat['_id']) ?>">
<?= htmlspecialchars($cat['name']) ?>
</option>
<?php endforeach; ?>

</select>
<div class="field-error" id="categoryError"></div>
</div>


</div>

<div class="form-grid">
<div>
<div class="icon-label">
<span class="icon"></span>
<span>Expiry Date</span>
</div>
<input class="form-date" type="date" name="expiryDate" id="expiryDate" min="2026-01-01" required>
<div class="field-error" id="expiryDateError"></div>
</div>

<div>
<div class="icon-label">
<span class="icon"></span>
<span>Pickup Location</span>
</div>
<select class="form-select" name="pickupLocationId" id="pickupLocationId" required>
<option value="">Select pickup location</option>
<?php foreach ($locations as $location): ?>
<option value="<?= htmlspecialchars((string)$location['_id']) ?>">
<?= htmlspecialchars(
trim(
implode(' - ', array_filter([
$location['locationName'] ?? '',
$location['address'] ?? '',
$location['city'] ?? ''
]))
)
) ?>
</option>
<?php endforeach; ?>
</select>
<div class="field-error" id="pickupLocationIdError"></div>
</div>
</div>

<div>
<div class="icon-label">
<span class="icon"></span>
<span>Pickup time</span>
</div>

<div class="time-picker-row">
<input class="form-date" type="date" id="pickupDatePicker" min="2026-01-01">
<input class="form-time" type="time" id="pickupTimePicker" >
<button type="button" class="add-time-btn" id="addTimeBtn">Add time</button>
</div>

<div class="time-list" id="timeList"></div>
<div id="hiddenTimesWrap"></div>
</div>

<div class="submit-row">
<button class="add-btn" type="submit">Add</button>
</div>

</form>
</div>
</div>


     </main>
    <script>
const form = document.querySelector('.add-item-form');
const typeInputs = document.querySelectorAll('input[name="itemType"]');
const priceWrap = document.getElementById('priceWrap');
const priceInput = document.getElementById('priceInput');

function togglePriceField() {
const selected = document.querySelector('input[name="itemType"]:checked');

if (selected && selected.value === 'sell') {
priceWrap.style.display = 'block';
} else {
priceWrap.style.display = 'none';
if (priceInput) priceInput.value = '';
}
}

typeInputs.forEach(input => {
input.addEventListener('change', togglePriceField);
});

priceWrap.style.display = 'none';

const addTimeBtn = document.getElementById('addTimeBtn');
const pickupDatePicker = document.getElementById('pickupDatePicker');
const pickupTimePicker = document.getElementById('pickupTimePicker');
const timeList = document.getElementById('timeList');
const hiddenTimesWrap = document.getElementById('hiddenTimesWrap');

let pickupTimes = [];

function renderPickupTimes() {
timeList.innerHTML = '';
hiddenTimesWrap.innerHTML = '';

pickupTimes.forEach((entry, index) => {
const chip = document.createElement('div');
chip.className = 'time-chip';
chip.innerHTML = `
<span>${entry.date} - ${entry.time}</span>
<button type="button" data-index="${index}">&times;</button>
`;
timeList.appendChild(chip);

const hiddenInput = document.createElement('input');
hiddenInput.type = 'hidden';
hiddenInput.name = 'pickupTimes[]';
hiddenInput.value = JSON.stringify(entry);
hiddenTimesWrap.appendChild(hiddenInput);
});

document.querySelectorAll('.time-chip button').forEach(btn => {
btn.addEventListener('click', function () {
const index = Number(this.dataset.index);
pickupTimes.splice(index, 1);
renderPickupTimes();
});
});
}

addTimeBtn.addEventListener('click', function () {
const date = pickupDatePicker.value;
const time = pickupTimePicker.value;

if (!date || !time) {
alert('Please select both pickup date and pickup time.');
return;
}

if (date < '2026-01-01') {
alert('Pickup date must be in 2026 or later.');
return;
}

pickupTimes.push({ date, time });
renderPickupTimes();

pickupDatePicker.value = '';
pickupTimePicker.value = '';
});

form.addEventListener('submit', function(e) {
let valid = true;

if (pickupTimes.length === 0) {
alert('Please add at least one pickup date and time.');
valid = false;
}

const itemName = document.getElementById('itemName');
const itemDetails = document.getElementById('itemDetails');
const itemPhoto = document.getElementById('itemPhoto');
const expiryDate = document.getElementById('expiryDate');
const pickupLocationId = document.getElementById('pickupLocationId');
const category = document.getElementById('category');

if (!document.querySelector('input[name="itemType"]:checked')) {
valid = false;
alert('Please select item type.');
}

if (!itemName.value.trim()) valid = false;
if (!itemDetails.value.trim()) valid = false;
if (!itemPhoto.value) valid = false;
if (!expiryDate.value) valid = false;
if (!pickupLocationId.value) valid = false;
if (!category.value) valid = false;

const selected = document.querySelector('input[name="itemType"]:checked');
if (selected && selected.value === 'sell') {
if (!priceInput.value || Number(priceInput.value) <= 0) {
valid = false;
}
}

if (!valid) {
e.preventDefault();
}
});

</script>



</body>
</html>
