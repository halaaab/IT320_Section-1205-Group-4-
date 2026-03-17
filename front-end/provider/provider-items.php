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
$uploadDir = '../../uploads/items/';
if (!is_dir($uploadDir)) {
mkdir($uploadDir, 0777, true);
}

$fileName = time() . '_' . basename($_FILES['itemPhoto']['name']);
$targetPath = $uploadDir . $fileName;

if (move_uploaded_file($_FILES['itemPhoto']['tmp_name'], $targetPath)) {
$photoUrl = '../../uploads/items/' . $fileName;
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

.slot-btn {
padding: 8px 16px;
border-radius: 999px;
border: 1.8px solid #cfdbea;
background: #fff;
color: #183482;
font-size: 14px;
font-family: 'Playfair Display', serif;
cursor: pointer;
transition: background .2s, color .2s, border-color .2s;
}
.slot-btn:hover { background: #fff8f2; border-color: #ea8b2c; }
.slot-btn.selected { background: #ea8b2c; color: #fff; border-color: #ea8b2c; }

.add-item-open-btn {
background: #f6811f;
color: #fff;
border: none;
border-radius: 999px;
padding: 12px 28px;
font-size: 17px;
font-family: 'Playfair Display', serif;
font-weight: 700;
cursor: pointer;
transition: background .2s;
}
.add-item-open-btn:hover { background: #df7413; }

.type-card {
display: flex;
flex-direction: column;
align-items: center;
justify-content: center;
padding: 20px 16px;
border: 2px solid #cfdbea;
border-radius: 18px;
background: #fff;
cursor: pointer;
transition: border-color .2s, background .2s, box-shadow .2s;
text-align: center;
}
.type-card:hover { border-color: #ea8b2c; background: #fff8f2; }
.type-card-active { border-color: #ea8b2c !important; background: #fff4e6 !important; box-shadow: 0 0 0 3px rgba(234,139,44,0.15); }

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
          <span>©️ 2026</span>
          <img src="../../images/Replate-white.png" alt="" style="height:40px;object-fit:contain;opacity:0.45;"/>
          <span>All rights reserved.</span>
        </div>
      </div>
    </aside>
    <main class="main">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-top:32px;margin-bottom:28px;margin-left:8px;">
      <div style="display:flex;gap:16px;">
        <a class="seg-btn <?= $tab==='sell'?'active':'' ?>" href="provider-items.php?tab=sell">Sell</a>
        <a class="seg-btn <?= $tab==='donate'?'active':'' ?>" href="provider-items.php?tab=donate">Donate</a>
        <a class="seg-btn <?= $tab==='all'?'active':'' ?>" href="provider-items.php?tab=all">All</a>
      </div>
      <button class="add-item-open-btn" onclick="document.getElementById('addItemModal').style.display='flex'">+ Add Item</button>
    </div>
    <div class="items-list">
<?php if (empty($items)): ?>
<div style="text-align:center;padding:32px 12px;color:#6d7da0;font-size:22px;">
No <?= htmlspecialchars($tab) ?> items yet.
</div>
<?php else: ?>
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
<?php endif; ?>
</div>

  <!-- ADD ITEM MODAL -->
  <div id="addItemModal" style="display:none;position:fixed;inset:0;background:rgba(12,22,45,0.45);z-index:9999;justify-content:center;align-items:center;padding:20px;" onclick="if(event.target===this)this.style.display='none'">
    <div style="background:#f7fbff;border-radius:26px;border:1.5px solid #cfdbea;padding:36px 32px;max-width:760px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(26,58,107,0.18);position:relative;">

      <button onclick="document.getElementById('addItemModal').style.display='none'" style="position:absolute;top:16px;right:20px;background:none;border:none;font-size:26px;color:#8aa3c0;cursor:pointer;line-height:1;">&times;</button>

      <h1 style="font-size:36px;font-weight:700;font-family:'Playfair Display',serif;background:linear-gradient(90deg,#143496 0%,#66a1d9 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;text-align:center;margin-bottom:28px;">Add Item</h1>

      <form class="add-item-form" id="addItemForm" method="POST" action="" enctype="multipart/form-data">

        <!-- Card-style type selector -->
        <div>
          <div class="section-label" style="margin-bottom:12px;">Select type <span style="color:#d64545;">*</span></div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <label class="type-card" id="typeCardDonate" onclick="selectTypeCard('donate')">
              <input type="radio" name="itemType" value="donate" style="display:none;">
              <div style="font-size:28px;margin-bottom:6px;">🤝</div>
              <div style="font-size:17px;font-weight:700;color:#183482;">Donate</div>
              <div style="font-size:12px;color:#7a8fa8;margin-top:4px;">Give it for free</div>
            </label>
            <label class="type-card" id="typeCardSell" onclick="selectTypeCard('sell')">
              <input type="radio" name="itemType" value="sell" style="display:none;">
              <div style="font-size:28px;margin-bottom:6px;">🏷️</div>
              <div style="font-size:17px;font-weight:700;color:#183482;">Sell</div>
              <div style="font-size:12px;color:#7a8fa8;margin-top:4px;">Set a price</div>
            </label>
          </div>
          <div class="field-error" id="itemTypeError">Please select a type.</div>
        </div>

        <div class="price-wrap" id="priceWrap">
          <div class="section-label">Price <span style="color:#d64545;">*</span></div>
          <input class="form-input" type="number" name="price" id="priceInput" step="0.01" min="0" placeholder="Enter price">
          <div class="field-error" id="priceError">Please enter a valid price.</div>
        </div>

        <div>
          <input class="form-input" type="text" name="itemName" id="itemName" placeholder="Item Name...">
          <div class="field-error" id="itemNameError">Item name is required.</div>
        </div>

        <div>
          <textarea class="form-textarea" name="itemDetails" id="itemDetails" placeholder="Item details ..."></textarea>
          <div class="field-error" id="itemDetailsError">Item details are required.</div>
        </div>

        <div>
          <div class="icon-label"><span>Upload Item Photo <span style="color:#d64545;">*</span></span></div>
          <input class="form-input" type="file" name="itemPhoto" id="itemPhoto" accept="image/*">
          <div class="field-error" id="itemPhotoError">Please upload a photo.</div>
        </div>

        <div>
          <div class="icon-label"><span>Category <span style="color:#d64545;">*</span></span></div>
          <select class="form-select" name="categoryId" id="category">
            <option value="">Select category</option>
            <?php foreach ($categories as $cat): ?>
            <option value="<?= htmlspecialchars((string)$cat['_id']) ?>"><?= htmlspecialchars($cat['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="field-error" id="categoryError">Please select a category.</div>
        </div>

        <div class="form-grid">
          <div>
            <div class="icon-label"><span>Expiry Date <span style="color:#d64545;">*</span></span></div>
            <input class="form-date" type="date" name="expiryDate" id="expiryDate" min="2026-01-01">
            <div class="field-error" id="expiryDateError">Expiry date is required.</div>
          </div>
          <div>
            <div class="icon-label"><span>Pickup Location <span style="color:#d64545;">*</span></span></div>
            <select class="form-select" name="pickupLocationId" id="pickupLocationId">
              <option value="">Select pickup location</option>
              <?php foreach ($locations as $location): ?>
              <option value="<?= htmlspecialchars((string)$location['_id']) ?>">
                <?= htmlspecialchars(trim(implode(' - ', array_filter([$location['locationName'] ?? '', $location['address'] ?? '', $location['city'] ?? ''])))) ?>
              </option>
              <?php endforeach; ?>
            </select>
            <div class="field-error" id="pickupLocationIdError">Please select a pickup location.</div>
          </div>
        </div>

        <div>
          <div class="icon-label"><span>Pickup Times <span style="color:#d64545;">*</span></span></div>
          <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
            <?php
            $slots = ['8:00 AM','9:00 AM','10:00 AM','11:00 AM','12:00 PM','1:00 PM','2:00 PM','3:00 PM','4:00 PM','5:00 PM','6:00 PM','7:00 PM','8:00 PM'];
            foreach ($slots as $slot): ?>
            <button type="button" class="slot-btn" onclick="toggleSlot('<?= $slot ?>', this)"><?= $slot ?></button>
            <?php endforeach; ?>
          </div>
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px;">
            <select id="customHour" style="width:110px;height:42px;border:1.5px solid #cfdbea;border-radius:999px;padding:0 14px;font-family:'Playfair Display',serif;font-size:15px;color:#183482;background:#fff;outline:none;cursor:pointer;">
              <option value="">Hour</option>
              <?php for($h=1;$h<=12;$h++): ?><option value="<?=$h?>"><?=$h?></option><?php endfor; ?>
            </select>
            <select id="customMinute" style="width:110px;height:42px;border:1.5px solid #cfdbea;border-radius:999px;padding:0 14px;font-family:'Playfair Display',serif;font-size:15px;color:#183482;background:#fff;outline:none;cursor:pointer;">
              <option value="">Min</option>
              <option value="00">00</option><option value="15">15</option><option value="30">30</option><option value="45">45</option>
            </select>
            <select id="customAmPm" style="width:90px;height:42px;border:1.5px solid #cfdbea;border-radius:999px;padding:0 14px;font-family:'Playfair Display',serif;font-size:15px;color:#183482;background:#fff;outline:none;cursor:pointer;">
              <option value="AM">AM</option><option value="PM">PM</option>
            </select>
            <button type="button" class="add-time-btn" onclick="addCustomTime()">+ Add</button>
          </div>
          <div class="field-error" id="pickupTimesError">Please add at least one pickup time.</div>
          <div class="time-list" id="timeList" style="margin-top:14px;"></div>
          <div id="hiddenTimesWrap"></div>
        </div>

        <div class="submit-row">
          <button class="add-btn" type="submit">Add Item</button>
        </div>

      </form>
    </div>
  </div>

     </main>
    <script>
const form = document.getElementById('addItemForm');
const priceWrap = document.getElementById('priceWrap');
const priceInput = document.getElementById('priceInput');
const timeList = document.getElementById('timeList');
const hiddenTimesWrap = document.getElementById('hiddenTimesWrap');
let pickupTimes = [];

// ── Card type selector ──
function selectTypeCard(type) {
  document.querySelector('input[name="itemType"][value="'+type+'"]').checked = true;
  document.getElementById('typeCardDonate').classList.toggle('type-card-active', type === 'donate');
  document.getElementById('typeCardSell').classList.toggle('type-card-active', type === 'sell');
  priceWrap.style.display = (type === 'sell') ? 'block' : 'none';
  if (type !== 'sell' && priceInput) priceInput.value = '';
  hideError('itemTypeError');
}
priceWrap.style.display = 'none';

// ── Time rendering ──
function renderPickupTimes() {
  timeList.innerHTML = '';
  hiddenTimesWrap.innerHTML = '';
  pickupTimes.forEach((t, index) => {
    const chip = document.createElement('div');
    chip.className = 'time-chip';
    chip.innerHTML = `<span>${t}</span><button type="button" data-index="${index}">&times;</button>`;
    timeList.appendChild(chip);
    const hidden = document.createElement('input');
    hidden.type = 'hidden'; hidden.name = 'pickupTimes[]'; hidden.value = t;
    hiddenTimesWrap.appendChild(hidden);
  });
  document.querySelectorAll('.time-chip button').forEach(btn => {
    btn.addEventListener('click', function() {
      const t = pickupTimes[Number(this.dataset.index)];
      pickupTimes.splice(Number(this.dataset.index), 1);
      document.querySelectorAll('.slot-btn').forEach(b => { if (b.textContent === t) b.classList.remove('selected'); });
      renderPickupTimes();
    });
  });
}

function toggleSlot(time, btn) {
  if (btn.classList.contains('selected')) {
    btn.classList.remove('selected');
    pickupTimes = pickupTimes.filter(t => t !== time);
  } else {
    btn.classList.add('selected');
    if (!pickupTimes.includes(time)) pickupTimes.push(time);
  }
  renderPickupTimes();
}

function addCustomTime() {
  const hour = document.getElementById('customHour').value;
  const minute = document.getElementById('customMinute').value;
  const ampm = document.getElementById('customAmPm').value;
  if (!hour || !minute) { showError('pickupTimesError'); return; }
  const label = `${hour}:${minute} ${ampm}`;
  if (!pickupTimes.includes(label)) { pickupTimes.push(label); renderPickupTimes(); }
  document.getElementById('customHour').value = '';
  document.getElementById('customMinute').value = '';
}

// ── Error helpers ──
function showError(id) { document.getElementById(id)?.classList.add('show'); }
function hideError(id) { document.getElementById(id)?.classList.remove('show'); }
function markField(el, hasError) { el?.classList.toggle('error', hasError); }

// ── Validation ──
form.addEventListener('submit', function(e) {
  let valid = true;

  const typeChecked = document.querySelector('input[name="itemType"]:checked');
  if (!typeChecked) { showError('itemTypeError'); valid = false; } else { hideError('itemTypeError'); }

  if (typeChecked && typeChecked.value === 'sell') {
    if (!priceInput.value || Number(priceInput.value) <= 0) { showError('priceError'); markField(priceInput, true); valid = false; }
    else { hideError('priceError'); markField(priceInput, false); }
  }

  const itemName = document.getElementById('itemName');
  if (!itemName.value.trim()) { showError('itemNameError'); markField(itemName, true); valid = false; }
  else { hideError('itemNameError'); markField(itemName, false); }

  const itemDetails = document.getElementById('itemDetails');
  if (!itemDetails.value.trim()) { showError('itemDetailsError'); markField(itemDetails, true); valid = false; }
  else { hideError('itemDetailsError'); markField(itemDetails, false); }

  const itemPhoto = document.getElementById('itemPhoto');
  if (!itemPhoto.value) { showError('itemPhotoError'); markField(itemPhoto, true); valid = false; }
  else { hideError('itemPhotoError'); markField(itemPhoto, false); }

  const category = document.getElementById('category');
  if (!category.value) { showError('categoryError'); markField(category, true); valid = false; }
  else { hideError('categoryError'); markField(category, false); }

  const expiryDate = document.getElementById('expiryDate');
  if (!expiryDate.value) { showError('expiryDateError'); markField(expiryDate, true); valid = false; }
  else { hideError('expiryDateError'); markField(expiryDate, false); }

  const pickupLocationId = document.getElementById('pickupLocationId');
  if (!pickupLocationId.value) { showError('pickupLocationIdError'); markField(pickupLocationId, true); valid = false; }
  else { hideError('pickupLocationIdError'); markField(pickupLocationId, false); }

  if (pickupTimes.length === 0) { showError('pickupTimesError'); valid = false; }
  else { hideError('pickupTimesError'); }

  if (!valid) e.preventDefault();
});
</script>

</body>
</html>