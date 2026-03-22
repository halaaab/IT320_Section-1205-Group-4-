<?php
session_start();

require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Category.php';
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
$itemId = $_GET['id'] ?? '';
$editMode = isset($_GET['edit']);

$providerModel = new Provider();
$itemModel = new Item();
$categoryModel = new Category();
$locationModel = new PickupLocation();

$provider = $providerModel->findById($providerId);
$item = $itemId ? $itemModel->findById($itemId) : null;

if (!$item) {
die('Item not found.');
}

$providerName = $provider['businessName'] ?? 'Provider';
$providerEmail = $provider['email'] ?? '';
$providerLogo = $provider['businessLogo'] ?? '';
$firstName = explode(' ', $providerName)[0];

$categoryName = 'Unknown category';
if (!empty($item['categoryId'])) {
$category = $categoryModel->findById((string)$item['categoryId']);
if ($category && !empty($category['name'])) {
$categoryName = $category['name'];
}
}

$locationName = 'No pickup location';
$locationAddress = '';
$locationCity = '';

if (!empty($item['pickupLocationId'])) {
$pickupLocation = $locationModel->findById((string)$item['pickupLocationId']);
if ($pickupLocation) {
$locationName = $pickupLocation['locationName'] ?? 'Pickup location';
$locationAddress = $pickupLocation['address'] ?? '';
$locationCity = $pickupLocation['city'] ?? '';
}
}

$itemName = $item['itemName'] ?? 'Item';
$itemDescription = $item['description'] ?? '';
$itemPhoto = $item['photoUrl'] ?? '';
$itemType = ucfirst($item['listingType'] ?? 'N/A');
$pickupDate = '';
if (!empty($item['pickupDate']) && $item['pickupDate'] instanceof MongoDB\BSON\UTCDateTime) {
$pickupDate = $item['pickupDate']->toDateTime()->format('Y-m-d');
}
$itemPrice = (($item['listingType'] ?? '') === 'donate')
? 'Donation'
: number_format((float)($item['price'] ?? 0), 2) . ' SAR';

$expiryDate = '';
if (!empty($item['expiryDate']) && $item['expiryDate'] instanceof MongoDB\BSON\UTCDateTime) {
$expiryDate = $item['expiryDate']->toDateTime()->format('Y-m-d');
}

$pickupTimes = $item['pickupTimes'] ?? [];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
$itemName = trim($_POST['itemName'] ?? '');
$itemDescription = trim($_POST['description'] ?? '');
$listingType = trim($_POST['listingType'] ?? '');
$price = trim($_POST['price'] ?? '');
$categoryId = trim($_POST['categoryId'] ?? '');
$pickupLocationId = trim($_POST['pickupLocationId'] ?? '');
$expiryDateInput = trim($_POST['expiryDate'] ?? '');
$pickupDateInput = trim($_POST['pickupDate'] ?? '');

$pickupTimes = $_POST['pickupTimes'] ?? [];
if (!is_array($pickupTimes)) {
$pickupTimes = [];
}
$pickupTimes = array_values(array_filter(array_map('trim', $pickupTimes)));

$itemName = ($itemName !== '') ? $itemName : ($item['itemName'] ?? '');
$itemDescription = ($itemDescription !== '') ? $itemDescription : ($item['description'] ?? '');
$listingType = ($listingType !== '') ? $listingType : ($item['listingType'] ?? 'donate');
$categoryId = ($categoryId !== '') ? $categoryId : (string)($item['categoryId'] ?? '');
$pickupLocationId = ($pickupLocationId !== '') ? $pickupLocationId : (string)($item['pickupLocationId'] ?? '');
$pickupTimes = !empty($pickupTimes) ? $pickupTimes : ($item['pickupTimes'] ?? []);
if ($listingType === 'sell' && ($price === '' || (float)$price <= 0)) {
$errors['price'] = 'Price is required when type is Sell.';
}
if ($expiryDateInput === '' && !empty($item['expiryDate'])) {
$expiryDateInput = $item['expiryDate']->toDateTime()->format('Y-m-d');
}

if ($pickupDateInput === '' && !empty($item['pickupDate'])) {
$pickupDateInput = $item['pickupDate']->toDateTime()->format('Y-m-d');
}

if ($listingType === 'donate') {
$price = 0;
} else {
$price = ($price !== '') ? (float)$price : (float)($item['price'] ?? 0);
}


$newPhotoPath = $item['photoUrl'] ?? '';
if (isset($_FILES['itemPhoto']) && $_FILES['itemPhoto']['error'] === UPLOAD_ERR_OK) {
$uploadDir = '../../uploads/items/';
if (!is_dir($uploadDir)) {
mkdir($uploadDir, 0777, true);
}

$ext = strtolower(pathinfo($_FILES['itemPhoto']['name'], PATHINFO_EXTENSION));
$allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

if (in_array($ext, $allowed)) {
$fileName = time() . '_' . basename($_FILES['itemPhoto']['name']);
$targetPath = $uploadDir . $fileName;

if (move_uploaded_file($_FILES['itemPhoto']['tmp_name'], $targetPath)) {
$newPhotoPath = '../../uploads/items/' . $fileName;
}
}
}

if (empty($errors)) {
$itemModel->updateById($itemId, [
'itemName' => $itemName,
'description' => $itemDescription,
'listingType' => $listingType,
'price' => ($listingType === 'donate') ? 0 : (float)$price,
'categoryId' => new MongoDB\BSON\ObjectId($categoryId),
'pickupLocationId' => new MongoDB\BSON\ObjectId($pickupLocationId),
'expiryDate' => new MongoDB\BSON\UTCDateTime(strtotime($expiryDateInput) * 1000),
'pickupDate' => new MongoDB\BSON\UTCDateTime(strtotime($pickupDateInput) * 1000),
'pickupTimes' => $pickupTimes,
'photoUrl' => $newPhotoPath,
]);

header('Location: provider-item-details.php?id=' . urlencode($itemId));
exit;
} else {
$editMode = true;
}
}

if (isset($_GET['delete']) && !empty($itemId)) {
$itemModel->deleteById($itemId);
header('Location: provider-items.php');
exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Edit Item</title>
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
    .sidebar-footer-copy { color: rgba(255,255,255,0.45); font-size: 10px; display: flex; align-items: center; justify-content: center; gap: 4px; flex-wrap: wrap; }
    .main{
flex: 1;
padding: 50px 30px;
display: flex;
justify-content: center;
align-items: flex-start;
}

.card-wrapper{
width: 100%;
max-width: 880px;
}

.item-card{
width: 100%;
background: #f7fbff;
border: 1px solid #d7e1ee;
border-radius: 24px;
padding: 30px 32px;
display: flex;
justify-content: space-between;
gap: 42px;
box-shadow: 0 8px 25px rgba(26,58,107,0.06);
}

.item-info{
flex: 1;
}

.item-info h2{
font-size: 22px;
color: #142c8e;
margin-bottom: 18px;
}

.item-info p{
font-size: 15px;
color: #243a5e;
margin-bottom: 10px;
line-height: 1.8;
}

.item-info strong{
color: #142c8e;
font-weight: 700;
}

.pickup-times-block{
margin-top: 8px;
}

.pickup-times-list{
display: flex;
flex-wrap: wrap;
gap: 10px;
margin-top: 10px;
}

.time-chip{
display: inline-block;
background: #ffffff;
border: 1px solid #d7e1ee;
color: #243a5e;
border-radius: 999px;
padding: 7px 12px;
font-size: 13px;
line-height: 1.4;
}

.edit-btn{
margin-top: 18px;
display: inline-block;
background: #e07a1a;
color: #fff;
padding: 10px 24px;
border-radius: 40px;
font-size: 14px;
text-decoration: none;
font-weight: 700;
transition: 0.2s;
}

.edit-btn:hover{
background: #c96a10;
}

.location-side{
width: 245px;
flex-shrink: 0;
}

.location-title{
font-size: 19px;
color: #142c8e;
margin-bottom: 14px;
text-align: center;
}

.location-card{
background: #eef5fb;
border: 1px solid #d7e1ee;
border-radius: 20px;
padding: 14px;
text-align: center;
}

.location-card img{
width: 100%;
height: 160px;
object-fit: cover;
border-radius: 16px;
margin-bottom: 10px;
}

.location-placeholder{
width: 100%;
height: 160px;
border-radius: 16px;
background: #dce7f3;
display: flex;
align-items: center;
justify-content: center;
color: #6f86a8;
font-size: 14px;
margin-bottom: 10px;
}

.location-card p{
font-size: 13px;
color: #4c638a;
line-height: 1.6;
margin-bottom: 4px;
}

@media (max-width: 900px){
.item-card{
flex-direction: column;
}

.location-side{
width: 100%;
}
}
.profile-edit-btn{ background: #e07a1a; color: #fff; border: none; border-radius: 50px; padding: 12px 28px; font-size: 16px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s, transform 0.15s; text-decoration: none; }

.profile-edit-btn:hover{ background: #c96a10; transform: translateY(-1px); }

.profile-info-grid{
display:grid;
grid-template-columns:1fr;
gap:18px;
}
.btn-save { background: #1a3a6b; color: #fff; border: none; border-radius: 50px; padding: 12px 28px; font-size: 16px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s, transform 0.15s; }
    .btn-save:hover { background: #2255a4; transform: translateY(-1px); }
    .btn-cancel { background: transparent; color: #8a9ab5; border: 2px solid #c8d8ee; border-radius: 50px; padding: 10px 22px; font-size: 15px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; transition: border-color 0.2s, color 0.2s; text-decoration: none; }
    .btn-cancel:hover { border-color: #8a9ab5; color: #4a6a9a; }
    .item-action-row{
display:flex;
justify-content:flex-end;
align-items:center;
gap:12px;
margin-top:20px;
flex-wrap:wrap;
padding-right:20px;
}

.profile-edit-btn{
background:#e07a1a;
color:#fff;
border:none;
border-radius:40px;
padding:10px 22px;
font-size:14px;
font-weight:700;
font-family:'Playfair Display', serif;
cursor:pointer;
display:inline-flex;
align-items:center;
justify-content:center;
gap:8px;
text-decoration:none;
transition:background 0.2s, transform 0.15s;
width:auto;
min-width:120px;
}

.profile-edit-btn:hover{
background:#c96a10;
transform:translateY(-1px);
}

.btn-save{
background:#1a3a6b;
color:#fff;
border:none;
border-radius:40px;
padding:10px 22px;
font-size:14px;
font-weight:700;
font-family:'Playfair Display', serif;
cursor:pointer;
display:inline-flex;
align-items:center;
justify-content:center;
gap:8px;
transition:background 0.2s, transform 0.15s;
width:auto;
min-width:120px;
}

.btn-save:hover{
background:#2255a4;
transform:translateY(-1px);
}

.btn-cancel{
background:transparent;
color:#8a9ab5;
border:2px solid #c8d8ee;
border-radius:40px;
padding:8px 20px;
font-size:14px;
font-weight:700;
font-family:'Playfair Display', serif;
cursor:pointer;
transition:border-color 0.2s, color 0.2s;
text-decoration:none;
display:inline-flex;
align-items:center;
justify-content:center;
width:auto;
min-width:110px;
}

.btn-cancel:hover{
border-color:#8a9ab5;
color:#4a6a9a;
}

.btn-delete{
background: transparent;
color: #e74c3c;
border: 2px solid #e74c3c;
border-radius: 40px;
padding: 8px 20px;
font-size: 14px;
font-weight: 700;
font-family: 'Playfair Display', serif;
cursor: pointer;
transition: all 0.2s;
text-decoration: none;
display: inline-flex;
align-items: center;
justify-content: center;
width: auto;
min-width: 110px;
}

.btn-delete:hover{
background: #e74c3c;
color: #fff;
}
.edit-input{
width:100%;
margin-top:6px;
padding:10px 14px;
border:1.5px solid #cfdbea;
border-radius:14px;
font-family:'Playfair Display', serif;
font-size:14px;
color:#243a5e;
background:#fff;
outline:none;
}

.edit-textarea{
width:100%;
margin-top:6px;
padding:12px 14px;
border:1.5px solid #cfdbea;
border-radius:14px;
font-family:'Playfair Display', serif;
font-size:14px;
color:#243a5e;
background:#fff;
outline:none;
min-height:100px;
resize:vertical;
}

.pickup-time-input{
margin-bottom:8px;
}

.btn-delete{
background: transparent !important;
color: #e74c3c !important;
border: 2px solid #e74c3c !important;
border-radius: 40px;
padding: 8px 20px;
font-size: 14px;
font-weight: 700;
font-family: 'Playfair Display', serif;
cursor: pointer;
transition: all 0.2s;
text-decoration: none;
display: inline-flex;
align-items: center;
justify-content: center;
min-width: 110px;
}

.btn-delete:hover{
background: #e74c3c !important;
color: #fff !important;
}
.small-time-select{
width:110px;
height:42px;
border:1.5px solid #cfdbea;
border-radius:999px;
padding:0 14px;
font-family:'Playfair Display', serif;
font-size:15px;
color:#183482;
background:#fff;
outline:none;
cursor:pointer;
}

.time-picker-row{
display:flex;
gap:10px;
align-items:center;
flex-wrap:wrap;
margin-top:12px;
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
.field-error{
color:#d64545;
font-size:13px;
margin-top:6px;
display:none;
}

.field-error.show{
display:block;
}

.edit-input.error,
.edit-textarea.error{
border:1.5px solid #d64545 !important;
}

    </style>
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
   <form method="POST" action="provider-item-details.php?id=<?= htmlspecialchars((string)$item['_id']) ?>" id="itemEditForm" enctype="multipart/form-data">
    <div class="card-wrapper">

<div class="item-card">
<div class="item-info">

<h2>Item</h2>

<?php if ($editMode): ?>

<p>
<strong>Name:</strong><br>
<input type="text" name="itemName" class="edit-input" value="<?= htmlspecialchars($_POST['itemName'] ?? $itemName) ?>">
<?php if (isset($errors['itemName'])): ?><span class="field-error show"><?= htmlspecialchars($errors['itemName']) ?></span><?php endif; ?>
</p>

<p>
<strong>Description:</strong><br>
<textarea name="description" class="edit-textarea"><?= htmlspecialchars($_POST['description'] ?? $itemDescription) ?></textarea>
<?php if (isset($errors['description'])): ?><span class="field-error show"><?= htmlspecialchars($errors['description']) ?></span><?php endif; ?>
</p>

<p>
<strong>Type:</strong><br>
<select name="listingType" class="edit-input">
<option value="donate" <?= (($_POST['listingType'] ?? $item['listingType']) === 'donate') ? 'selected' : '' ?>>Donate</option>
<option value="sell" <?= (($_POST['listingType'] ?? $item['listingType']) === 'sell') ? 'selected' : '' ?>>Sell</option>
</select>
<?php if (isset($errors['listingType'])): ?><span class="field-error show"><?= htmlspecialchars($errors['listingType']) ?></span><?php endif; ?>
</p>

<p>
<strong>Price:</strong><br>
<input type="number" step="0.01" name="price" class="edit-input" value="<?= htmlspecialchars((string)($_POST['price'] ?? $item['price'] ?? 0)) ?>">
<?php if (isset($errors['price'])): ?><span class="field-error show"><?= htmlspecialchars($errors['price']) ?></span><?php endif; ?>
</p>

<p>
<strong>Category:</strong><br>
<select name="categoryId" class="edit-input">
<option value="">Select category</option>
<?php foreach ($categoryModel->getAll() as $cat): ?>
<option value="<?= htmlspecialchars((string)$cat['_id']) ?>"
<?= ((string)($_POST['categoryId'] ?? (string)$item['categoryId']) === (string)$cat['_id']) ? 'selected' : '' ?>>
<?= htmlspecialchars($cat['name']) ?>
</option>
<?php endforeach; ?>
</select>
<?php if (isset($errors['categoryId'])): ?><span class="field-error show"><?= htmlspecialchars($errors['categoryId']) ?></span><?php endif; ?>
</p>

<p>
<strong>Expiry date:</strong><br>
<input
type="date"
name="expiryDate"
min="2026-01-01"
class="edit-input <?= isset($errors['expiryDate']) ? 'error' : '' ?>"
value="<?= htmlspecialchars(
(isset($_POST['expiryDate']))
? $_POST['expiryDate']
: (($expiryDate >= '2026-01-01') ? $expiryDate : '')
) ?>"
>
<?php if (isset($errors['expiryDate'])): ?>
<span class="field-error show"><?= htmlspecialchars($errors['expiryDate']) ?></span>
<?php endif; ?>
</p>


<p>
<strong>Pickup date:</strong><br>
<input
type="date"
name="pickupDate"
min="2026-01-01"
class="edit-input <?= isset($errors['pickupDate']) ? 'error' : '' ?>"
value="<?= htmlspecialchars(
(isset($_POST['pickupDate']))
? $_POST['pickupDate']
: (($pickupDate >= '2026-01-01') ? $pickupDate : '')
) ?>"
>
<?php if (isset($errors['pickupDate'])): ?>
<span class="field-error show"><?= htmlspecialchars($errors['pickupDate']) ?></span>
<?php endif; ?>
</p>


<p>
<strong>Pickup location:</strong><br>
<select name="pickupLocationId" class="edit-input">
<option value="">Select pickup location</option>
<?php foreach ($locationModel->getByProvider($providerId) as $loc): ?>
<option value="<?= htmlspecialchars((string)$loc['_id']) ?>"
<?= ((string)($_POST['pickupLocationId'] ?? (string)$item['pickupLocationId']) === (string)$loc['_id']) ? 'selected' : '' ?>>
<?= htmlspecialchars(trim(implode(' - ', array_filter([
$loc['locationName'] ?? '',
$loc['address'] ?? '',
$loc['city'] ?? ''
])))) ?>
</option>
<?php endforeach; ?>
</select>
<?php if (isset($errors['pickupLocationId'])): ?><span class="field-error show"><?= htmlspecialchars($errors['pickupLocationId']) ?></span><?php endif; ?>
</p>

<p>
<strong>Photo:</strong><br>
<input type="file" name="itemPhoto" class="edit-input" accept="image/*">
</p>

<div class="pickup-times-block">
<p><strong>Pickup times:</strong></p>

<div id="pickupTimesContainer" class="pickup-times-list">
<?php
$editablePickupTimes = $_POST['pickupTimes'] ?? $pickupTimes;
if (!empty($editablePickupTimes)):
foreach ($editablePickupTimes as $time):
?>
<span class="time-chip"><?= htmlspecialchars($time) ?></span>
<input type="hidden" name="pickupTimes[]" value="<?= htmlspecialchars($time) ?>">
<?php
endforeach;
endif;
?>
</div>

<div class="time-picker-row">
<select id="editHour" class="small-time-select">
<option value="">Hour</option>
<?php for ($h = 1; $h <= 12; $h++): ?>
<option value="<?= $h ?>"><?= $h ?></option>
<?php endfor; ?>
</select>

<select id="editMinute" class="small-time-select">
<option value="">Min</option>
<option value="00">00</option>
<option value="15">15</option>
<option value="30">30</option>
<option value="45">45</option>
</select>

<select id="editAmPm" class="small-time-select">
<option value="AM">AM</option>
<option value="PM">PM</option>
</select>

<button type="button" class="add-time-btn" onclick="addPickupTimeEdit()">+ Add</button>
</div>

<?php if (isset($errors['pickupTimes'])): ?>
<span class="field-error show"><?= htmlspecialchars($errors['pickupTimes']) ?></span>
<?php endif; ?>
</div>

<?php else: ?>

<p><strong>Name:</strong> <?= htmlspecialchars($itemName) ?></p>
<p><strong>Description:</strong> <?= htmlspecialchars($itemDescription) ?></p>
<p><strong>Type:</strong> <?= htmlspecialchars($itemType) ?></p>
<p><strong>Price:</strong> <?= htmlspecialchars($itemPrice) ?></p>
<p><strong>Category:</strong> <?= htmlspecialchars($categoryName) ?></p>
<p><strong>Expiry date:</strong> <?= htmlspecialchars($expiryDate) ?></p>
<p><strong>Pickup date:</strong> <?= htmlspecialchars($pickupDate) ?></p>

<div class="pickup-times-block">
<p><strong>Pickup times:</strong></p>

<?php if (!empty($pickupTimes)): ?>
<div class="pickup-times-list">
<?php foreach ($pickupTimes as $time): ?>
<?php
$displayTime = '';
if (is_string($time)) {
$decoded = json_decode($time, true);
if (is_array($decoded) && isset($decoded['date'], $decoded['time'])) {
$displayTime = $decoded['date'] . ' - ' . $decoded['time'];
} else {
$displayTime = $time;
}
} elseif (is_array($time) && isset($time['date'], $time['time'])) {
$displayTime = $time['date'] . ' - ' . $time['time'];
}
?>
<span class="time-chip"><?= htmlspecialchars($displayTime) ?></span>
<?php endforeach; ?>
</div>
<?php else: ?>
<p>No pickup times added.</p>
<?php endif; ?>
</div>

<?php endif; ?>


<?php if ($editMode): ?>
<div class="item-action-row">

<a href="provider-item-details.php?id=<?= htmlspecialchars((string)$item['_id']) ?>" class="btn-cancel">
Cancel
</a>

<button type="submit" form="itemEditForm" class="btn-save">
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
<path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/>
<polyline points="17 21 17 13 7 13 7 21"/>
<polyline points="7 3 7 8 15 8"/>
</svg>
Save
</button>


<a href="provider-item-details.php?id=<?= htmlspecialchars((string)$item['_id']) ?>&delete=1"
class="btn-delete"
onclick="return confirm('Are you sure you want to delete this item?');">
Delete
</a>

</div>
<?php else: ?>
<div class="item-action-row">
<a class="profile-edit-btn" href="provider-item-details.php?id=<?= htmlspecialchars((string)$item['_id']) ?>&edit=1">
<svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
<path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/>
<path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/>
</svg>
Edit
</a>

</div>
<?php endif; ?>


</div>

<div class="location-side">

<h3 class="location-title">Pick up location</h3>

<div class="location-card">

<?php if (!empty($itemPhoto)): ?>
<img src="<?= htmlspecialchars($itemPhoto) ?>" alt="<?= htmlspecialchars($itemName) ?>">
<?php else: ?>
<div class="location-placeholder">No image</div>
<?php endif; ?>

<p><?= htmlspecialchars($locationName) ?></p>
<p><?= htmlspecialchars(trim($locationAddress . ', ' . $locationCity, ', ')) ?></p>

</div>

</div>

</div>

</div>
<script>
function addPickupTimeEdit() {
const hour = document.getElementById('editHour').value;
const minute = document.getElementById('editMinute').value;
const ampm = document.getElementById('editAmPm').value;

if (!hour || !minute) {
alert('Please choose hour and minute.');
return;
}

const value = `${hour}:${minute} ${ampm}`;
const container = document.getElementById('pickupTimesContainer');

const chip = document.createElement('span');
chip.className = 'time-chip';
chip.textContent = value;

const hidden = document.createElement('input');
hidden.type = 'hidden';
hidden.name = 'pickupTimes[]';
hidden.value = value;

container.appendChild(chip);
container.appendChild(hidden);

document.getElementById('editHour').value = '';
document.getElementById('editMinute').value = '';
document.getElementById('editAmPm').value = 'AM';
}
</script>

</body>
</html>