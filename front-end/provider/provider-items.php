<?php
 
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$tab = $_GET['tab'] ?? 'all';

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
$categoryModel->seed();
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

usort($items, function($a, $b) {
    return strcmp((string)$b['_id'], (string)$a['_id']);
});

$formError = '';
// ── Handle Quick Update (AJAX JSON POST) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
    header('Content-Type: application/json');

    $input = json_decode(file_get_contents('php://input'), true);
    $itemId = trim($input['id'] ?? '');

    if (!$itemId) {
        echo json_encode(['success' => false, 'message' => 'Missing item ID']);
        exit;
    }

    try {
        $fields = ['updatedAt' => new MongoDB\BSON\UTCDateTime()];

        $qty = $input['quantity'] ?? null;
        if ($qty !== null && $qty !== '') {
            $fields['quantity'] = (int)$qty;
            $fields['isAvailable'] = ((int)$qty > 0);
        }

        $listingType = trim($input['listingType'] ?? '');
        if ($listingType === 'donate') {
            $fields['listingType'] = 'donate';
            $fields['price'] = 0;
        } elseif ($listingType === 'sell') {
            $fields['listingType'] = 'sell';
            if (!empty($input['price'])) {
                $fields['price'] = (float)$input['price'];
            }
        }

        if (!empty($input['expiryDate'])) {
            $fields['expiryDate'] = new MongoDB\BSON\UTCDateTime(strtotime($input['expiryDate']) * 1000);
        }

        if (!empty($input['pickupDate'])) {
            $fields['pickupDate'] = new MongoDB\BSON\UTCDateTime(strtotime($input['pickupDate']) * 1000);
        }

        if (!empty($input['pickupLocationId'])) {
            $fields['pickupLocationId'] = new MongoDB\BSON\ObjectId($input['pickupLocationId']);
        }

        $itemModel->updateById($itemId, $fields);
        echo json_encode(['success' => true]);

    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['formAction'] ?? 'addItem';

    // AJAX delete
   if ($formAction === 'deleteItem') {
    header('Content-Type: application/json');

    try {
        $itemId = $_POST['itemId'] ?? '';

        if ($itemId === '') {
            throw new Exception('Missing item id');
        }

        $itemModel->deleteById($itemId);

        echo json_encode([
            'success' => true
        ]);
    } catch (Throwable $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    exit;
}
    // Add / Edit form
    $photoUrl = trim($_POST['existingPhotoUrl'] ?? '');

    if (!empty($_FILES['itemPhoto']['name']) && $_FILES['itemPhoto']['error'] === 0) {
        $uploadDir = '../../uploads/items/';

        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $originalName = basename($_FILES['itemPhoto']['name']);
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        if (!in_array($extension, $allowed, true)) {
            throw new Exception('Invalid image type. Please upload jpg, jpeg, png, gif, or webp.');
        }

        $newFileName = uniqid('item_', true) . '.' . $extension;
        $targetPath = $uploadDir . $newFileName;

        if (!move_uploaded_file($_FILES['itemPhoto']['tmp_name'], $targetPath)) {
            throw new Exception('Failed to upload image.');
        }

        $photoUrl = '../../uploads/items/' . $newFileName;
    }

    try {
        if ($formAction === 'editItem') {
            $editItemId = $_POST['editItemId'] ?? '';
            if ($editItemId === '') {
                throw new Exception('Missing edit item id');
            }

            $data = [
                'categoryId'  => $_POST['categoryId'] ?? '',
                'itemName'    => trim($_POST['itemName'] ?? ''),
                'description' => trim($_POST['itemDetails'] ?? ''),
                'photoUrl'    => $photoUrl,
                'listingType' => $_POST['itemType'] ?? '',
                'price'       => (($_POST['itemType'] ?? '') === 'sell') ? (float)($_POST['price'] ?? 0) : 0,
            ];

            $itemModel->updateById($editItemId, $data);
        } else {
            $data = [
                'categoryId'       => $_POST['categoryId'] ?? '',
                'pickupLocationId' => $_POST['pickupLocationId'] ?? '',
                'itemName'         => trim($_POST['itemName'] ?? ''),
                'description'      => trim($_POST['itemDetails'] ?? ''),
                'photoUrl'         => $photoUrl,
                'listingType'      => $_POST['itemType'] ?? '',
                'price'            => (($_POST['itemType'] ?? '') === 'sell') ? (float)($_POST['price'] ?? 0) : 0,
                'pickupDate'       => $_POST['pickupDate'] ?? '',
                'pickupTimes'      => $_POST['pickupTimes'] ?? [],
            ];

            $itemModel->create($providerId, $data);
        }

        header('Location: provider-items.php?tab=' . urlencode($_POST['itemType'] ?? 'all'));
        exit;
    } catch (Exception $e) {
        $formError = $e->getMessage();
    }
}

$provider = $providerModel->findById($providerId);
$locations = $locationModel->getByProvider($providerId);
$defaultLocation = null;

foreach ($locations as $loc) {
    if (!empty($loc['isMain']) || !empty($loc['isDefault'])) {
        $defaultLocation = $loc;
        break;
    }
}

if (!$defaultLocation && !empty($locations)) {
    $defaultLocation = $locations[0];
}

$providerName = $provider['businessName'] ?? 'Provider';
$providerEmail = $provider['email'] ?? '';
$providerPhone = $provider['phoneNumber'] ?? '';
$providerLogo = $provider['businessLogo'] ?? '';

$firstName = explode(' ', $providerName)[0];

$today = date('Y-m-d');
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<style>

*{
  box-sizing:border-box;
  margin:0;
  padding:0;
}

body{
  font-family:'Playfair Display', serif;
  background:#f4f7fc;
  min-height:100vh;
  display:flex;
  flex-direction:column;
  color:#183482;
}

/* ===== NAVBAR ===== */
nav.navbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:0 40px;
  height:72px;
  background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);
  position:sticky;
  top:0;
  z-index:100;
  box-shadow:0 2px 16px rgba(26,58,107,0.18);
}

.nav-left{
  display:flex;
  align-items:center;
}

.nav-logo{
  height:90px;
}

.nav-right{
  display:flex;
  align-items:center;
  gap:14px;
}

.nav-search-wrap{
  position:relative;
}

.nav-search-wrap svg{
  position:absolute;
  left:14px;
  top:50%;
  transform:translateY(-50%);
  opacity:.65;
  pointer-events:none;
}

.nav-search-wrap input{
  width:260px;
  height:40px;
  border-radius:999px;
  border:1.5px solid rgba(255,255,255,0.35);
  background:rgba(255,255,255,0.12);
  color:#fff;
  padding:0 16px 0 40px;
  outline:none;
  font-family:'Playfair Display', serif;
  font-size:14px;
}

.nav-search-wrap input::placeholder{
  color:rgba(255,255,255,0.65);
}

.nav-provider-info{
  display:flex;
  align-items:center;
  gap:12px;
}

.nav-provider-logo{
  width:46px;
  height:46px;
  border-radius:50%;
  border:2px solid rgba(255,255,255,0.55);
  background:rgba(255,255,255,0.15);
  display:flex;
  align-items:center;
  justify-content:center;
  overflow:hidden;
  color:#fff;
  font-weight:700;
  flex-shrink:0;
}

.nav-provider-logo img{
  width:100%;
  height:100%;
  object-fit:cover;
}

.nav-provider-text{
  display:flex;
  flex-direction:column;
}

.nav-provider-name{
  font-size:15px;
  font-weight:700;
  color:#fff;
}

.nav-provider-email{
  font-size:12px;
  color:rgba(255,255,255,0.75);
}

/* ===== LAYOUT ===== */
.page-body{
  display:flex;
  flex:1;
}

.main{
  flex:1;
  padding:36px 40px;
  overflow-y:auto;
}

/* ===== SIDEBAR ===== */
.sidebar{
  width:240px;
  min-height:calc(100vh - 72px);
  background:linear-gradient(180deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);
  display:flex;
  flex-direction:column;
  padding:34px 24px 28px;
  flex-shrink:0;
}

.sidebar-welcome{
  color:rgba(255,255,255,0.78);
  font-size:17px;
  margin-bottom:4px;
}

.sidebar-name{
  color:rgba(255,255,255,0.62);
  font-size:38px;
  font-weight:700;
  line-height:1.1;
  margin-bottom:34px;
}

.sidebar-nav{
  display:flex;
  flex-direction:column;
  gap:16px;
  flex:1;
}

.sidebar-link{
  display:flex;
  align-items:center;
  gap:10px;
  color:rgba(255,255,255,0.78);
  text-decoration:none;
  font-size:16px;
  padding:10px 8px;
  transition:.2s;
}

.sidebar-link:hover{
  color:#fff;
}

.sidebar-link.active{
  color:#fff;
  font-weight:700;
  border-bottom:2px solid rgba(255,255,255,0.55);
  padding-bottom:6px;
}

.sidebar-link svg{
  flex-shrink:0;
}

.sidebar-logout{
  margin-top:22px;
  background:#fff;
  color:#1a3a6b;
  border:none;
  border-radius:999px;
  padding:12px 0;
  font-size:15px;
  font-weight:700;
  font-family:'Playfair Display', serif;
  cursor:pointer;
  width:100%;
}

.sidebar-footer{
  margin-top:24px;
  padding-top:18px;
  border-top:1px solid rgba(255,255,255,0.14);
  display:flex;
  flex-direction:column;
  gap:10px;
  align-items:center;
}

.sidebar-footer-social{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
}

.sidebar-social-icon{
  width:28px;
  height:28px;
  border-radius:50%;
  border:1.5px solid rgba(255,255,255,0.35);
  display:flex;
  align-items:center;
  justify-content:center;
  color:rgba(255,255,255,0.82);
  font-size:11px;
  font-weight:700;
  text-decoration:none;
}

.sidebar-footer-copy{
  color:rgba(255,255,255,0.45);
  font-size:10px;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:4px;
  flex-wrap:wrap;
}
.close-modal-btn{
  position:absolute;
  top:16px;
  right:20px;
  background:none;
  border:none;
  color:#8aa3c0;
  font-size:32px;
  font-weight:700;
  cursor:pointer;
  line-height:1;
}

/* ===== PAGE WRAP ===== */
.items-page-wrap{
  max-width:980px;
  margin:0 auto;
}

.page-header{
  margin-bottom:18px;
}

.page-header h1{
  font-size:34px;
  font-weight:700;
  background:linear-gradient(90deg,#143496 0%,#66a1d9 100%);
  -webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
  background-clip:text;
  display:inline-block;
}
.items-header-bar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  margin-bottom:22px;
  flex-wrap:wrap;
}

.items-header-left{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
}

.items-header-right{
  display:flex;
  justify-content:flex-end;
}

.add-item-open-btn{
  background:#f6811f;
  color:#fff;
  border:none;
  border-radius:999px;
  padding:12px 26px;
  font-size:17px;
  font-family:'Playfair Display', serif;
  font-weight:700;
  cursor:pointer;
  transition:.2s;
  box-shadow:0 4px 14px rgba(246,129,31,0.18);
}

.add-item-open-btn:hover{
  background:#df7413;
  transform:translateY(-1px);
}

.item-card-actions{
  display:flex;
  align-items:center;
  gap:10px;
  margin-top:10px;
}

.icon-action-btn{
  width:36px;
  height:36px;
  border-radius:50%;
  border:1.5px solid #d7e1ee;
  background:#fff;
  color:#183482;
  font-size:16px;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:center;
  transition:.2s;
}

.icon-action-btn:hover{
  background:#f7fbff;
  border-color:#ea8b2c;
}

.delete-icon-btn{
  color:#c2410c;
  border-color:#f3b39a;
}

.delete-icon-btn:hover{
  background:#fff3ed;
  border-color:#ea8b2c;
}
/* ===== MODE SWITCH ===== */
.mode-switch{
  display:flex;
  justify-content:center;
  gap:12px;
  margin-bottom:22px;
}

.mode-btn{
  min-width:180px;
  padding:12px 22px;
  border-radius:999px;
  border:1.8px solid #e07a1a;
  background:#fff;
  color:#e07a1a;
  font-size:19px;
  font-weight:600;
  font-family:'Playfair Display', serif;
  cursor:pointer;
  transition:.2s;
}

.mode-btn.active{
  background:#e07a1a;
  color:#fff;
}

.mode-btn:hover{
  transform:translateY(-1px);
}

.mode-helper-card{
  max-width:720px;
  margin:0 auto 22px;
  padding:18px 22px;
  background:#f7fbff;
  border:1.5px solid #d7e1ee;
  border-radius:18px;
  text-align:center;
  box-shadow:0 6px 18px rgba(26,58,107,0.05);
}

.mode-helper-card h3{
  font-size:20px;
  color:#183482;
  margin-bottom:8px;
}

.mode-helper-card p{
  font-size:14px;
  color:#6f86a8;
  line-height:1.7;
}

/* ===== TABS ===== */
.items-topbar > div {
  display: flex;
  gap: 8px;
  width: 100%;
}

.seg-btn {
  min-width: 180px;
  padding: 10px 20px;
  border-radius: 18px;
  border: 1.8px solid #ea8b2c;
  background: #fff;
  color: #183482;
  font-size: 19px;
  text-decoration: none;
  text-align: center;
  display: inline-block;
  transition: .2s;
}

.seg-btn.active{
  background:#f6811f;
  color:#fff;
  border-color:#f6811f;
}

.seg-btn:not(.active):hover{
  background:#fff8f2;
}

.items-topbar > div{
  display:flex;
  gap:16px;
}

/* ===== ITEM LIST / CARDS ===== */
.items-list{
  width:100%;
}

.order-row{
  background:#f5f8fc;
  border:1.6px solid #d2dce8;
  border-radius:22px;
  padding:16px 18px;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  margin:14px 0;
  text-decoration:none;
  color:inherit;
  transition:.2s;
}

.order-row:hover{
  box-shadow:0 4px 18px rgba(26,58,107,.10);
}

.quick-item-card{
  cursor:pointer;
}

.quick-item-card:hover{
  transform:translateY(-2px);
  border-color:#ea8b2c;
}

.quick-item-card.selected{
  border:2px solid #ea8b2c !important;
  box-shadow:0 8px 22px rgba(234,139,44,0.18);
  background:#fffaf4;
}

.order-left{
  display:flex;
  align-items:center;
  gap:16px;
}

.logo-box{
  width:130px;
  height:100px;
  border-radius:20px;
  border:1.4px solid #d2dce8;
  background:#fff;
  display:flex;
  align-items:center;
  justify-content:center;
  padding:8px;
  overflow:hidden;
  flex-shrink:0;
}

.logo-box img{
  width:100%;
  height:100%;
  object-fit:cover;
  border-radius:18px;
}

.order-info h3{
  margin:0 0 6px;
  font-size:20px;
  color:#183482;
  font-weight:700;
}

.info-line{
  display:flex;
  align-items:center;
  gap:8px;
  color:#4166ad;
  font-size:15px;
  margin:5px 0;
}

.order-right{
  display:flex;
  flex-direction:column;
  align-items:flex-end;
  gap:12px;
  flex-shrink:0;
}

.order-total{
  color:#ea8b2c;
  font-size:22px;
  font-weight:700;
}

.view-item-btn{
  display:inline-block;
  margin-top:10px;
  background:#183482;
  color:#fff;
  padding:8px 18px;
  border-radius:20px;
  text-decoration:none;
  font-size:14px;
  font-weight:600;
  border:none;
  cursor:pointer;
  transition:.2s;
}

.view-item-btn:hover{
  background:#10275f;
  transform:translateY(-1px);
}

/* ===== QUICK UPDATE PANEL ===== */
#quickUpdatePanel{
  transition: all 0.25s ease;
}
.quick-update-panel{
  max-width:900px;
  margin:24px auto 0;
  background:#fff7ef;
  border:1.5px solid #f3c999;
  border-radius:22px;
  padding:24px;
  box-shadow:0 8px 24px rgba(234,139,44,0.10);
  display:none;
}

.quick-update-note{
  font-size:14px;
  color:#6f86a8;
  margin-bottom:14px;
}

.quick-update-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:16px;
}

.quick-update-actions{
  display:flex;
  justify-content:flex-end;
  gap:12px;
  margin-top:20px;
}

.quick-cancel-btn{
  background:#fff;
  color:#8a9ab5;
  border:2px solid #c8d8ee;
  border-radius:999px;
  padding:10px 18px;
  font-size:14px;
  font-family:'Playfair Display', serif;
  cursor:pointer;
}

.quick-save-btn{
  background:#ea8b2c;
  color:#fff;
  border:none;
  border-radius:999px;
  padding:10px 20px;
  font-size:14px;
  font-family:'Playfair Display', serif;
  cursor:pointer;
}

.quick-save-btn:hover{
  background:#d87917;
}

/* ===== GENERAL FORM ===== */
.form-grid{
  display:grid;
  gap:16px;
  margin-bottom:0;
}

.form-grid.two-cols{
  grid-template-columns:1fr 1fr;
  align-items:start;
}

.form-group{
  display:flex;
  flex-direction:column;
}

.form-group label,
.section-label,
.icon-label{
  margin-bottom:6px;
  font-size:14px;
  font-weight:700;
  color:#183482;
}

.form-input,
.form-select,
.form-date,
.form-time,
.form-textarea{
  width:100%;
  border:1.5px solid #cfdbea;
  border-radius:14px;
  padding:12px 14px;
  font-size:14px;
  font-family:'Playfair Display', serif;
  color:#183482;
  background:#fff;
  box-sizing:border-box;
  outline:none;
}

.form-input,
.form-select,
.form-date,
.form-time{
  height:46px;
}

.form-textarea{
  min-height:90px;
  resize:vertical;
}

.form-input:focus,
.form-select:focus,
.form-date:focus,
.form-time:focus,
.form-textarea:focus{
  border-color:#ea8b2c;
  box-shadow:0 0 0 3px rgba(234,139,44,0.10);
}

.form-input.error,
.form-select.error,
.form-date.error,
.form-time.error,
.form-textarea.error{
  border-color:#d64545 !important;
}

input[type="file"].form-input{
  padding:8px 12px;
  line-height:28px;
}

/* ===== REQUIRED ===== */
.req{
  color:#d64545;
  font-weight:700;
}

/* ===== OLD TYPE CARDS ===== */
.type-cards-old{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:14px;
}

.type-card-old{
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  padding:18px 14px;
  border:2px solid #cfdbea;
  border-radius:18px;
  background:#fff;
  cursor:pointer;
  transition:.2s;
  text-align:center;
  min-height:100px;
}

.type-card-old input{
  display:none;
}

.type-card-old:hover{
  border-color:#ea8b2c;
  background:#fff8f2;
}

.type-card-old.active{
  border-color:#ea8b2c !important;
  background:#fff4e6 !important;
  box-shadow:0 0 0 3px rgba(234,139,44,0.15);
}

.type-card-icon{
  font-size:26px;
  margin-bottom:6px;
}

.type-card-title{
  font-size:16px;
  font-weight:700;
  color:#183482;
}

.type-card-sub{
  font-size:12px;
  color:#7a8fa8;
  margin-top:4px;
}

.price-wrap{
  display:none;
}

/* ===== TIMES ===== */
.slot-btn{
  padding:8px 12px;
  border-radius:999px;
  border:1.5px solid #cfdbea;
  background:#fff;
  color:#183482;
  font-size:12px;
  font-family:'Playfair Display', serif;
  cursor:pointer;
  transition:.2s;
}

.slot-btn:hover{
  background:#fff8f2;
  border-color:#ea8b2c;
}

.slot-btn.selected{
  background:#ea8b2c;
  color:#fff;
  border-color:#ea8b2c;
}

.time-list{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  margin-top:12px;
}

.time-chip{
  display:inline-flex;
  align-items:center;
  gap:6px;
  background:#fff;
  border:1.5px solid #cfdbea;
  border-radius:999px;
  padding:7px 12px;
  font-size:13px;
  color:#183482;
}

.small-time-select{
  width:90px;
  height:42px;
  border:1.5px solid #cfdbea;
  border-radius:12px;
  padding:0 10px;
  font-size:13px;
  font-family:'Playfair Display', serif;
  color:#183482;
  background:#fff;
  outline:none;
}

.add-time-btn{
  height:42px;
  border:none;
  background:#ea8b2c;
  color:#fff;
  border-radius:999px;
  padding:0 16px;
  font-size:14px;
  font-weight:700;
  font-family:'Playfair Display', serif;
  cursor:pointer;
}

/* ===== MODAL ===== */
#addItemModal{
  display:none;
  position:fixed;
  inset:0;
  background:rgba(12,22,45,0.45);
  z-index:9999;
  justify-content:center;
  align-items:center;
  padding:20px;
}

#addItemModal > div{
  background:#f7fbff;
  border-radius:26px;
  border:1.5px solid #cfdbea;
  padding:34px 36px;
  max-width:900px;
  width:92%;
  max-height:90vh;
  overflow-y:auto;
  box-shadow:0 20px 60px rgba(26,58,107,0.18);
  position:relative;
}

.add-item-card{
  width:100%;
  background:#f7fbff;
  border:1.5px solid #cfdbea;
  border-radius:24px;
  padding:26px 22px 28px;
}

.add-item-form{
  display:flex;
  flex-direction:column;
  gap:20px;
}

#addItemModal h1{
  font-size:36px;
  font-weight:700;
  text-align:center;
  margin-bottom:28px;
  background:linear-gradient(90deg,#143496 0%,#66a1d9 100%);
  -webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
  background-clip:text;
}

.submit-row{
  display:flex;
  justify-content:center;
  margin-top:10px;
}

.add-btn{
  min-width:200px;
  height:52px;
  border:none;
  background:#f6811f;
  color:#fff;
  border-radius:999px;
  font-size:18px;
  font-weight:700;
  font-family:'Playfair Display', serif;
  cursor:pointer;
}

/* ===== TOAST ===== */
#quickToast{
  display:none;
  position:fixed;
  bottom:24px;
  right:24px;
  background:#183482;
  color:#fff;
  padding:12px 18px;
  border-radius:12px;
  box-shadow:0 10px 24px rgba(0,0,0,0.16);
  z-index:99999;
  font-size:14px;
}

/* ===== PAGE HEAD ===== */
.add-item-page-head{
  margin-bottom:24px;
}

.add-item-page-head h1{
  margin:10px 0 6px;
  font-size:32px;
  font-weight:800;
  color:#2b1b12;
}

.add-item-page-head p{
  margin:0;
  color:#7a6a58;
  font-size:15px;
}

.back-link{
  display:inline-flex;
  align-items:center;
  gap:6px;
  text-decoration:none;
  color:#a85a17;
  font-weight:700;
  font-size:14px;
}

/* ===== ERRORS ===== */
.field-error{
  display:none;
  font-size:12px;
  color:#b42318;
  margin-top:4px;
}

.field-error.show{
  display:block;
}
/* ── MOBILE HEADER / HAMBURGER ── */
.hamburger {
  display: none;
  flex-direction: column;
  gap: 5px;
  cursor: pointer;
  background: none;
  border: none;
  padding: 6px;
}

.hamburger span {
  display: block;
  width: 24px;
  height: 2.5px;
  background: #fff;
  border-radius: 2px;
  transition: all 0.3s;
}

.hamburger.open span:nth-child(1) {
  transform: translateY(7.5px) rotate(45deg);
}

.hamburger.open span:nth-child(2) {
  opacity: 0;
}

.hamburger.open span:nth-child(3) {
  transform: translateY(-7.5px) rotate(-45deg);
}

.mobile-menu {
  display: none;
  position: fixed;
  inset: 0;
  top: 72px;
  background: linear-gradient(180deg, #1a3a6b 0%, #2255a4 100%);
  z-index: 99;
  flex-direction: column;
  padding: 24px 20px;
}

.mobile-menu.open {
  display: flex;
}

.mobile-menu a {
  color: rgba(255,255,255,0.9);
  font-size: 22px;
  font-weight: 700;
  padding: 18px 0;
  border-bottom: 1px solid rgba(255,255,255,0.12);
  text-decoration: none;
}

.mobile-search {
  margin-top: 22px;
  position: relative;
}
.mobile-search svg {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  opacity: 0.6;
  pointer-events: none;
}

.mobile-search input::placeholder {
  color: rgba(255,255,255,0.6);
  font-family: 'Playfair Display', serif;
}
.mobile-search input {
  width: 100%;
  background: rgba(255,255,255,0.15);
  border: 1.5px solid rgba(255,255,255,0.4);
  border-radius: 50px;
  padding: 12px 16px 12px 40px;
  color: #fff;
  outline: none;
}

.search-dropdown {
  display: none;
  position: absolute;
  top: calc(100% + 10px);
  left: 0;
  width: 360px;
  max-width: calc(100vw - 40px);
  background: #fff;
  border-radius: 18px;
  border: 1.5px solid #e0eaf5;
  box-shadow: 0 12px 40px rgba(26,58,107,0.18);
  z-index: 9999;
  overflow: hidden;
}

.search-dropdown.visible {
  display: block;
}

.sd-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  text-decoration: none;
  color: inherit;
  transition: background 0.15s;
}

.sd-row:hover {
  background: #f4f8ff;
}

.sd-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  background: #edf3fb;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #1a3a6b;
  flex-shrink: 0;
}

.sd-info {
  min-width: 0;
}

.sd-name {
  font-size: 14px;
  font-weight: 700;
  color: #1a3a6b;
}

.sd-sub {
  font-size: 12px;
  color: #7a8fa8;
  margin-top: 2px;
}

.mobile-search {
  position: relative;
}

.mobile-search .search-dropdown {
  top: calc(100% + 12px);
  left: 0;
  width: 100%;
}
/* DESKTOP SEARCH RESULT IMPROVEMENT */
.search-dropdown {
  width: 405px;
  border-radius: 20px;
  overflow: hidden;
}

.sd-section-title {
  font-size: 13px;
  font-weight: 700;
  color: #183482;
  padding: 12px 16px 6px;
  background: #fff;
}

.sd-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
}

.sd-icon {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  overflow: hidden;
  flex-shrink: 0;
  background: #edf3fb;
}

.sd-icon img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 14px;
}

.sd-info {
  min-width: 0;
  flex: 1;
}

.sd-name {
  font-size: 16px;
  font-weight: 700;
  color: #183482;
  line-height: 1.2;
  margin-bottom: 2px;
}

.sd-sub {
  font-size: 13px;
  color: #7a8fa8;
  line-height: 1.3;
}

@media (max-width: 768px) {
  .hamburger {
    display: flex;
  }

  .sidebar {
    display: none;
  }

  .page-body {
    display: block;
  }

  .main {
    width: 100%;
    padding: 20px 16px;
    margin: 0;
  }
  .items-topbar > div {
  display: flex;
  gap: 8px;
  width: 100%;
}

.seg-btn {
  flex: 1;
  min-width: 0;
  width: auto;
  padding: 10px 6px;
  font-size: 14px;
}
  .items-page-wrap {
    width: 100%;
    max-width: 100%;
    margin: 0 auto;
  }

  nav.navbar {
    padding: 0 16px;
  }

  .nav-logo {
    height: 70px;
  }

  .nav-provider-text {
    display: none;
  }

  .nav-search-wrap {
    display: none;
  }

.items-header-bar,
.mode-switch,
.quick-update-actions,
.submit-row {
  flex-direction: column;
}

  .items-header-left,
  .items-header-right,
  .add-item-open-btn,
  .mode-btn,
  .seg-btn,
  .quick-cancel-btn,
  .quick-save-btn,
  .add-btn {
    width: 100%;
  }

  .order-row {
    flex-direction: column;
    align-items: flex-start;
  }

  .order-right {
    width: 100%;
    align-items: flex-start;
  }

  .quick-update-grid,
  .form-grid.two-cols,
  .type-cards-old {
    grid-template-columns: 1fr;
  }

  #addItemModal > div {
    width: 100%;
    padding: 24px 18px;
  }
  .mobile-search .search-dropdown {
  top: calc(100% + 8px);
  width: 100%;
  max-width: 100%;
  border-radius: 16px;
}

.mobile-search .sd-row {
  padding: 10px 12px;
  gap: 10px;
}

.mobile-search .sd-icon {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  flex-shrink: 0;
}

.mobile-search .sd-icon img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 12px;
}

.mobile-search .sd-info {
  min-width: 0;
  flex: 1;
}

.mobile-search .sd-name {
  font-size: 15px;
  font-weight: 700;
  line-height: 1.2;
  color: #183482;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.mobile-search .sd-sub {
  font-size: 13px;
  line-height: 1.2;
  margin-top: 3px;
}

.mobile-search .sd-section-title {
  font-size: 12px;
  padding: 10px 12px 6px;
}
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
<div class="nav-search-wrap" id="searchWrap">
  <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
    <circle cx="11" cy="11" r="8"/>
    <path d="M21 21l-4.35-4.35"/>
  </svg>

  <input
    type="text"
    id="searchInput"
    placeholder="Search items ..."
    autocomplete="off"
  />

  <div class="search-dropdown" id="searchDropdown"></div>
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

    <button id="hamburger" class="hamburger" onclick="toggleMobileMenu()" aria-label="Open menu">
      <span></span>
      <span></span>
      <span></span>
    </button>

  </div>
</nav>

<div class="mobile-menu" id="mobileMenu">
  <div class="mobile-search">
    <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="7"></circle>
      <path d="m21 21-4.3-4.3"></path>
    </svg>

    <input
      type="text"
      id="mobileSearchInput"
      placeholder="Search items ..."
    />

    <div class="search-dropdown" id="mobileSearchDropdown"></div>
  </div>

  <a href="provider-dashboard.php" onclick="closeMobileMenu()">Dashboard</a>
  <a href="provider-items.php" onclick="closeMobileMenu()">Items</a>
  <a href="provider-orders.php" onclick="closeMobileMenu()">Orders</a>
  <a href="provider-profile.php" onclick="closeMobileMenu()">Profile</a>
  <a href="provider-dashboard.php?logout=1" onclick="closeMobileMenu()">Log out</a>
</div>

<div class="page-body">
<aside class="sidebar">
<p class="sidebar-welcome">Welcome Back ,</p>
<p class="sidebar-name"><?= htmlspecialchars($firstName) ?></p>
<nav class="sidebar-nav">
<a href="provider-dashboard.php" class="sidebar-link ">
<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
Dashboard
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
<button class="sidebar-logout" onclick="window.location.href='provider-dashboard.php?logout=1'">Logout</button>
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
<div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>
<main class="main">
  <div class="items-page-wrap">
<div class="page-header">
  <h1><span>My</span> Items</h1>
</div>

<div class="items-header-bar">
  <div class="items-header-left">
    <button class="mode-btn active" id="viewItemsBtn" onclick="showMode('view')">
      View Items
    </button>

    <button class="mode-btn" id="quickUpdateBtn" onclick="showQuickHelper()">
      Quick Update
    </button>
  </div>

<div class="items-header-right">
  <button
    type="button"
    class="add-item-open-btn"
    onclick="resetAddItemModal(); document.getElementById('addItemModal').style.display='flex'">
    + Add New Item
  </button>
</div>
</div>

<div id="viewMode">
  <div class="mode-helper-card" id="viewItemsHelper">
    <h3>View Your Items</h3>
    <p>See all your available items here. You can select any item to update its daily availability, and later we can add edit and delete actions inside this section.</p>
  </div>

  <div class="mode-helper-card" id="quickUpdateHelper" style="display:none;">
    <h3>Daily Availability Update</h3>
    <p>Select an existing item and update only the details that change often, like quantity, expiry date, pickup branch, pickup date, and pickup times.</p>
  </div>

  <div class="items-topbar">
    <div style="display:flex;gap:16px;">
     <a class="seg-btn <?= $tab==='all'?'active':'' ?>" href="provider-items.php?tab=all">All</a>
<a class="seg-btn <?= $tab==='donate'?'active':'' ?>" href="provider-items.php?tab=donate">Donate</a>
<a class="seg-btn <?= $tab==='sell'?'active':'' ?>" href="provider-items.php?tab=sell">Sell</a>
    </div>
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
        ? 'Donation'
        : number_format((float)($item['price'] ?? 0), 2) .
          ' <img src="../../images/SAR.png" style="height:14px;vertical-align:middle;">';
    ?>
    <div
      class="order-row quick-item-card"
  id="item-<?= htmlspecialchars((string)$item['_id']) ?>"
  onclick="selectQuickItem(this)"
  data-id="<?= htmlspecialchars((string)$item['_id']) ?>"
      data-name="<?= htmlspecialchars($item['itemName'] ?? 'Item') ?>"
      data-description="<?= htmlspecialchars($item['description'] ?? '') ?>"
      data-photo="<?= htmlspecialchars($item['photoUrl'] ?? '') ?>"
      data-type="<?= htmlspecialchars($item['listingType'] ?? '') ?>"
      data-price="<?= htmlspecialchars((string)($item['price'] ?? 0)) ?>"
      data-quantity="<?= htmlspecialchars((string)($item['quantity'] ?? 1)) ?>"
      data-category="<?= htmlspecialchars($categoryName) ?>"
      data-expiry="<?= !empty($item['expiryDate']) && $item['expiryDate'] instanceof MongoDB\BSON\UTCDateTime ? $item['expiryDate']->toDateTime()->format('Y-m-d') : '' ?>"
      data-pickupdate="<?= !empty($item['pickupDate']) && $item['pickupDate'] instanceof MongoDB\BSON\UTCDateTime ? $item['pickupDate']->toDateTime()->format('Y-m-d') : '' ?>"
      data-pickuplocation="<?= htmlspecialchars((string)($item['pickupLocationId'] ?? '')) ?>"
    >
      <div class="order-left">
        <div class="logo-box">
          <?php if (!empty($item['photoUrl'])): ?>
            <img
              src="<?= htmlspecialchars($item['photoUrl']) ?>"
              alt="<?= htmlspecialchars($item['itemName'] ?? 'Item') ?>"
              onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
            >
            <div style="display:none;width:100%;height:100%;align-items:center;justify-content:center;font-size:13px;color:#8ea0bf;">
              No Image
            </div>
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
          <?= $priceText ?>
        </div>

<div class="item-card-actions">

<button
  type="button"
  class="icon-action-btn"
  onclick="event.stopPropagation(); openEditItemModal(this, event)"
  data-id="<?= htmlspecialchars((string)$item['_id']) ?>"
  data-name="<?= htmlspecialchars($item['itemName'] ?? '') ?>"
  data-description="<?= htmlspecialchars($item['description'] ?? '') ?>"
  data-type="<?= htmlspecialchars($item['listingType'] ?? '') ?>"
  data-price="<?= htmlspecialchars((string)($item['price'] ?? 0)) ?>"
  data-categoryid="<?= htmlspecialchars((string)($item['categoryId'] ?? '')) ?>"
  data-quantity="<?= htmlspecialchars((string)($item['quantity'] ?? 1)) ?>"
  data-expiry="<?= !empty($item['expiryDate']) && $item['expiryDate'] instanceof MongoDB\BSON\UTCDateTime ? $item['expiryDate']->toDateTime()->format('Y-m-d') : '' ?>"
  data-pickupdate="<?= !empty($item['pickupDate']) && $item['pickupDate'] instanceof MongoDB\BSON\UTCDateTime ? $item['pickupDate']->toDateTime()->format('Y-m-d') : '' ?>"
  data-pickuplocation="<?= htmlspecialchars((string)($item['pickupLocationId'] ?? '')) ?>"
  data-photo="<?= htmlspecialchars($item['photoUrl'] ?? '') ?>"
>
  ✏️
</button>

  <button
    type="button"
    class="icon-action-btn delete-icon-btn"
    onclick="deleteItem('<?= (string)$item['_id'] ?>', event)"
  >
    🗑
  </button>
</div>

      </div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<div id="quickUpdatePanel" class="quick-update-panel">
  
<input type="hidden" id="quickItemId">
<div class="quick-update-note">
    Update only the daily-changing details for the selected item.
  </div>

  <div class="quick-update-grid">
       <div>
      <div class="icon-label"><span>Select Type</span></div>
      <select class="form-select" id="quickType" onchange="handleQuickTypeChange()">
  <option value="donate">Donate</option>
  <option value="sell">Sell</option>
</select>
    </div>

    <div>
      <div class="icon-label"><span>Price</span></div>
      <input class="form-input" type="number" id="quickPrice" min="0" step="0.01" placeholder="Enter price">
    </div>

    <div>
  <div class="icon-label"><span>Quantity</span></div>
  <input class="form-input" type="number" id="quickQuantity" min="0" placeholder="Enter quantity">
</div>

<div>
  <div class="icon-label"><span>Pickup Date</span></div>
  <input class="form-date" type="date" id="quickPickupDate" min="<?= date('Y-m-d') ?>">
</div>

<div>
  <div class="icon-label"><span>Pickup Branch</span></div>
  <select class="form-select" id="quickPickupLocation">
    <option value="" disabled selected hidden>Select pickup branch</option>
    <?php foreach ($locations as $loc): ?>
      <?php
      $branchName = trim($loc['locationName'] ?? ($loc['label'] ?? ''));
      if ($branchName === '') {
          $branchName = 'Branch';
      }
      $branchStreet = $loc['street'] ?? '';
      $branchCity   = $loc['city'] ?? '';
      ?>
      <option value="<?= htmlspecialchars((string)$loc['_id']) ?>">
        <?= htmlspecialchars(trim(implode(' - ', array_filter([$branchName, $branchStreet, $branchCity])))) ?>
      </option>
    <?php endforeach; ?>
  </select>
</div>

<div>
  <div class="icon-label"><span>Expiry Date</span></div>
  <input class="form-date" type="date" id="quickExpiryDate" min="<?= date('Y-m-d') ?>">
</div>

<div class="quick-update-actions" style="grid-column:1 / -1;">
  <button type="button" class="quick-cancel-btn" onclick="clearQuickSelection()">Cancel</button>
  <button type="button" class="quick-save-btn" onclick="saveQuickUpdate()">Save Update</button>
</div>
</div>
</div>
</div>
</div>

</div>
<!-- ADD ITEM MODAL -->
<div id="addItemModal" style="display:none;position:fixed;inset:0;background:rgba(12,22,45,0.45);z-index:9999;justify-content:center;align-items:center;padding:20px;" onclick="if(event.target===this)this.style.display='none'">
<div style="background:#f7fbff;border-radius:26px;border:1.5px solid #cfdbea;padding:36px 32px;max-width:760px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(26,58,107,0.18);position:relative;">

<button
  type="button"
  class="close-modal-btn"
  onclick="resetAddItemModal(); document.getElementById('addItemModal').style.display='none'">
  &times;
</button>
<h1 style="font-size:36px;font-weight:700;font-family:'Playfair Display',serif;background:linear-gradient(90deg,#143496 0%,#66a1d9 100%);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;text-align:center;margin-bottom:28px;">Add Item</h1>
<?php if (!empty($formError)): ?>
<div style="margin-bottom:16px;padding:12px 16px;border-radius:12px;background:#ffe7e7;border:1px solid #f3b3b3;color:#b42318;font-size:14px;">
<?= htmlspecialchars($formError) ?>
</div>
<?php endif; ?>

<form class="add-item-form" id="addItemForm" method="POST" action="" enctype="multipart/form-data">

<input type="hidden" name="formAction" id="formAction" value="addItem">
<input type="hidden" name="editItemId" id="editItemId" value="">
<input type="hidden" name="existingPhotoUrl" id="existingPhotoUrl" value="">

<div class="add-item-card">
<div>
  <div class="section-label" style="margin-bottom:12px;">Select type <span class="req">*</span></div>

  <div class="type-cards-old">
    <label class="type-card-old" id="typeCardDonate" onclick="selectTypeCard('donate')">
      <input
        type="radio"
        name="itemType"
        value="donate"
        style="display:none;"
        <?= (($_POST['itemType'] ?? '') === 'donate') ? 'checked' : '' ?>
      >
      <div class="type-card-icon">🤝</div>
      <div class="type-card-title">Donate</div>
      <div class="type-card-sub">Give it for free</div>
    </label>

    <label class="type-card-old" id="typeCardSell" onclick="selectTypeCard('sell')">
      <input
        type="radio"
        name="itemType"
        value="sell"
        style="display:none;"
        <?= (($_POST['itemType'] ?? '') === 'sell') ? 'checked' : '' ?>
      >
      <div class="type-card-icon">🏷️</div>
      <div class="type-card-title">Sell</div>
      <div class="type-card-sub">Set a price</div>
    </label>
  </div>

  <div class="field-error" id="itemTypeError">Please select a type.</div>
</div>

<div class="price-wrap" id="priceWrap">
  <div class="section-label">Price <span class="req">*</span></div>
  <input
    class="form-input"
    type="number"
    name="price"
    id="priceInput"
    step="0.01"
    min="0"
    value="<?= htmlspecialchars($_POST['price'] ?? '') ?>"
    placeholder="Enter price"
  >
  <div class="field-error" id="priceError">Please enter a valid price.</div>
</div>
<div class="form-grid two-cols">
  <div class="form-group">
    <label for="itemName">Item Name <span class="req">*</span></label>
    <input
      type="text"
      name="itemName"
      id="itemName"
      class="form-input"
      value="<?= htmlspecialchars($_POST['itemName'] ?? '') ?>"
      placeholder="Enter item name"
    >
    <div class="field-error" id="itemNameError">Please enter the item name.</div>
  </div>

  <div class="form-group">
    <label for="categoryId">Category <span class="req">*</span></label>
    <select name="categoryId" id="categoryId" class="form-select">
      <option value="">Select category</option>
      <?php foreach ($categories as $category): ?>
        <option
          value="<?= htmlspecialchars((string)$category['_id']) ?>"
          <?= (($_POST['categoryId'] ?? '') == (string)$category['_id']) ? 'selected' : '' ?>
        >
          <?= htmlspecialchars($category['name'] ?? 'Unnamed Category') ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="field-error" id="categoryIdError">Please select a category.</div>
  </div>
</div>

<div class="form-grid two-cols">

</div>

<div class="form-group">
  <label>Description *</label>
  <textarea class="form-textarea" name="itemDetails" id="itemDetails"></textarea>
  <div class="field-error" id="itemDetailsError">Item details are required.</div>
</div>

<div class="form-group">
  <label>Photo *</label>
  <input class="form-input" type="file" name="itemPhoto" id="itemPhoto">
  <div class="field-error" id="itemPhotoError">Please upload a photo.</div>
</div>

<div class="form-grid two-cols">
  <div class="form-group">
    <label>Pickup Branch <span class="req">*</span></label>
    <select class="form-select" name="pickupLocationId" id="pickupLocationId">
      <option value="">Select pickup branch</option>
      <?php foreach ($locations as $loc): ?>
        <?php
          $branchName = trim($loc['locationName'] ?? ($loc['label'] ?? ''));
          if ($branchName === '') {
              $branchName = trim($loc['street'] ?? '');
          }
          if ($branchName === '') {
              $branchName = 'Branch';
          }

          $branchStreet = trim($loc['street'] ?? '');
          $branchCity   = trim($loc['city'] ?? '');
          $branchText   = trim(implode(' - ', array_filter([$branchName, $branchStreet, $branchCity])));
        ?>
        <option
          value="<?= htmlspecialchars((string)$loc['_id']) ?>"
          <?= ($defaultLocation && (string)$loc['_id'] === (string)$defaultLocation['_id']) ? 'selected' : '' ?>
        >
          <?= htmlspecialchars($branchText) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <div class="field-error" id="pickupLocationIdError">Please select a pickup branch.</div>
  </div>

  <div class="form-group">
    <label>Pickup Date <span class="req">*</span></label>
    <input
      class="form-date"
      type="date"
      name="pickupDate"
      id="pickupDate"
      min="<?= $today ?>"
      value="<?= htmlspecialchars($_POST['pickupDate'] ?? $today) ?>"
    >
    <div class="field-error" id="pickupDateError">Please select a valid pickup date.</div>
  </div>
</div>

<div class="form-group">
  <label>Pickup Times *</label>

  <div style="display:flex;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
    <?php
    $slots = ['8:00 AM','9:00 AM','10:00 AM','11:00 AM','12:00 PM','1:00 PM','2:00 PM','3:00 PM','4:00 PM','5:00 PM','6:00 PM','7:00 PM','8:00 PM'];
    foreach ($slots as $slot): ?>
      <button type="button" class="slot-btn" onclick="toggleSlot('<?= $slot ?>', this)">
        <?= $slot ?>
      </button>
    <?php endforeach; ?>
  </div>

  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
    <select id="customHour" class="small-time-select">
      <option value="">Hour</option>
      <?php for($h=1;$h<=12;$h++): ?>
        <option value="<?= $h ?>"><?= $h ?></option>
      <?php endfor; ?>
    </select>

    <select id="customMinute" class="small-time-select">
      <option value="">Min</option>
      <option value="00">00</option>
      <option value="15">15</option>
      <option value="30">30</option>
      <option value="45">45</option>
    </select>

    <select id="customAmPm" class="small-time-select">
      <option value="AM">AM</option>
      <option value="PM">PM</option>
    </select>

    <button type="button" class="add-time-btn" onclick="addCustomTime()">+ Add</button>
  </div>

  <div class="time-list" id="timeList"></div>
  <div id="hiddenTimesWrap"></div>
  <div class="field-error" id="pickupTimesError">Please add at least one pickup time.</div>
</div>

<div class="submit-row">
<button class="add-btn" type="submit">Add Item</button>
</div>
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
let pickupTimes = <?= json_encode($_POST['pickupTimes'] ?? []) ?>;

function selectTypeCard(type) {
  const donateInput = document.querySelector('input[name="itemType"][value="donate"]');
  const sellInput = document.querySelector('input[name="itemType"][value="sell"]');

  if (type === 'donate' && donateInput) donateInput.checked = true;
  if (type === 'sell' && sellInput) sellInput.checked = true;

  const donateCard = document.getElementById('typeCardDonate');
  const sellCard = document.getElementById('typeCardSell');

  if (donateCard) donateCard.classList.toggle('active', type === 'donate');
  if (sellCard) sellCard.classList.toggle('active', type === 'sell');

  if (type === 'sell') {
    priceWrap.style.display = 'block';
  } else {
    priceWrap.style.display = 'none';
    if (priceInput) priceInput.value = '';
  }

  hideError('itemTypeError');
}

function initTypeCards() {
  const checked = document.querySelector('input[name="itemType"]:checked');
  if (checked) {
    selectTypeCard(checked.value);
  } else {
    priceWrap.style.display = 'none';
  }
}

function renderPickupTimes() {
  timeList.innerHTML = '';
  hiddenTimesWrap.innerHTML = '';

  pickupTimes.forEach((time, index) => {
    const chip = document.createElement('span');
    chip.className = 'time-chip';
    chip.innerHTML = `
      ${time}
      <button type="button" onclick="removeTime(${index})" style="border:none;background:none;cursor:pointer;font-weight:bold;">×</button>
    `;
    timeList.appendChild(chip);

    const hidden = document.createElement('input');
    hidden.type = 'hidden';
    hidden.name = 'pickupTimes[]';
    hidden.value = time;
    hiddenTimesWrap.appendChild(hidden);
  });

  document.querySelectorAll('.slot-btn').forEach(btn => {
    btn.classList.toggle('selected', pickupTimes.includes(btn.textContent.trim()));
  });
}

function toggleSlot(time, button) {
  const exists = pickupTimes.includes(time);

  if (exists) {
    pickupTimes = pickupTimes.filter(t => t !== time);
  } else {
    pickupTimes.push(time);
  }

  renderPickupTimes();
}

function addCustomTime() {
  const hour = document.getElementById('customHour').value;
  const minute = document.getElementById('customMinute').value;
  const ampm = document.getElementById('customAmPm').value;

  if (!hour || !minute) {
    showError('pickupTimesError');
    return;
  }

  const newTime = `${hour}:${minute} ${ampm}`;

  if (!pickupTimes.includes(newTime)) {
    pickupTimes.push(newTime);
    renderPickupTimes();
  }

  document.getElementById('customHour').value = '';
  document.getElementById('customMinute').value = '';
  document.getElementById('customAmPm').value = 'AM';
}

function removeTime(index) {
  pickupTimes.splice(index, 1);
  renderPickupTimes();
}

function showError(id) { document.getElementById(id)?.classList.add('show'); }
function hideError(id) { document.getElementById(id)?.classList.remove('show'); }
function markField(el, hasError) { el?.classList.toggle('error', hasError); }

initTypeCards();
renderPickupTimes();
</script>

<script>
const itemPhotoInput = document.getElementById('itemPhoto');

form.addEventListener('submit', function(e) {
    let valid = true;

    const currentAction = document.getElementById('formAction').value;
    const isEdit = currentAction === 'editItem';

    const typeChecked = document.querySelector('input[name="itemType"]:checked');
    if (!typeChecked) {
        showError('itemTypeError');
        valid = false;
    } else {
        hideError('itemTypeError');
    }

    if (typeChecked && typeChecked.value === 'sell') {
        if (!priceInput.value || Number(priceInput.value) <= 0) {
            showError('priceError');
            markField(priceInput, true);
            valid = false;
        } else {
            hideError('priceError');
            markField(priceInput, false);
        }
    }

    const itemName = document.getElementById('itemName');
    if (!itemName.value.trim()) {
        showError('itemNameError');
        markField(itemName, true);
        valid = false;
    } else {
        hideError('itemNameError');
        markField(itemName, false);
    }

    const itemDetails = document.getElementById('itemDetails');
    if (!itemDetails.value.trim()) {
        showError('itemDetailsError');
        markField(itemDetails, true);
        valid = false;
    } else {
        hideError('itemDetailsError');
        markField(itemDetails, false);
    }

    // photo required only when adding, not editing
    if (!isEdit && !itemPhotoInput.files[0]) {
        showError('itemPhotoError');
        markField(itemPhotoInput, true);
        valid = false;
    } else {
        hideError('itemPhotoError');
        markField(itemPhotoInput, false);
    }

    const category = document.getElementById('categoryId');
    if (!category.value) {
        showError('categoryIdError');
        markField(category, true);
        valid = false;
    } else {
        hideError('categoryIdError');
        markField(category, false);
    }

    // pickup times required only when adding
    if (!isEdit) {
        if (pickupTimes.length === 0) {
            showError('pickupTimesError');
            valid = false;
        } else {
            hideError('pickupTimesError');
        }
    }

    if (!valid) {
        e.preventDefault();
    }
});
</script>
<script>
function showMode(mode){
    const viewMode = document.getElementById('viewMode');
    const viewItemsHelper = document.getElementById('viewItemsHelper');
    const quickUpdateHelper = document.getElementById('quickUpdateHelper');
    const viewItemsBtn = document.getElementById('viewItemsBtn');
    const quickUpdateBtn = document.getElementById('quickUpdateBtn');

    if (mode === 'view') {
        viewMode.style.display = 'block';
        viewItemsHelper.style.display = 'block';
        quickUpdateHelper.style.display = 'none';

        viewItemsBtn.classList.add('active');
        quickUpdateBtn.classList.remove('active');

        clearQuickSelection();
    }
}

function showQuickHelper(){
    const viewItemsHelper = document.getElementById('viewItemsHelper');
    const quickUpdateHelper = document.getElementById('quickUpdateHelper');
    const viewItemsBtn = document.getElementById('viewItemsBtn');
    const quickUpdateBtn = document.getElementById('quickUpdateBtn');

    viewItemsHelper.style.display = 'none';
    quickUpdateHelper.style.display = 'block';

    viewItemsBtn.classList.remove('active');
    quickUpdateBtn.classList.add('active');
}
</script>
<script>
function saveQuickUpdate(){
  const data = {
  id: document.getElementById('quickItemId').value,
  quantity: document.getElementById('quickQuantity').value,
  price: document.getElementById('quickPrice').value,
  expiryDate: document.getElementById('quickExpiryDate').value,
  pickupDate: document.getElementById('quickPickupDate').value,
  pickupLocationId: document.getElementById('quickPickupLocation').value,
  listingType: document.getElementById('quickType').value
};

  fetch('quick-update-item.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  })
  .then(res => res.json())
  .then(res => {
    if(res.success){
      showQuickToast('Updated successfully');
setTimeout(() => {
  location.reload();
}, 900);
    } else {
      showQuickToast('Error: ' + res.message);
    }
  })
  .catch(err => {
    showQuickToast('Something went wrong');
  });
}
</script>
<script>
function handleQuickTypeChange(){
  const type = document.getElementById('quickType').value;
  const priceInput = document.getElementById('quickPrice');

  if (type === 'donate') {
    priceInput.disabled = true;
    priceInput.value = 0;
  } else {
    priceInput.disabled = false;
  }
}
</script>
<script>
function showQuickToast(message){
  const toast = document.getElementById('quickToast');
  toast.textContent = message;
  toast.style.display = 'block';

  setTimeout(() => {
    toast.style.display = 'none';
  }, 2200);
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const today = new Date().toISOString().split('T')[0];

    // expiry date
    const expiry = document.getElementById('quickExpiryDate');
    if (expiry) expiry.setAttribute('min', today);

    // pickup date
    const pickup = document.getElementById('quickPickupDate');
    if (pickup) pickup.setAttribute('min', today);
});
</script>
<script>
let deleteTargetItemId = '';

function deleteItem(itemId, event){
  event.stopPropagation();
  deleteTargetItemId = itemId;
  document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal(){
  deleteTargetItemId = '';
  document.getElementById('deleteModal').style.display = 'none';
}

function confirmDeleteItem(){
  if (!deleteTargetItemId) return;

  const currentId = deleteTargetItemId;

  const formData = new FormData();
  formData.append('formAction', 'deleteItem');
  formData.append('itemId', currentId);

  fetch(window.location.href, {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if (data.success) {
      closeDeleteModal();

      const itemCard =
        document.getElementById('item-' + currentId) ||
        document.querySelector('[data-item-id="' + currentId + '"]') ||
        document.querySelector('[data-id="' + currentId + '"]');

      if (itemCard) {
        itemCard.style.transition = '0.3s ease';
        itemCard.style.opacity = '0';
        itemCard.style.transform = 'translateY(-10px)';

        setTimeout(() => {
          itemCard.remove();
        }, 300);
      }

      deleteTargetItemId = '';
    } else {
      alert(data.message || 'Delete failed');
    }
  })
  .catch(err => {
    console.log(err);
    alert('Something went wrong');
  });
}
function resetAddItemModal(){
  document.getElementById('formAction').value = 'addItem';
  document.getElementById('editItemId').value = '';
  document.getElementById('existingPhotoUrl').value = '';

  document.getElementById('itemName').value = '';
  document.getElementById('itemDetails').value = '';
  document.getElementById('categoryId').value = '';
  document.getElementById('priceInput').value = '';
  document.getElementById('itemPhoto').value = '';
  document.getElementById('pickupLocationId').value = '<?= $defaultLocation ? (string)$defaultLocation['_id'] : '' ?>';
  document.getElementById('pickupDate').value = '<?= $today ?>';

  const donateInput = document.querySelector('input[name="itemType"][value="donate"]');
  const sellInput = document.querySelector('input[name="itemType"][value="sell"]');
  if (donateInput) donateInput.checked = false;
  if (sellInput) sellInput.checked = false;

  const donateCard = document.getElementById('typeCardDonate');
  const sellCard = document.getElementById('typeCardSell');
  if (donateCard) donateCard.classList.remove('active');
  if (sellCard) sellCard.classList.remove('active');

  pickupTimes = [];
  renderPickupTimes();
  priceWrap.style.display = 'none';

  const modalTitle = document.querySelector('#addItemModal h1');
  if(modalTitle) modalTitle.textContent = 'Add Item';

  const submitBtn = document.querySelector('#addItemForm .add-btn');
  if(submitBtn) submitBtn.textContent = 'Add Item';
}
</script>

<script>
const itemPhotoInput = document.getElementById('itemPhoto');

form.addEventListener('submit', function(e) {
    let valid = true;

    const typeChecked = document.querySelector('input[name="itemType"]:checked');
    if (!typeChecked) {
        showError('itemTypeError');
        valid = false;
    } else {
        hideError('itemTypeError');
    }

    if (typeChecked && typeChecked.value === 'sell') {
        if (!priceInput.value || Number(priceInput.value) <= 0) {
            showError('priceError');
            markField(priceInput, true);
            valid = false;
        } else {
            hideError('priceError');
            markField(priceInput, false);
        }
    }

    const itemName = document.getElementById('itemName');
    if (!itemName.value.trim()) {
        showError('itemNameError');
        markField(itemName, true);
        valid = false;
    } else {
        hideError('itemNameError');
        markField(itemName, false);
    }

    const itemDetails = document.getElementById('itemDetails');
    if (!itemDetails.value.trim()) {
        showError('itemDetailsError');
        markField(itemDetails, true);
        valid = false;
    } else {
        hideError('itemDetailsError');
        markField(itemDetails, false);
    }

    if (!itemPhotoInput.files[0]) {
        showError('itemPhotoError');
        markField(itemPhotoInput, true);
        valid = false;
    } else {
        hideError('itemPhotoError');
        markField(itemPhotoInput, false);
    }

    const category = document.getElementById('categoryId');
    if (!category.value) {
        showError('categoryIdError');
        markField(category, true);
        valid = false;
    } else {
        hideError('categoryIdError');
        markField(category, false);
    }

    if (!valid) {
        e.preventDefault();
    }
});
</script>
<script>
function showMode(mode){
    const viewMode = document.getElementById('viewMode');
    const viewItemsHelper = document.getElementById('viewItemsHelper');
    const quickUpdateHelper = document.getElementById('quickUpdateHelper');
    const viewItemsBtn = document.getElementById('viewItemsBtn');
    const quickUpdateBtn = document.getElementById('quickUpdateBtn');

    if (mode === 'view') {
        viewMode.style.display = 'block';
        viewItemsHelper.style.display = 'block';
        quickUpdateHelper.style.display = 'none';

        viewItemsBtn.classList.add('active');
        quickUpdateBtn.classList.remove('active');

        clearQuickSelection();
    }
}

function showQuickHelper(){
    const viewItemsHelper = document.getElementById('viewItemsHelper');
    const quickUpdateHelper = document.getElementById('quickUpdateHelper');
    const viewItemsBtn = document.getElementById('viewItemsBtn');
    const quickUpdateBtn = document.getElementById('quickUpdateBtn');

    viewItemsHelper.style.display = 'none';
    quickUpdateHelper.style.display = 'block';

    viewItemsBtn.classList.remove('active');
    quickUpdateBtn.classList.add('active');
}
</script>
<script>
function saveQuickUpdate(){
  const data = {
  id: document.getElementById('quickItemId').value,
  quantity: document.getElementById('quickQuantity').value,
  price: document.getElementById('quickPrice').value,
  expiryDate: document.getElementById('quickExpiryDate').value,
  pickupDate: document.getElementById('quickPickupDate').value,
  pickupLocationId: document.getElementById('quickPickupLocation').value,
  listingType: document.getElementById('quickType').value
};

  fetch('quick-update-item.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(data)
  })
  .then(res => res.json())
  .then(res => {
    if(res.success){
      showQuickToast('Updated successfully');
setTimeout(() => {
  location.reload();
}, 900);
    } else {
      showQuickToast('Error: ' + res.message);
    }
  })
  .catch(err => {
    showQuickToast('Something went wrong');
  });
}
</script>
<script>
function handleQuickTypeChange(){
  const type = document.getElementById('quickType').value;
  const priceInput = document.getElementById('quickPrice');

  if (type === 'donate') {
    priceInput.disabled = true;
    priceInput.value = 0;
  } else {
    priceInput.disabled = false;
  }
}
</script>
<script>
function showQuickToast(message){
  const toast = document.getElementById('quickToast');
  toast.textContent = message;
  toast.style.display = 'block';

  setTimeout(() => {
    toast.style.display = 'none';
  }, 2200);
}
</script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const today = new Date().toISOString().split('T')[0];

    // expiry date
    const expiry = document.getElementById('quickExpiryDate');
    if (expiry) expiry.setAttribute('min', today);

    // pickup date
    const pickup = document.getElementById('quickPickupDate');
    if (pickup) pickup.setAttribute('min', today);
});
</script>
<script>
let deleteTargetItemId = '';

function deleteItem(itemId, event){
  event.stopPropagation();
  deleteTargetItemId = itemId;
  document.getElementById('deleteModal').style.display = 'flex';
}

function closeDeleteModal(){
  deleteTargetItemId = '';
  document.getElementById('deleteModal').style.display = 'none';
}

function confirmDeleteItem(){
  if(!deleteTargetItemId) return;

  const formData = new FormData();
  formData.append('formAction', 'deleteItem');
  formData.append('itemId', deleteTargetItemId);

  fetch('', {
    method: 'POST',
    body: formData
  })
  .then(res => res.json())
  .then(data => {
    if(data.success){
      closeDeleteModal();
      showQuickToast('Item deleted successfully');
      setTimeout(() => location.reload(), 700);
    } else {
      alert(data.message || 'Delete failed');
    }
  })
  .catch(() => {
    alert('Something went wrong');
  });
}

function clearQuickSelection(){
    document.querySelectorAll('.quick-item-card').forEach(el => el.classList.remove('selected'));

    const panel = document.getElementById('quickUpdatePanel');
    if (panel) panel.style.display = 'none';

    const viewItemsHelper = document.getElementById('viewItemsHelper');
    const quickUpdateHelper = document.getElementById('quickUpdateHelper');
    const viewItemsBtn = document.getElementById('viewItemsBtn');
    const quickUpdateBtn = document.getElementById('quickUpdateBtn');

    if (viewItemsHelper) viewItemsHelper.style.display = 'block';
    if (quickUpdateHelper) quickUpdateHelper.style.display = 'none';
    if (viewItemsBtn) viewItemsBtn.classList.add('active');
    if (quickUpdateBtn) quickUpdateBtn.classList.remove('active');

    const quickItemId = document.getElementById('quickItemId');
    if (quickItemId) quickItemId.value = '';
}
</script>
</script>
<div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(12,22,45,0.5);z-index:9999;justify-content:center;align-items:center;">
  <div style="background:#fff;border-radius:20px;padding:40px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <svg width="48" height="48" fill="none" stroke="#c0392b" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 16px;display:block;">
      <circle cx="12" cy="12" r="10"/>
      <line x1="12" y1="8" x2="12" y2="12"/>
      <line x1="12" y1="16" x2="12.01" y2="16"/>
    </svg>

    <h3 style="font-size:22px;font-weight:700;color:#1a3a6b;margin-bottom:10px;font-family:'Playfair Display',serif;">
      Delete Item?
    </h3>

    <p style="font-size:14px;color:#7a8fa8;margin-bottom:28px;line-height:1.6;">
      This will permanently delete this item. You cannot undo this.
    </p>

    <div style="display:flex;gap:14px;justify-content:center;">
      <button type="button" onclick="closeDeleteModal()" style="padding:12px 28px;border-radius:50px;border:2px solid #c8d8ee;background:#fff;color:#7a8fa8;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;cursor:pointer;">
        Cancel
      </button>

      <button type="button" onclick="confirmDeleteItem()" style="padding:12px 28px;border-radius:50px;border:none;background:#c0392b;color:#fff;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;cursor:pointer;">
        Yes, Delete
      </button>
    </div>
  </div>
</div>
<script>
function selectQuickItem(card){
    const panel = document.getElementById('quickUpdatePanel');

    // ✅ If same item clicked again → CLOSE
    if (card.classList.contains('selected')) {
        clearQuickSelection();
        return;
    }

    // otherwise → OPEN normally
    showQuickHelper();

    document.getElementById('quickItemId').value = card.getAttribute('data-id') || '';

    document.querySelectorAll('.quick-item-card').forEach(el => el.classList.remove('selected'));
    card.classList.add('selected');

   const today = new Date().toISOString().split('T')[0];

const quantity = card.getAttribute('data-quantity') || '';
const price = card.getAttribute('data-price') || '';
let expiry = card.getAttribute('data-expiry') || '';
let pickupDate = card.getAttribute('data-pickupdate') || '';
const pickupLocation = card.getAttribute('data-pickuplocation') || '';
const type = card.getAttribute('data-type') || 'donate';

if (!expiry || expiry.startsWith('1970-')) {
    expiry = today;
}

if (!pickupDate || pickupDate.startsWith('1970-')) {
    pickupDate = today;
}

document.getElementById('quickQuantity').value = quantity;
document.getElementById('quickPrice').value = price;
document.getElementById('quickExpiryDate').value = expiry;
document.getElementById('quickPickupDate').value = pickupDate;
document.getElementById('quickPickupLocation').value = pickupLocation;
document.getElementById('quickType').value = type;

document.getElementById('quickExpiryDate').min = today;
document.getElementById('quickPickupDate').min = today;

    handleQuickTypeChange();

    card.insertAdjacentElement('afterend', panel);
    panel.style.display = 'block';
    panel.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
</script>
<script>
function clearQuickSelection(){
    document.querySelectorAll('.quick-item-card').forEach(el => el.classList.remove('selected'));

    const panel = document.getElementById('quickUpdatePanel');
    if (panel) panel.style.display = 'none';

    const viewItemsHelper = document.getElementById('viewItemsHelper');
    const quickUpdateHelper = document.getElementById('quickUpdateHelper');
    const viewItemsBtn = document.getElementById('viewItemsBtn');
    const quickUpdateBtn = document.getElementById('quickUpdateBtn');

    if (viewItemsHelper) viewItemsHelper.style.display = 'block';
    if (quickUpdateHelper) quickUpdateHelper.style.display = 'none';
    if (viewItemsBtn) viewItemsBtn.classList.add('active');
    if (quickUpdateBtn) quickUpdateBtn.classList.remove('active');

    const quickItemId = document.getElementById('quickItemId');
    if (quickItemId) quickItemId.value = '';
}
</script>
<script>
function openEditItemModal(btn, event){
  event.stopPropagation();

  // open modal
  document.getElementById('addItemModal').style.display = 'flex';

  // change form mode to edit
  document.getElementById('formAction').value = 'editItem';

  // fill item data
  document.getElementById('editItemId').value = btn.dataset.id;
  document.getElementById('itemName').value = btn.dataset.name;
  document.getElementById('itemDetails').value = btn.dataset.description;
  document.getElementById('categoryId').value = btn.dataset.categoryid;

  // type
  selectTypeCard(btn.dataset.type);

  // price
  document.getElementById('priceInput').value = btn.dataset.price || '';

  // pickup
  document.getElementById('pickupLocationId').value = btn.dataset.pickuplocation || '';
  document.getElementById('pickupDate').value = btn.dataset.pickupdate || '';

  // photo
  document.getElementById('existingPhotoUrl').value = btn.dataset.photo || '';

  // change button text
  document.querySelector('#addItemForm .add-btn').textContent = 'Update Item';

  // change title
  document.querySelector('#addItemModal h1').textContent = 'Edit Item';
}
 <script>
  mobileDebounceTimer = setTimeout(() => {
    fetch(`../../back-end/search.php?q=${encodeURIComponent(q)}`)
      .then(r => r.json())
      .then(data => {
        const items  = data.items  || [];
        const orders = data.orders || [];

        if (!items.length && !orders.length) {
          mobileDD.innerHTML = '<div class="sd-empty">No results found.</div>';
          return;
        }

        let html = '';
       if (items.length) {
  html += `<div class="sd-section-title">Items</div>`;

  items.forEach(item => {
    const thumb = item.photoUrl
      ? `<img src="${esc(item.photoUrl)}" alt="">`
      : `<svg width="20" height="20" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/></svg>`;

    const badgeClass = item.listingType === 'donate'
      ? 'sd-badge-donate'
      : 'sd-badge-sell';

    const badgeLabel = item.listingType === 'donate'
      ? 'Donation'
      : 'Sell';

    const priceText = item.listingType === 'donate' || !item.price || item.price == 0
      ? 'Free'
      : `﷼ ${esc(item.price)}`;

    html += `
      <a class="sd-row" href="provider-item-details.php?id=${esc(item.id)}">
        <div class="sd-thumb">${thumb}</div>

        <div class="sd-info">
          <div class="sd-name">${esc(item.name)}</div>
          <div class="sd-sub">${priceText}</div>
        </div>

        <span class="sd-badge ${badgeClass}">${badgeLabel}</span>
      </a>
    `;
  });
}

              </div>
              <span class="sd-badge ${badgeClass}">${badgeLabel}</span>
            </a>`;
          });
        }
        if (orders.length) {
          html += `<div class="sd-section-title">Orders</div>`;
          orders.forEach(order => {
            const badgeClass = `sd-badge-${order.status}`;
            const statusLabel = order.status.charAt(0).toUpperCase() + order.status.slice(1);
            html += `<a class="sd-row" href="provider-orders.php">
              <div class="sd-thumb">
                <svg width="20" height="20" fill="none" stroke="#2255a4" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
              </div>
              <div class="sd-info">
                <div class="sd-name">Order #${esc(order.orderNumber)}</div>
                <div class="sd-sub">${order.itemName ? esc(order.itemName) + ' · ' : ''}﷼ ${esc(order.total)}</div>
              </div>
              <span class="sd-badge ${badgeClass}">${statusLabel}</span>
            </a>`;
          });
        }
        mobileDD.innerHTML = html;
      })
      .catch(() => {
        mobileDD.innerHTML = '<div class="sd-empty">Something went wrong.</div>';
      });
  }, 300);
});

// Close mobile dropdown on outside click
document.addEventListener('click', (e) => {
  if (!document.getElementById('searchWrap')?.contains(e.target)) closeDropdown();
  const mobileSearch = document.querySelector('.mobile-search');
  const mobileDD = document.getElementById('mobileSearchDropdown');
  if (mobileSearch && !mobileSearch.contains(e.target)) {
    mobileDD?.classList.remove('visible');
  }
});
</script>
<script>
function toggleMobileMenu() {
  const menu = document.getElementById('mobileMenu');
  const btn = document.getElementById('hamburger');

  menu.classList.toggle('open');
  btn.classList.toggle('open');
  document.body.style.overflow = menu.classList.contains('open') ? 'hidden' : '';
}

function closeMobileMenu() {
  document.getElementById('mobileMenu').classList.remove('open');
  document.getElementById('hamburger').classList.remove('open');
  document.body.style.overflow = '';
}
</script>
<script>
function toggleMobileMenu() {
  const menu = document.getElementById('mobileMenu');
  const btn = document.getElementById('hamburger');
  menu.classList.toggle('open');
  btn.classList.toggle('open');
  document.body.style.overflow = menu.classList.contains('open') ? 'hidden' : '';
}

function closeMobileMenu() {
  document.getElementById('mobileMenu').classList.remove('open');
  document.getElementById('hamburger').classList.remove('open');
  document.body.style.overflow = '';
}

let mobileDebounceTimer = null;

document.getElementById('mobileSearchInput')?.addEventListener('input', function () {
  clearTimeout(mobileDebounceTimer);
  const q = this.value.trim();
  const mobileDD = document.getElementById('mobileSearchDropdown');

  if (!mobileDD) return;

  if (q.length < 2) {
    mobileDD.classList.remove('visible');
    mobileDD.innerHTML = '';
    return;
  }

  mobileDD.innerHTML = '<div style="padding:14px;text-align:center;color:#8a9ab5;font-size:13px;">Searching...</div>';
  mobileDD.classList.add('visible');

  mobileDebounceTimer = setTimeout(() => {
    fetch(`../../back-end/search.php?q=${encodeURIComponent(q)}`)
      .then(r => r.json())
      .then(data => {
        const items = data.items || [];
        const providers = data.providers || [];

        if (!items.length && !providers.length) {
          mobileDD.innerHTML = '<div style="padding:14px;text-align:center;color:#8a9ab5;font-size:13px;">No matches found</div>';
          mobileDD.classList.add('visible');
          return;
        }

        let html = '';

        if (providers.length) {
          html += '<div class="sd-section-title">Providers</div>';
          providers.forEach(p => {
            const logo = p.businessLogo
              ? `<div class="sd-icon"><img src="${p.businessLogo}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px;"></div>`
              : `<div class="sd-icon">${(p.businessName || 'P').charAt(0).toUpperCase()}</div>`;

            html += `
              <a class="sd-row" href="provider-dashboard.php" onclick="closeMobileMenu()">
                ${logo}
                <div class="sd-info">
                  <div class="sd-name">${p.businessName || ''}</div>
                  <div class="sd-sub">${p.category || ''}</div>
                </div>
              </a>
            `;
          });
        }

        if (items.length) {
          html += '<div class="sd-section-title">Products</div>';
          items.forEach(item => {
            const thumb = item.photoUrl
              ? `<div class="sd-icon"><img src="${item.photoUrl}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px;"></div>`
              : '<div class="sd-icon">🍱</div>';

            html += `
              <a class="sd-row" href="provider-items.php" onclick="closeMobileMenu()">
                ${thumb}
                <div class="sd-info">
                  <div class="sd-name">${item.name || ''}</div>
                  <div class="sd-sub">Product</div>
                </div>
              </a>
            `;
          });
        }

        mobileDD.innerHTML = html;
        mobileDD.classList.add('visible');
      })
      .catch(() => {
        mobileDD.innerHTML = '<div style="padding:14px;text-align:center;color:#8a9ab5;font-size:13px;">Search unavailable</div>';
        mobileDD.classList.add('visible');
      });
  }, 220);
});

const searchInput = document.getElementById('searchInput');
const searchDropdown = document.getElementById('searchDropdown');
const searchWrap = document.getElementById('searchWrap');
let searchTimer = null;

searchInput?.addEventListener('input', function () {
  clearTimeout(searchTimer);
  const q = this.value.trim();

  if (q.length < 2) {
    searchDropdown?.classList.remove('visible');
    if (searchDropdown) searchDropdown.innerHTML = '';
    return;
  }

  searchDropdown.innerHTML = '<div style="padding:14px;text-align:center;color:#8a9ab5;font-size:13px;">Searching...</div>';
  searchDropdown.classList.add('visible');

  searchTimer = setTimeout(async () => {
    try {
      const res = await fetch(`../../back-end/search.php?q=${encodeURIComponent(q)}`);
      const data = await res.json();

      const items = data.items || [];
      const providers = data.providers || [];
      let html = '';

      if (!items.length && !providers.length) {
        html = '<div style="padding:14px;text-align:center;color:#8a9ab5;font-size:13px;">No matches found</div>';
      } else {
        if (providers.length) {
          html += '<div class="sd-section-title">Providers</div>';
          providers.forEach(p => {
            const logo = p.businessLogo
              ? `<div class="sd-icon"><img src="${p.businessLogo}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px;"></div>`
              : `<div class="sd-icon">${(p.businessName || 'P').charAt(0).toUpperCase()}</div>`;

            html += `
              <a class="sd-row" href="provider-dashboard.php">
                ${logo}
                <div class="sd-info">
                  <div class="sd-name">${p.businessName || ''}</div>
                  <div class="sd-sub">${p.category || ''}</div>
                </div>
              </a>
            `;
          });
        }

        if (items.length) {
          html += '<div class="sd-section-title">Products</div>';
          items.forEach(item => {
            const thumb = item.photoUrl
              ? `<div class="sd-icon"><img src="${item.photoUrl}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:10px;"></div>`
              : '<div class="sd-icon">🍱</div>';

            html += `
              <a class="sd-row" href="provider-items.php">
                ${thumb}
                <div class="sd-info">
                  <div class="sd-name">${item.name || ''}</div>
                  <div class="sd-sub">Product</div>
                </div>
              </a>
            `;
          });
        }
      }

      searchDropdown.innerHTML = html;
      searchDropdown.classList.add('visible');
    } catch (e) {
      searchDropdown.innerHTML = '<div style="padding:14px;text-align:center;color:#8a9ab5;font-size:13px;">Something went wrong.</div>';
      searchDropdown.classList.add('visible');
    }
  }, 280);
});

searchInput?.addEventListener('keydown', e => {
  if (e.key === 'Escape') searchDropdown?.classList.remove('visible');
});

document.addEventListener('click', e => {
  if (searchWrap && !searchWrap.contains(e.target)) {
    searchDropdown?.classList.remove('visible');
  }

  const mobileMenu = document.getElementById('mobileMenu');
  const mobileSearchInput = document.getElementById('mobileSearchInput');
  const mobileSearchDropdown = document.getElementById('mobileSearchDropdown');

  if (
    mobileMenu &&
    mobileSearchDropdown &&
    mobileMenu.classList.contains('open') &&
    !mobileSearchInput?.contains(e.target) &&
    !mobileSearchDropdown.contains(e.target)
  ) {
    mobileSearchDropdown.classList.remove('visible');
  }
});
</script>
</body>
</html>
  
