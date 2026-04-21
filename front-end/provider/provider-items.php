<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../vendor/autoload.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Category.php';
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/PickupLocation.php';

use Cloudinary\Configuration\Configuration;
use Cloudinary\Api\Upload\UploadApi;

Configuration::instance([
    'cloud' => [
        'cloud_name' => 'dwsafdzwr',
        'api_key'    => '553757457562639',
        'api_secret' => 'yejCp7rI1mCq-4cpw-lBmbyD-iA'
    ],
    'url' => ['secure' => true]
]);

session_start();

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

$itemModel     = new Item();
$categoryModel = new Category();
$categoryModel->seed();
$providerModel = new Provider();
$locationModel = new PickupLocation();

// ── AJAX: get item details ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'get_item' && !empty($_GET['id'])) {
    header('Content-Type: application/json');
    try {
        $item = $itemModel->findById($_GET['id']);
        if (!$item || (string)$item['providerId'] !== $providerId) {
            echo json_encode(['success' => false]); exit;
        }
        $catName = 'Unknown';
        if (!empty($item['categoryId'])) {
            $cat = $categoryModel->findById((string)$item['categoryId']);
            if ($cat) $catName = $cat['name'] ?? 'Unknown';
        }
        // ── Helper: extract locData from a location record ──
        $buildLocData = function(?array $loc): array {
            if (!$loc) return [];
            $lName   = $loc['label']  ?? ($loc['locationName'] ?? '');
            $lStreet = $loc['street'] ?? '';
            $lCity   = $loc['city']   ?? '';
            $lat     = $loc['lat'] ?? ($loc['coordinates']['lat'] ?? null);
            $lng     = $loc['lng'] ?? ($loc['coordinates']['lng'] ?? null);
            return [
                'name'        => $lName,
                'street'      => $lStreet,
                'city'        => $lCity,
                'fullAddress' => trim(implode(', ', array_filter([$lName, $lStreet, $lCity]))),
                'lat'         => $lat,
                'lng'         => $lng,
            ];
        };

        $locData = [];

        // 1. Try the location the item was assigned to
        if (!empty($item['pickupLocationId'])) {
            $loc = $locationModel->findById((string)$item['pickupLocationId']);
            $locData = $buildLocData($loc);
        }

        // 2. If that's missing/stale, try the provider's default location
        if (empty($locData)) {
            $locData = $buildLocData($locationModel->getDefault($providerId));
        }

        // 3. Last resort: just use whichever location exists first for this provider
        if (empty($locData)) {
            $allLocs = $locationModel->getByProvider($providerId);
            if (!empty($allLocs)) {
                $locData = $buildLocData((array)$allLocs[0]);
            }
        }
        echo json_encode(['success' => true, 'item' => [
            'id'               => (string)$item['_id'],
            'name'             => $item['itemName']    ?? '',
            'description'      => $item['description'] ?? '',
            'type'             => $item['listingType'] ?? 'donate',
            'price'            => (float)($item['price'] ?? 0),
            'category'         => $catName,
            'categoryId'       => !empty($item['categoryId'])       ? (string)$item['categoryId']       : '',
            'pickupLocationId' => !empty($item['pickupLocationId']) ? (string)$item['pickupLocationId'] : '',
            'quantity'         => (int)($item['quantity'] ?? 1),
            'photoUrl'         => $item['photoUrl'] ?? '',
            'expiryDate'       => !empty($item['expiryDate']) ? $item['expiryDate']->toDateTime()->format('d/m/Y') : '',
            'expiryDateRaw'    => !empty($item['expiryDate']) ? $item['expiryDate']->toDateTime()->format('Y-m-d') : date('Y-m-d'),
            'pickupDate'       => !empty($item['pickupDate'])  ? $item['pickupDate']->toDateTime()->format('d/m/Y')  : '',
            'pickupDateRaw'    => !empty($item['pickupDate'])  ? $item['pickupDate']->toDateTime()->format('Y-m-d')  : date('Y-m-d'),
            'pickupTimes'      => $item['pickupTimes'] ?? [],
            'isAvailable'      => (bool)($item['isAvailable'] ?? false),
            'location'         => $locData,
        ]]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── POST: edit existing item ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_item') {
    $editItemId = trim($_POST['editItemId'] ?? '');
    $editError  = '';
    $photoUrl   = trim($_POST['existingPhotoUrl'] ?? '');

    if (!empty($_FILES['editItemPhoto']['name']) && $_FILES['editItemPhoto']['error'] === UPLOAD_ERR_OK) {
        $fileType = mime_content_type($_FILES['editItemPhoto']['tmp_name']);
        if (in_array($fileType, ['image/jpeg','image/png','image/gif','image/webp'], true)) {
            try {
                $res = (new UploadApi())->upload($_FILES['editItemPhoto']['tmp_name'], ['folder' => 'replate/items']);
                $photoUrl = $res['secure_url'] ?? $photoUrl;
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                $editError = (stripos($msg,'too large')!==false||stripos($msg,'exceeds')!==false||stripos($msg,'size')!==false)
                    ? 'Image is too large. Please lower the resolution or compress before uploading.'
                    : 'Image upload failed: ' . $msg;
            }
        } else {
            $editError = 'Invalid image type. Allowed: jpg, jpeg, png, gif, webp.';
        }
    }

    if (!$editError && $editItemId) {
        try {
            $editType = $_POST['editItemType'] ?? 'donate';
            $editQty  = max(1, (int)($_POST['editQuantity'] ?? 1));
            $editExpiryRaw  = $_POST['editExpiryDate']  ?? date('Y-m-d');
            $editPickupRaw  = $_POST['editPickupDate']  ?? date('Y-m-d');
            $editPickupTimes = array_values(array_filter(array_map('trim', $_POST['editPickupTimes'] ?? [])));

            $itemModel->updateById($editItemId, [
                'itemName'         => trim($_POST['editItemName']    ?? ''),
                'description'      => trim($_POST['editItemDetails'] ?? ''),
                'listingType'      => $editType,
                'price'            => ($editType === 'sell') ? (float)($_POST['editPrice'] ?? 0) : 0,
                'categoryId'       => new MongoDB\BSON\ObjectId($_POST['editCategoryId'] ?? ''),
                'pickupLocationId' => new MongoDB\BSON\ObjectId($_POST['editPickupLocationId'] ?? ''),
                'quantity'         => $editQty,
                'expiryDate'       => new MongoDB\BSON\UTCDateTime(strtotime($editExpiryRaw) * 1000),
                'pickupDate'       => new MongoDB\BSON\UTCDateTime(strtotime($editPickupRaw) * 1000),
                'pickupTimes'      => $editPickupTimes,
                'photoUrl'         => $photoUrl,
                'isAvailable'      => $editQty > 0,
            ]);
            header('Location: provider-items.php?tab=' . urlencode($_GET['tab'] ?? 'all') . '&success=edited');
            exit;
        } catch (Exception $e) { $editError = $e->getMessage(); }
    }
    // On error fall through to render page with $editError set
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_item') {
    header('Content-Type: application/json');
    try {
        $itemId = $_POST['itemId'] ?? '';
        $itemModel->deleteById($itemId);
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$tab = $_GET['tab'] ?? 'all';

$categories = $categoryModel->getAll();

$itemFilter = [];
if ($tab === 'sell')   $itemFilter['listingType'] = 'sell';
elseif ($tab === 'donate') $itemFilter['listingType'] = 'donate';

$items = $itemModel->getByProvider($providerId, $itemFilter);
usort($items, fn($a,$b) => strcmp((string)$b['_id'], (string)$a['_id']));

$formError = '';
$editError  = $editError ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($_POST['action'] ?? '', ['delete_item','edit_item'])) {
    $photoUrl = '';
    if (!empty($_FILES['itemPhoto']['name']) && $_FILES['itemPhoto']['error'] === UPLOAD_ERR_OK) {
        $fileType = mime_content_type($_FILES['itemPhoto']['tmp_name']);
        if (in_array($fileType, ['image/jpeg','image/png','image/gif','image/webp'], true)) {
            try {
                $res = (new UploadApi())->upload($_FILES['itemPhoto']['tmp_name'], ['folder' => 'replate/items']);
                $photoUrl = $res['secure_url'] ?? '';
            } catch (Throwable $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'too large') !== false || stripos($msg, 'exceeds') !== false || stripos($msg, 'bytes') !== false || stripos($msg, 'size') !== false) {
                    $formError = 'Image is too large. Please lower the resolution or compress the image before uploading.';
                } else {
                    $formError = 'Image upload failed: ' . $msg;
                }
            }
        } else { $formError = 'Invalid image type. Allowed: jpg, jpeg, png, gif, webp.'; }
    }
    if (!$formError) {
        try {
            $itemModel->create($providerId, [
                'categoryId'       => $_POST['categoryId']       ?? '',
                'pickupLocationId' => $_POST['pickupLocationId'] ?? '',
                'itemName'         => trim($_POST['itemName']    ?? ''),
                'description'      => trim($_POST['itemDetails'] ?? ''),
                'photoUrl'         => $photoUrl,
                'listingType'      => $_POST['itemType']         ?? '',
                'price'            => (($_POST['itemType'] ?? '') === 'sell') ? (float)($_POST['price'] ?? 0) : 0,
                'quantity'         => (int)($_POST['quantity']   ?? 1),
                'expiryDate'       => $_POST['expiryDate']       ?? '',
                'pickupDate'       => $_POST['pickupDate']        ?? '',
                'pickupTimes'      => $_POST['pickupTimes']       ?? [],
            ]);
            header('Location: provider-items.php?tab=' . urlencode($_POST['itemType'] ?? 'all') . '&success=added');
            exit;
        } catch (Exception $e) { $formError = $e->getMessage(); }
    }
}

$provider        = $providerModel->findById($providerId);
$locations       = $locationModel->getByProvider($providerId);
$defaultLocation = null;
foreach ($locations as $loc) {
    if (!empty($loc['isDefault']) || !empty($loc['isMain'])) { $defaultLocation = $loc; break; }
}
if (!$defaultLocation && !empty($locations)) $defaultLocation = $locations[0];

$providerName  = $provider['businessName'] ?? 'Provider';
$providerEmail = $provider['email']        ?? '';
$providerLogo  = $provider['businessLogo'] ?? '';
$firstName     = explode(' ', $providerName)[0];
$today         = date('Y-m-d');
$timeSlots     = ['8:00 AM','9:00 AM','10:00 AM','11:00 AM','12:00 PM','1:00 PM','2:00 PM','3:00 PM','4:00 PM','5:00 PM','6:00 PM','7:00 PM','8:00 PM'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RePlate – My Items</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Playfair Display', serif; background: #f4f7fc; min-height: 100vh; display: flex; flex-direction: column; color: #183482; }

/* ── NAVBAR ── */
nav.navbar { display: flex; align-items: center; justify-content: space-between; padding: 0 40px; height: 72px; background: linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%); position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 16px rgba(26,58,107,.18); }
.nav-left { display: flex; align-items: center; }
.nav-logo  { height: 90px; }
.nav-right { display: flex; align-items: center; gap: 14px; }

/* ── SEARCH ── */
.nav-search-wrap { position: relative; }
.nav-search-wrap svg.search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); opacity: .6; pointer-events: none; }
.nav-search-wrap input { background: rgba(255,255,255,.15); border: 1.5px solid rgba(255,255,255,.4); border-radius: 50px; padding: 10px 18px 10px 40px; color: #fff; font-size: 14px; outline: none; width: 260px; font-family: 'Playfair Display', serif; transition: width .3s, background .2s; }
.nav-search-wrap input::placeholder { color: rgba(255,255,255,.6); }
.nav-search-wrap input:focus { width: 340px; background: rgba(255,255,255,.25); }
.search-dropdown { display: none; position: absolute; top: calc(100% + 10px); left: 0; width: 420px; background: #fff; border-radius: 18px; border: 1.5px solid #e0eaf5; box-shadow: 0 12px 40px rgba(26,58,107,.18); z-index: 9999; overflow: hidden; }
.search-dropdown.visible { display: block; }
.sd-section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: #8a9ab5; padding: 12px 16px 6px; border-bottom: 1px solid #f0f5fc; }
.sd-row { display: flex; align-items: center; gap: 12px; padding: 10px 16px; text-decoration: none; color: inherit; transition: background .15s; cursor: pointer; }
.sd-row:hover { background: #f4f8ff; }
.sd-thumb { width: 42px; height: 42px; border-radius: 10px; border: 1.5px solid #e0eaf5; background: #f0f5ff; overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }
.sd-thumb img { width: 100%; height: 100%; object-fit: cover; }
.sd-info { flex: 1; min-width: 0; }
.sd-name { font-size: 14px; font-weight: 700; color: #1a3a6b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.sd-sub  { font-size: 12px; color: #8a9ab5; margin-top: 2px; }
.sd-badge { border-radius: 50px; padding: 2px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; flex-shrink: 0; }
.sd-badge-sell     { background: #fff4e6; color: #e07a1a; }
.sd-badge-donate   { background: #e8f7ee; color: #1a6b3a; }
.sd-badge-pending  { background: #fff4e6; color: #e07a1a; }
.sd-badge-completed{ background: #e8f7ee; color: #1a6b3a; }
.sd-badge-cancelled{ background: #fde8e8; color: #c0392b; }
.sd-empty   { padding: 18px 16px; text-align: center; color: #b0c4d8; font-size: 13px; }
.sd-loading { padding: 16px; text-align: center; color: #8a9ab5; font-size: 13px; }

.nav-provider-info { display: flex; align-items: center; gap: 14px; }
.nav-provider-logo { width: 46px; height: 46px; border-radius: 50%; border: 2px solid rgba(255,255,255,.6); background: rgba(255,255,255,.15); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #fff; overflow: hidden; flex-shrink: 0; }
.nav-provider-logo img { width: 100%; height: 100%; object-fit: cover; }
.nav-provider-text { display: flex; flex-direction: column; }
.nav-provider-name { font-size: 15px; font-weight: 700; color: #fff; }
.nav-provider-email { font-size: 12px; color: rgba(255,255,255,.75); }

/* ── LAYOUT ── */
.page-body { display: flex; flex: 1; }
.main { flex: 1; padding: 24px 28px 24px 20px; overflow-y: auto; }

/* ── SIDEBAR ── */
.sidebar { width: 240px; min-height: calc(100vh - 72px); background: linear-gradient(180deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%); display: flex; flex-direction: column; padding: 34px 24px 28px; flex-shrink: 0; }
.sidebar-welcome { color: rgba(255,255,255,.78); font-size: 17px; margin-bottom: 4px; }
.sidebar-name    { color: rgba(255,255,255,.62); font-size: 38px; font-weight: 700; line-height: 1.1; margin-bottom: 34px; }
.sidebar-nav     { display: flex; flex-direction: column; gap: 16px; flex: 1; }
.sidebar-link { display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,.78); text-decoration: none; font-size: 16px; padding: 10px 8px; transition: .2s; }
.sidebar-link:hover { color: #fff; }
.sidebar-link.active { color: #fff; font-weight: 700; border-bottom: 2px solid rgba(255,255,255,.55); padding-bottom: 6px; }
.sidebar-link svg { flex-shrink: 0; }
.sidebar-logout { margin-top: 22px; background: #fff; color: #1a3a6b; border: none; border-radius: 999px; padding: 12px 0; font-size: 15px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; width: 100%; }
.sidebar-footer { margin-top: 24px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,.14); display: flex; flex-direction: column; gap: 10px; align-items: center; }
.sidebar-footer-social { display: flex; align-items: center; justify-content: center; gap: 8px; }
.sidebar-social-icon { width: 28px; height: 28px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,.35); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.82); font-size: 11px; font-weight: 700; text-decoration: none; }
.sidebar-footer-copy { color: rgba(255,255,255,.45); font-size: 10px; display: flex; align-items: center; justify-content: center; gap: 4px; flex-wrap: wrap; }

/* ── PAGE WRAP ── */
.items-page-wrap { max-width: 1100px; margin: 0 auto; }
.page-header       { margin-bottom: 18px; text-align: center; }
.page-header h1 { font-size: 34px; font-weight: 700; background: linear-gradient(90deg,#143496 0%,#66a1d9 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; display: inline-block; }

/* ── HEADER BAR ── */
.items-header-bar { display: flex; align-items: center; justify-content: space-between; gap: 16px; margin-bottom: 22px; flex-wrap: wrap; }
.add-item-open-btn { background: #f6811f; color: #fff; border: none; border-radius: 999px; padding: 12px 26px; font-size: 17px; font-family: 'Playfair Display', serif; font-weight: 700; cursor: pointer; transition: .2s; box-shadow: 0 4px 14px rgba(246,129,31,.18); }
.add-item-open-btn:hover { background: #df7413; transform: translateY(-1px); }

/* ── FILTER TABS ── */
.seg-btn { min-width: 140px; padding: 10px 20px; border-radius: 18px; border: 1.8px solid #ea8b2c; background: #fff; color: #183482; font-size: 16px; font-family: 'Playfair Display', serif; text-decoration: none; text-align: center; display: inline-block; transition: .2s; }
.seg-btn.active  { background: #f6811f; color: #fff; border-color: #f6811f; }
.seg-btn:not(.active):hover { background: #fff8f2; }

/* ── 2x2 GRID ── */
.items-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 14px; }

/* ── ITEM CARD ── */
.item-grid-card { background: #f2f4f8; border: 1.5px solid #c8d8ee; border-radius: 20px; overflow: hidden; text-decoration: none; color: inherit; display: flex; flex-direction: row; transition: box-shadow .2s, transform .2s, border-color .2s; cursor: pointer; box-shadow: 0 2px 14px rgba(26,58,107,.07); min-height: 130px; position: relative; }
.item-grid-card:hover { box-shadow: 0 8px 28px rgba(26,58,107,.13); transform: translateY(-3px); border-color: #ea8b2c; }
.item-card-photo { width: 130px; flex-shrink: 0; background: #d8e6f5; overflow: hidden; }
.item-card-photo img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .3s; }
.item-grid-card:hover .item-card-photo img { transform: scale(1.05); }
.item-card-photo-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #a0b4cc; font-size: 13px; }
.item-card-body { flex: 1; padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; overflow: hidden; min-width: 0; }
.item-card-top-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.item-category-tag { display: inline-block; background: #e8f0ff; color: #2255a4; font-size: 10px; font-weight: 700; border-radius: 50px; padding: 2px 9px; letter-spacing: .05em; text-transform: uppercase; width: fit-content; }
.item-card-divider { width: 100%; height: 1px; background: #c0d2e8; margin: 3px 0; }
.item-card-name { font-size: 16px; font-weight: 700; color: #1a3a6b; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.item-card-price { font-size: 13px; font-weight: 700; color: #e07a1a; }
.item-card-price.donate-price { color: #1a6b3a; }
.item-card-desc { font-size: 12px; color: #4a6a9a; line-height: 1.4; overflow: hidden; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; font-family: 'Playfair Display', serif; }
.badge-donate      { background: #edfbf3; color: #1a7a45; border: 1px solid #b0e6c8; }
.badge-sell        { background: #fff7ed; color: #c06a10; border: 1px solid #f3c999; }
.badge-unavailable { background: #fef2f2; color: #b42318; border: 1px solid #fca5a5; }
.badge-available   { background: #edfbf3; color: #1a7a45; border: 1px solid #b0e6c8; }
.badge-outofstock  { background: #fef2f2; color: #b42318; border: 1px solid #fca5a5; }
.badge-expiry-red  { background: #fef2f2; color: #b42318; border: 1px solid #fca5a5; }
.badge-expiry-orange { background: #fff7ed; color: #c06a10; border: 1px solid #f3c999; }

/* ── CARD ACTION BUTTONS ── */
.card-actions { position: absolute; bottom: 10px; right: 10px; display: flex; gap: 6px; }
.card-icon-btn { width: 32px; height: 32px; border-radius: 50%; border: 1.5px solid #d7e1ee; background: #fff; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: .2s; flex-shrink: 0; box-shadow: 0 2px 8px rgba(26,58,107,.12); }
.card-icon-btn:hover { transform: scale(1.12); }
.card-icon-btn.edit-btn:hover { border-color: #ea8b2c; background: #fff9f3; }
.card-icon-btn.del-btn:hover  { border-color: #e74c3c; background: #fef2f2; }

/* ── EMPTY STATE ── */
.empty-state { text-align: center; padding: 60px 12px; color: #6d7da0; font-size: 20px; grid-column: 1 / -1; }

/* ── MODAL OVERLAYS ── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(12,22,45,.45); z-index: 9999; justify-content: center; align-items: center; padding: 20px; }
.modal-overlay.open { display: flex; }
.modal-box { background: #f7fbff; border-radius: 26px; border: 1.5px solid #cfdbea; padding: 36px 32px; max-width: 760px; width: 100%; max-height: 90vh; overflow-y: auto; box-shadow: 0 20px 60px rgba(26,58,107,.18); position: relative; }
.modal-box.detail-box { max-width: 520px; padding: 0; overflow: hidden; display: flex; flex-direction: column; }
.close-modal-btn { position: absolute; top: 14px; right: 14px; background: rgba(255,255,255,0.9); border: 1.5px solid #d7e1ee; color: #8aa3c0; font-size: 22px; font-weight: 700; cursor: pointer; line-height: 1; z-index: 10; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 8px rgba(26,58,107,.1); transition: .2s; }
.close-modal-btn:hover { background: #fff; color: #e74c3c; border-color: #e74c3c; }
.modal-title { font-size: 34px; font-weight: 700; font-family: 'Playfair Display', serif; background: linear-gradient(90deg,#143496 0%,#66a1d9 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; text-align: center; margin-bottom: 28px; }

/* ── ITEM DETAIL MODAL ── */
.modal-box.detail-box { max-width: 520px; padding: 0; overflow: hidden; display: flex; flex-direction: column; max-height: 88vh; }
.detail-scroll { overflow-y: auto; flex: 1; min-height: 0; display: flex; flex-direction: column; }
.detail-top { display: flex; flex-direction: column; align-items: center; padding: 28px 24px 18px; border-bottom: 1px solid #e8f0f8; gap: 14px; }
.detail-thumb { width: 160px; height: 160px; border-radius: 20px; background: #d8e6f5; overflow: hidden; flex-shrink: 0; }
.detail-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.detail-thumb-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #8aa3c0; font-size: 11px; text-align: center; }
.detail-top-info { flex: 1; min-width: 0; display: flex; flex-direction: column; align-items: center; gap: 6px; width: 100%; }
.detail-title { font-size: 26px; font-weight: 700; color: #1a3a6b; line-height: 1.2; text-align: center; }
.detail-top-badges { display: flex; gap: 6px; flex-wrap: wrap; justify-content: center; }
.detail-price { font-size: 17px; font-weight: 700; color: #e07a1a; }
.detail-category { display: flex; align-items: center; gap: 5px; justify-content: center; }
.detail-body { padding: 14px 24px 16px; display: flex; flex-direction: column; gap: 12px; }
.detail-grid { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; background: #f6faff; border-radius: 12px; padding: 12px 14px; }
.detail-field { display: flex; flex-direction: column; gap: 3px; }
.detail-field label { font-size: 10px; font-weight: 700; color: #8aa3c0; text-transform: uppercase; letter-spacing: .06em; }
.detail-field span { font-size: 13px; color: #243a5e; font-weight: 600; }
.detail-desc-block { background: #eef3fb; border-radius: 12px; padding: 12px 14px; }
.detail-desc-block label { font-size: 10px; font-weight: 700; color: #8aa3c0; text-transform: uppercase; letter-spacing: .06em; display: block; margin-bottom: 5px; }
.detail-desc-block p { font-size: 13px; color: #243a5e; line-height: 1.6; }
.detail-times-wrap { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 4px; }
.detail-time-chip { background: #fff; border: 1px solid #d7e1ee; color: #243a5e; border-radius: 999px; padding: 4px 11px; font-size: 12px; }
.detail-actions { display: flex; gap: 12px; padding: 14px 24px 20px; justify-content: center; border-top: 1px solid #e8f0f8; flex-shrink: 0; }
.detail-edit-btn { background: #e07a1a; color: #fff; border: none; border-radius: 40px; padding: 10px 24px; font-size: 14px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; transition: .2s; }
.detail-edit-btn:hover { background: #c96a10; }
.detail-del-btn { background: transparent; color: #e74c3c; border: 2px solid #e74c3c; border-radius: 40px; padding: 10px 24px; font-size: 14px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; transition: .2s; }
.detail-del-btn:hover { background: #e74c3c; color: #fff; }
/* Map accordion */
.map-accordion { border: 1.5px solid #d7e8f5; border-radius: 14px; overflow: hidden; }
.map-accordion-header { display: flex; align-items: center; justify-content: space-between; padding: 11px 14px; background: #f0f6ff; cursor: pointer; user-select: none; }
.map-accordion-header:hover { background: #e4f0ff; }
.map-accordion-title { display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 700; color: #1a3a6b; }
.map-accordion-addr { font-size: 12px; color: #6a84a8; font-weight: 400; margin-top: 1px; }
.map-accordion-chevron { transition: transform .25s; color: #6a84a8; flex-shrink: 0; }
.map-accordion-chevron.open { transform: rotate(180deg); }
.map-accordion-body { display: none; }
.map-accordion-body.open { display: block; }
.map-accordion-body iframe { width: 100%; height: 200px; border: none; display: block; }

/* ── DELETE CONFIRM MODAL ── */
.confirm-box { background: #fff; border-radius: 20px; padding: 40px; max-width: 400px; width: 90%; text-align: center; box-shadow: 0 20px 60px rgba(0,0,0,.2); position: relative; }
.confirm-icon { width: 56px; height: 56px; border-radius: 50%; background: #fde8e8; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; }
.confirm-title { font-size: 22px; font-weight: 700; color: #1a3a6b; margin-bottom: 8px; font-family: 'Playfair Display', serif; }
.confirm-sub { font-size: 14px; color: #7a8fa8; margin-bottom: 28px; line-height: 1.6; }
.confirm-btns { display: flex; gap: 14px; justify-content: center; }
.confirm-cancel { padding: 12px 28px; border-radius: 50px; border: 2px solid #c8d8ee; background: #fff; color: #7a8fa8; font-size: 15px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; }
.confirm-delete { padding: 12px 28px; border-radius: 50px; border: none; background: #e74c3c; color: #fff; font-size: 15px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; }

/* ── TOAST ── */
.toast { display: none; position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%); background: #c0392b; color: #fff; padding: 14px 28px; border-radius: 14px; box-shadow: 0 10px 28px rgba(0,0,0,.18); z-index: 99999; font-size: 15px; font-family: 'Playfair Display', serif; font-weight: 600; max-width: 420px; width: max-content; line-height: 1.5; text-align: center; }
.toast.show { display: block; animation: fadeInUp .3s ease; }
@keyframes fadeInUp { from { opacity:0; transform: translateX(-50%) translateY(10px); } to { opacity:1; transform: translateX(-50%) translateY(0); } }

/* ── FORM ── */
.form-error-banner { margin-bottom: 16px; padding: 12px 16px; border-radius: 12px; background: #ffe7e7; border: 1px solid #f3b3b3; color: #b42318; font-size: 14px; }
.add-item-form { display: flex; flex-direction: column; gap: 20px; }
.form-group { display: flex; flex-direction: column; gap: 6px; }
.form-group label { font-size: 14px; font-weight: 700; color: #4166ad; letter-spacing: .01em; display: flex; align-items: center; gap: 7px; }
.form-group label svg { flex-shrink: 0; opacity: .75; }
.req { color: #e07a1a; }
.form-grid.two-cols { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
.form-input, .form-select, .form-textarea, .form-date {
  width: 100%; padding: 11px 15px; border: 1.5px solid #cfdbea; border-radius: 14px;
  font-family: 'Playfair Display', serif; font-size: 14px; color: #183482;
  background: #fff; outline: none; transition: border-color .2s;
  height: 46px;
}
.form-textarea { min-height: 90px; height: auto; resize: vertical; }
.form-input:focus, .form-select:focus, .form-textarea:focus, .form-date:focus { border-color: #ea8b2c; }
.form-input.error, .form-select.error, .form-textarea.error, .form-date.error { border-color: #e74c3c !important; background: #fff8f8; }
.form-group label.error-label { color: #e74c3c; }
.field-error { color: #d64545; font-size: 13px; margin-top: 4px; display: none; }
.field-error.show { display: block; }
.type-cards { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.type-card { border: 2px solid #d7e1ee; border-radius: 16px; padding: 16px 18px; cursor: pointer; transition: border-color .2s, background .2s; background: #fff; text-align: center; }
.type-card.active { border-color: #ea8b2c; background: #fff9f3; }
.type-card.error  { border-color: #e74c3c; background: #fff8f8; }
.type-card-title { font-size: 17px; font-weight: 700; color: #183482; margin-bottom: 4px; }
.type-card-sub   { font-size: 13px; color: #7a8fa8; }
#priceWrap { display: none; }
.slot-btn { padding: 8px 14px; border-radius: 999px; border: 1.5px solid #c8d8ee; background: #fff; color: #183482; font-family: 'Playfair Display', serif; font-size: 14px; cursor: pointer; transition: .15s; }
.slot-btn.selected { background: #183482; color: #fff; border-color: #183482; }
.slot-btn:hover:not(.selected) { border-color: #ea8b2c; }
.small-time-select { width: 100px; height: 40px; border: 1.5px solid #cfdbea; border-radius: 999px; padding: 0 12px; font-family: 'Playfair Display', serif; font-size: 14px; color: #183482; background: #fff; outline: none; cursor: pointer; }
.add-time-btn { border: none; background: #ea8b2c; color: #fff; border-radius: 999px; padding: 10px 18px; font-family: 'Playfair Display', serif; font-size: 14px; cursor: pointer; }
.add-time-btn:hover { background: #d87917; }
.time-chip { display: inline-flex; align-items: center; gap: 6px; background: #eef3fb; border: 1px solid #c5d7ee; color: #243a5e; border-radius: 999px; padding: 6px 12px; font-size: 13px; }
.submit-row { display: flex; justify-content: center; padding-top: 6px; }
.add-btn { background: #183482; color: #fff; border: none; border-radius: 999px; padding: 14px 48px; font-size: 16px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; transition: .2s; }
.add-btn:hover { background: #0e2260; transform: translateY(-1px); }

/* ── HAMBURGER + MOBILE MENU ── */
.hamburger { display: none; flex-direction: column; gap: 5px; cursor: pointer; background: none; border: none; padding: 6px; }
.hamburger span { display: block; width: 24px; height: 2.5px; background: #fff; border-radius: 2px; transition: all .3s; }
.hamburger.open span:nth-child(1) { transform: translateY(7.5px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity: 0; }
.hamburger.open span:nth-child(3) { transform: translateY(-7.5px) rotate(-45deg); }
.mobile-menu { display: none; position: fixed; inset: 0; top: 72px; background: linear-gradient(180deg,#1a3a6b 0%,#2255a4 100%); z-index: 99; flex-direction: column; padding: 24px 20px; }
.mobile-menu.open { display: flex; }
.mobile-menu a { color: rgba(255,255,255,.9); font-size: 22px; font-weight: 700; font-family: 'Playfair Display', serif; padding: 18px 0; border-bottom: 1px solid rgba(255,255,255,.12); text-decoration: none; }
.mobile-menu a:hover { color: #fff; }
.mobile-search { margin-top: 22px; position: relative; }
.mobile-search svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); opacity: .6; pointer-events: none; }
.mobile-search input { width: 100%; background: rgba(255,255,255,.15); border: 1.5px solid rgba(255,255,255,.4); border-radius: 50px; padding: 12px 16px 12px 40px; color: #fff; font-size: 15px; outline: none; font-family: 'Playfair Display', serif; }
.mobile-search input::placeholder { color: rgba(255,255,255,.6); }
.mobile-search-dropdown { display: none; background: #fff; border-radius: 14px; border: 1.5px solid #e0eaf5; box-shadow: 0 8px 32px rgba(26,58,107,.18); margin-top: 8px; overflow: hidden; max-height: 320px; overflow-y: auto; }
.mobile-search-dropdown.visible { display: block; }

/* ── RESPONSIVE ── */
@media (max-width: 768px) {
  nav.navbar { padding: 0 18px; }
  .nav-logo { height: 72px; }
  .nav-right { gap: 10px; flex: 1; justify-content: flex-end; }
  .nav-provider-text { display: none; }
  .nav-provider-logo { width: 40px; height: 40px; }
  .nav-search-wrap { display: none; }
  .hamburger { display: flex; }
  .sidebar { display: none; }
  .main { padding: 20px 16px; }
  .items-grid { grid-template-columns: 1fr; }
  .item-card-photo { width: 110px; }
  .item-card-name { font-size: 14px; }
  .form-grid.two-cols { grid-template-columns: 1fr; }
  .type-cards { grid-template-columns: 1fr; }
  .items-header-bar { flex-direction: column; align-items: stretch; gap: 10px; }
  .items-header-bar > div { display: flex; gap: 8px; width: 100%; }
  .seg-btn { min-width: 0; flex: 1; padding: 10px 8px; font-size: 14px; border-radius: 14px; }
  .add-item-open-btn { width: 100%; }
  .badge { font-size: 9px; padding: 2px 7px; }
  .detail-header { flex-direction: column; }
  .detail-photo { width: 100%; height: 200px; }
  .detail-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<nav class="navbar">
  <div class="nav-left">
    <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
  </div>
  <div class="nav-right">
    <div class="nav-search-wrap" id="searchWrap">
      <svg class="search-icon" width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
      </svg>
      <input type="text" id="searchInput" placeholder="Search items ..." autocomplete="off"/>
      <div class="search-dropdown" id="searchDropdown"></div>
    </div>
    <div class="nav-provider-info">
      <div class="nav-provider-logo">
        <?php if ($providerLogo): ?>
          <img src="<?= htmlspecialchars($providerLogo) ?>" alt=""/>
        <?php else: ?>
          <?= mb_strtoupper(mb_substr($providerName, 0, 1)) ?>
        <?php endif; ?>
      </div>
    </div>
    <button id="hamburger" class="hamburger" onclick="toggleMobileMenu()" aria-label="Open menu">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>

<div class="mobile-menu" id="mobileMenu">
  <a href="provider-dashboard.php" onclick="closeMobileMenu()">Dashboard</a>
  <a href="provider-items.php"     onclick="closeMobileMenu()" style="color:#fff;">Items</a>
  <a href="provider-orders.php"    onclick="closeMobileMenu()">Orders</a>
  <a href="provider-profile.php"   onclick="closeMobileMenu()">Profile</a>
  <a href="provider-dashboard.php?logout=1" onclick="closeMobileMenu()">Log out</a>
  <div class="mobile-search">
    <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>
    </svg>
    <input type="text" id="mobileSearchInput" placeholder="Search items ..." autocomplete="off"/>
    <div class="mobile-search-dropdown" id="mobileSearchDropdown"></div>
  </div>
</div>

<div class="page-body">
  <aside class="sidebar">
    <p class="sidebar-welcome">Welcome Back ,</p>
    <p class="sidebar-name"><?= htmlspecialchars($firstName) ?></p>
    <nav class="sidebar-nav">
      <a href="provider-dashboard.php" class="sidebar-link">
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
        <span>2026</span>
        <img src="../../images/Replate-white.png" alt="" style="height:40px;object-fit:contain;opacity:.45;">
        <span>All rights reserved.</span>
      </div>
    </div>
  </aside>

  <main class="main">
    <div class="items-page-wrap">

      <div class="page-header"><h1>My Items</h1></div>

      <div class="items-header-bar">
        <div style="display:flex;gap:12px;flex-wrap:wrap;">
          <a class="seg-btn <?= $tab==='all'    ? 'active':'' ?>" href="provider-items.php?tab=all">All</a>
          <a class="seg-btn <?= $tab==='donate' ? 'active':'' ?>" href="provider-items.php?tab=donate">Donating</a>
          <a class="seg-btn <?= $tab==='sell'   ? 'active':'' ?>" href="provider-items.php?tab=sell">Selling</a>
        </div>
        <button type="button" class="add-item-open-btn" onclick="openAddModal()">+ Add New Item</button>
      </div>

      <div class="items-grid">
        <?php if (empty($items)): ?>
          <div class="empty-state">No <?= $tab==='all' ? '' : htmlspecialchars($tab==='donate' ? 'donating' : 'selling') ?> items yet.</div>
        <?php else: ?>
          <?php foreach ($items as $item): ?>
            <?php
              $categoryName = 'Uncategorized';
              if (!empty($item['categoryId'])) {
                  $cat = $categoryModel->findById((string)$item['categoryId']);
                  if ($cat && !empty($cat['name'])) $categoryName = $cat['name'];
              }
              $isAvailable = (bool)($item['isAvailable'] ?? false);
              $listingType = $item['listingType'] ?? 'donate';
              $itemId      = (string)$item['_id'];
              $qty         = (int)($item['quantity'] ?? 0);
              $stockOk     = $isAvailable && $qty > 0;

              // Expiry countdown
              $expiryBadgeText  = '';
              $expiryBadgeClass = '';
              if (!empty($item['expiryDate']) && $item['expiryDate'] instanceof MongoDB\BSON\UTCDateTime) {
                  $expiryTs = $item['expiryDate']->toDateTime()->getTimestamp();
                  $daysLeft = ceil(($expiryTs - time()) / 86400);
                  if ($daysLeft <= 0) {
                      $expiryBadgeText = 'Expired'; $expiryBadgeClass = 'badge-expiry-red';
                  } elseif ($daysLeft === 1) {
                      $expiryBadgeText = 'Expires today'; $expiryBadgeClass = 'badge-expiry-red';
                  } elseif ($daysLeft <= 3) {
                      $expiryBadgeText = "Expires in {$daysLeft}d"; $expiryBadgeClass = 'badge-expiry-red';
                  } elseif ($daysLeft <= 7) {
                      $expiryBadgeText = "Expires in {$daysLeft}d"; $expiryBadgeClass = 'badge-expiry-orange';
                  }
              }
            ?>
            <div class="item-grid-card" onclick="openDetailModal('<?= $itemId ?>')">
              <div class="item-card-photo">
                <?php if (!empty($item['photoUrl'])): ?>
                  <img src="<?= htmlspecialchars($item['photoUrl']) ?>" alt="<?= htmlspecialchars($item['itemName'] ?? 'Item') ?>" onerror="this.parentElement.innerHTML='<div class=\'item-card-photo-placeholder\'>No Image</div>'">
                <?php else: ?>
                  <div class="item-card-photo-placeholder">No Image</div>
                <?php endif; ?>
              </div>
              <div class="item-card-body">
                <div class="item-card-top-row">
                  <div class="item-card-name"><?= htmlspecialchars($item['itemName'] ?? 'Item') ?></div>
                  <?php if ($listingType === 'donate'): ?>
                    <span class="badge badge-donate">Donating</span>
                  <?php else: ?>
                    <span class="badge badge-sell">Selling</span>
                  <?php endif; ?>
                </div>
                <span class="item-category-tag"><?= htmlspecialchars($categoryName) ?></span>
                <div class="item-card-divider"></div>
                <?php if ($listingType === 'donate'): ?>
                  <div class="item-card-price donate-price">Donation</div>
                <?php else: ?>
                  <div class="item-card-price"><?= number_format((float)($item['price'] ?? 0), 2) ?> SAR</div>
                <?php endif; ?>
                <?php if (!empty($item['description'])): ?>
                  <p class="item-card-desc"><?= htmlspecialchars($item['description']) ?></p>
                <?php endif; ?>
                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:auto;padding-top:6px;">
                  <?php if ($stockOk): ?>
                    <span class="badge badge-available">Available</span>
                  <?php else: ?>
                    <span class="badge badge-outofstock">Out of Stock</span>
                  <?php endif; ?>
                  <?php if ($expiryBadgeText): ?>
                    <span class="badge <?= $expiryBadgeClass ?>"><?= htmlspecialchars($expiryBadgeText) ?></span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="card-actions" onclick="event.stopPropagation()">
                <button class="card-icon-btn edit-btn" title="Edit" onclick="fetchAndOpenEditModal('<?= $itemId ?>')">
                  <svg width="14" height="14" fill="none" stroke="#ea8b2c" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </button>
                <button class="card-icon-btn del-btn" title="Delete" onclick="openDeleteConfirm('<?= $itemId ?>')">
                  <svg width="14" height="14" fill="none" stroke="#e74c3c" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

    </div>
  </main>
</div>

<!-- ── ITEM DETAIL MODAL ── -->
<div id="detailModal" class="modal-overlay" onclick="if(event.target===this)closeDetailModal()">
  <div class="modal-box detail-box">
    <button class="close-modal-btn" onclick="closeDetailModal()" style="z-index:3;">&times;</button>
    <div id="detailContent" style="flex:1;min-height:0;display:flex;flex-direction:column;overflow:hidden;">
      <div style="padding:60px;text-align:center;color:#8aa3c0;">Loading...</div>
    </div>
  </div>
</div>

<!-- ── DELETE CONFIRM MODAL ── -->
<div id="deleteModal" class="modal-overlay" onclick="if(event.target===this)closeDeleteConfirm()">
  <div class="confirm-box">
    <div class="confirm-icon">
      <svg width="24" height="24" fill="none" stroke="#e74c3c" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 011-1h4a1 1 0 011 1v2"/></svg>
    </div>
    <p class="confirm-title">Delete Item?</p>
    <p class="confirm-sub">This will permanently remove this item. This action cannot be undone.</p>
    <div class="confirm-btns">
      <button class="confirm-cancel" onclick="closeDeleteConfirm()">Cancel</button>
      <button class="confirm-delete" onclick="confirmDelete()">Yes, Delete</button>
    </div>
    <input type="hidden" id="deleteTargetId" value="">
  </div>
</div>

<!-- ── EDIT ITEM MODAL ── -->
<div id="editItemModal" class="modal-overlay" onclick="if(event.target===this)closeEditModal()">
  <div class="modal-box">
    <button class="close-modal-btn" onclick="closeEditModal()">&times;</button>
    <h1 class="modal-title">Edit Item</h1>

    <div id="editErrorBanner" class="form-error-banner" style="display:none;"></div>
    <?php if (!empty($editError ?? '')): ?>
      <div class="form-error-banner"><?= htmlspecialchars($editError) ?></div>
    <?php endif; ?>

    <form class="add-item-form" id="editItemForm" method="POST" action="" enctype="multipart/form-data">
      <input type="hidden" name="action" value="edit_item">
      <input type="hidden" name="editItemId" id="editItemId">
      <input type="hidden" name="existingPhotoUrl" id="editExistingPhotoUrl">

      <!-- Type -->
      <div class="form-group">
        <label>
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
          Select Type <span class="req">*</span>
        </label>
        <div class="type-cards">
          <label class="type-card" id="editTypeCardDonate" onclick="selectEditType('donate')">
            <input type="radio" name="editItemType" value="donate" style="display:none;">
            <div class="type-card-title">Donation</div>
            <div class="type-card-sub">Give it for free</div>
          </label>
          <label class="type-card" id="editTypeCardSell" onclick="selectEditType('sell')">
            <input type="radio" name="editItemType" value="sell" style="display:none;">
            <div class="type-card-title">Selling</div>
            <div class="type-card-sub">Set a price</div>
          </label>
        </div>
      </div>

      <!-- Name + Category -->
      <div class="form-grid two-cols">
        <div class="form-group">
          <label>
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            Item Name <span class="req">*</span>
          </label>
          <input type="text" name="editItemName" id="editItemName" class="form-input" placeholder="e.g. Sourdough Bread">
        </div>
        <div class="form-group">
          <label>
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Category <span class="req">*</span>
          </label>
          <select name="editCategoryId" id="editCategoryId" class="form-select">
            <option value="">Select category</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= htmlspecialchars((string)$category['_id']) ?>"><?= htmlspecialchars($category['name'] ?? '') ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Description -->
      <div class="form-group">
        <label>
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="17" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="17" y1="18" x2="3" y2="18"/></svg>
          Description <span class="req">*</span>
        </label>
        <textarea name="editItemDetails" id="editItemDetails" class="form-textarea" placeholder="Describe the item"></textarea>
      </div>

      <!-- Photo -->
      <div class="form-group">
        <label>
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
          Photo <span style="font-size:12px;color:#7a8fa8;">(leave empty to keep current)</span>
        </label>
        <div id="editCurrentPhotoWrap" style="margin-bottom:8px;display:none;">
          <img id="editCurrentPhotoThumb" src="" alt="Current" style="height:60px;border-radius:10px;object-fit:cover;border:1px solid #d7e1ee;">
        </div>
        <input type="file" name="editItemPhoto" id="editItemPhoto" class="form-input" accept="image/*" style="height:auto;padding:10px 15px;">
      </div>

      <!-- Price -->
      <div id="editPriceWrap" class="form-group" style="display:none;">
        <label>
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
          Price <span class="req">*</span>
        </label>
        <input type="number" name="editPrice" id="editPrice" class="form-input" step="0.01" min="0" placeholder="Enter price in SAR">
      </div>

      <!-- Expiry + Quantity -->
      <div class="form-grid two-cols">
        <div class="form-group">
          <label>
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Expiry Date <span class="req">*</span>
          </label>
          <input type="date" name="editExpiryDate" id="editExpiryDate" class="form-date" min="<?= $today ?>">
        </div>
        <div class="form-group">
          <label>
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            Quantity <span class="req">*</span>
          </label>
          <input type="number" name="editQuantity" id="editQuantity" class="form-input" min="1" step="1" value="1" oninput="if(parseInt(this.value)<1||!this.value)this.value=1;">
        </div>
      </div>

      <!-- Pickup Branch + Pickup Date -->
      <div class="form-grid two-cols">
        <div class="form-group">
          <label>
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="3"/></svg>
            Pickup Branch <span class="req">*</span>
          </label>
          <select name="editPickupLocationId" id="editPickupLocationId" class="form-select">
            <option value="">Select pickup branch</option>
            <?php foreach ($locations as $loc): ?>
              <?php
                $bName = trim($loc['locationName'] ?? ($loc['label'] ?? ''));
                if ($bName === '') $bName = trim($loc['street'] ?? 'Branch');
                $bText = trim(implode(' - ', array_filter([$bName, $loc['street']??'', $loc['city']??''])));
              ?>
              <option value="<?= htmlspecialchars((string)$loc['_id']) ?>"><?= htmlspecialchars($bText) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01"/></svg>
            Pickup Date <span class="req">*</span>
          </label>
          <input type="date" name="editPickupDate" id="editPickupDate" class="form-date" min="<?= $today ?>">
        </div>
      </div>

      <!-- Pickup Times -->
      <div class="form-group">
        <label>
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Pickup Times <span class="req">*</span>
        </label>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
          <?php foreach ($timeSlots as $slot): ?>
            <button type="button" class="slot-btn edit-slot-btn" onclick="toggleEditSlot('<?= $slot ?>')"><?= $slot ?></button>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
          <select id="editCustomHour" class="small-time-select">
            <option value="">Hour</option>
            <?php for ($h=1;$h<=12;$h++): ?><option value="<?= $h ?>"><?= $h ?></option><?php endfor; ?>
          </select>
          <select id="editCustomMinute" class="small-time-select">
            <option value="">Min</option>
            <option value="00">00</option><option value="15">15</option><option value="30">30</option><option value="45">45</option>
          </select>
          <select id="editCustomAmPm" class="small-time-select"><option value="AM">AM</option><option value="PM">PM</option></select>
          <button type="button" class="add-time-btn" onclick="addEditCustomTime()">+ Add</button>
        </div>
        <div id="editTimeList" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
        <div id="editHiddenTimesWrap"></div>
      </div>

      <div class="submit-row">
        <button type="button" class="add-btn" onclick="submitEditItem()">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ── ADD ITEM MODAL ── -->
<div id="addItemModal" class="modal-overlay" onclick="if(event.target===this)closeAddModal()">
  <div class="modal-box">
    <button class="close-modal-btn" onclick="closeAddModal()">&times;</button>
    <h1 class="modal-title">Add Item</h1>

    <?php if (!empty($formError)): ?>
      <div class="form-error-banner"><?= htmlspecialchars($formError) ?></div>
    <?php endif; ?>

    <form class="add-item-form" id="addItemForm" method="POST" action="" enctype="multipart/form-data">

      <!-- 1. Type -->
      <div class="form-group">
        <label id="labelItemType">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
          Select Type <span class="req">*</span>
        </label>
        <div class="type-cards" id="typeCardsWrap">
          <label class="type-card" id="typeCardDonate" onclick="selectType('donate')">
            <input type="radio" name="itemType" value="donate" style="display:none;" <?= (($_POST['itemType']??'')==='donate')?'checked':'' ?>>
            <div class="type-card-title">Donation</div>
            <div class="type-card-sub">Give it for free</div>
          </label>
          <label class="type-card" id="typeCardSell" onclick="selectType('sell')">
            <input type="radio" name="itemType" value="sell" style="display:none;" <?= (($_POST['itemType']??'')==='sell')?'checked':'' ?>>
            <div class="type-card-title">Selling</div>
            <div class="type-card-sub">Set a price</div>
          </label>
        </div>
        <div class="field-error" id="itemTypeError">Please select a listing type.</div>
      </div>

      <!-- 2 & 3. Name + Category -->
      <div class="form-grid two-cols">
        <div class="form-group">
          <label for="itemName">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 013 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            Item Name <span class="req">*</span>
          </label>
          <input type="text" name="itemName" id="itemName" class="form-input" value="<?= htmlspecialchars($_POST['itemName']??'') ?>" placeholder="e.g. Sourdough Bread">
          <div class="field-error" id="itemNameError">Please enter the item name.</div>
        </div>
        <div class="form-group">
          <label for="categoryId">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
            Category <span class="req">*</span>
          </label>
          <select name="categoryId" id="categoryId" class="form-select">
            <option value="">Select category</option>
            <?php foreach ($categories as $category): ?>
              <option value="<?= htmlspecialchars((string)$category['_id']) ?>" <?= (($_POST['categoryId']??'')===(string)$category['_id'])?'selected':'' ?>>
                <?= htmlspecialchars($category['name']??'Unnamed') ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="field-error" id="categoryIdError">Please select a category.</div>
        </div>
      </div>

      <!-- 4. Description -->
      <div class="form-group">
        <label for="itemDetails">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="17" y1="10" x2="3" y2="10"/><line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="14" x2="3" y2="14"/><line x1="17" y1="18" x2="3" y2="18"/></svg>
          Description <span class="req">*</span>
        </label>
        <textarea name="itemDetails" id="itemDetails" class="form-textarea" placeholder="Describe the item"><?= htmlspecialchars($_POST['itemDetails']??'') ?></textarea>
        <div class="field-error" id="itemDetailsError">Description is required.</div>
      </div>

      <!-- 5. Photo -->
      <div class="form-group">
        <label for="itemPhoto">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M23 19a2 2 0 01-2 2H3a2 2 0 01-2-2V8a2 2 0 012-2h4l2-3h6l2 3h4a2 2 0 012 2z"/><circle cx="12" cy="13" r="4"/></svg>
          Photo <span class="req">*</span>
        </label>
        <input type="file" name="itemPhoto" id="itemPhoto" class="form-input" accept="image/*" style="height:auto;padding:10px 15px;">
        <div class="field-error" id="itemPhotoError">Please upload a photo.</div>
      </div>

      <!-- 6. Price (conditional) -->
      <div id="priceWrap" class="form-group">
        <label for="priceInput">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/></svg>
          Price <span class="req">*</span>
        </label>
        <input type="number" name="price" id="priceInput" class="form-input" step="0.01" min="0" value="<?= htmlspecialchars($_POST['price']??'') ?>" placeholder="Enter price in SAR">
        <div class="field-error" id="priceError">Please enter a valid price.</div>
      </div>

      <!-- 7. Expiry + Quantity -->
      <div class="form-grid two-cols">
        <div class="form-group">
          <label for="expiryDate">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Expiry Date <span class="req">*</span>
          </label>
          <input type="date" name="expiryDate" id="expiryDate" class="form-date" min="<?= $today ?>" value="<?= htmlspecialchars($_POST['expiryDate']??'') ?>">
          <div class="field-error" id="expiryDateError">Please select an expiry date.</div>
        </div>
        <div class="form-group">
          <label for="quantity">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
            Quantity <span class="req">*</span>
          </label>
          <input type="number" name="quantity" id="quantity" class="form-input" min="1" step="1" value="<?= htmlspecialchars((string)($_POST['quantity']??1)) ?>" placeholder="Available units" oninput="if(parseInt(this.value)<1||!this.value)this.value=1;">
          <div class="field-error" id="quantityError">Please enter a quantity.</div>
        </div>
      </div>

      <!-- 8 & 9. Branch + Pickup Date -->
      <div class="form-grid two-cols">
        <div class="form-group">
          <label for="pickupLocationId">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="3"/></svg>
            Pickup Branch <span class="req">*</span>
          </label>
          <select name="pickupLocationId" id="pickupLocationId" class="form-select">
            <option value="">Select pickup branch</option>
            <?php foreach ($locations as $loc): ?>
              <?php
                $bName = trim($loc['locationName'] ?? ($loc['label'] ?? ''));
                if ($bName === '') $bName = trim($loc['street'] ?? 'Branch');
                $bText = trim(implode(' - ', array_filter([$bName, $loc['street']??'', $loc['city']??''])));
              ?>
              <option value="<?= htmlspecialchars((string)$loc['_id']) ?>" <?= ($defaultLocation && (string)$loc['_id']===(string)$defaultLocation['_id'])?'selected':'' ?>>
                <?= htmlspecialchars($bText) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="field-error" id="pickupLocationIdError">Please select a pickup branch.</div>
        </div>
        <div class="form-group">
          <label for="pickupDate">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/><path d="M8 14h.01M12 14h.01M16 14h.01"/></svg>
            Pickup Date <span class="req">*</span>
          </label>
          <input type="date" name="pickupDate" id="pickupDate" class="form-date" min="<?= $today ?>" value="<?= htmlspecialchars($_POST['pickupDate']??$today) ?>">
          <div class="field-error" id="pickupDateError">Please select a pickup date.</div>
        </div>
      </div>

      <!-- 10. Pickup Times -->
      <div class="form-group">
        <label>
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          Pickup Times <span class="req">*</span>
        </label>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px;">
          <?php $slots = ['8:00 AM','9:00 AM','10:00 AM','11:00 AM','12:00 PM','1:00 PM','2:00 PM','3:00 PM','4:00 PM','5:00 PM','6:00 PM','7:00 PM','8:00 PM'];
          foreach ($slots as $slot): ?>
            <button type="button" class="slot-btn" onclick="toggleSlot('<?= $slot ?>',this)"><?= $slot ?></button>
          <?php endforeach; ?>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;">
          <select id="customHour" class="small-time-select">
            <option value="">Hour</option>
            <?php for ($h=1;$h<=12;$h++): ?><option value="<?= $h ?>"><?= $h ?></option><?php endfor; ?>
          </select>
          <select id="customMinute" class="small-time-select">
            <option value="">Min</option>
            <option value="00">00</option><option value="15">15</option><option value="30">30</option><option value="45">45</option>
          </select>
          <select id="customAmPm" class="small-time-select"><option value="AM">AM</option><option value="PM">PM</option></select>
          <button type="button" class="add-time-btn" onclick="addCustomTime()">+ Add</button>
        </div>
        <div id="timeList" style="display:flex;flex-wrap:wrap;gap:8px;"></div>
        <div id="hiddenTimesWrap"></div>
        <div class="field-error" id="pickupTimesError">Please add at least one pickup time.</div>
      </div>

      <div class="submit-row">
        <button type="button" class="add-btn" onclick="submitAddItem()">Add Item</button>
      </div>

    </form>
  </div>
</div>

<!-- ── TOAST ── -->
<div class="toast" id="toast"></div>

<script>
// ── Success toast on redirect ─────────────────────────────────────────────────
(function(){
  const params = new URLSearchParams(window.location.search);
  if (params.get('success') === 'edited') {
    showToast('✓ Item updated successfully!', 'success');
  } else if (params.get('success') === 'added') {
    showToast('✓ Item added successfully!', 'success');
  }
  if (params.get('success')) {
    const url = new URL(window.location);
    url.searchParams.delete('success');
    window.history.replaceState({}, '', url);
  }
})();

// ── Mobile menu ───────────────────────────────────────────────────────────────
function toggleMobileMenu() {
  const menu = document.getElementById('mobileMenu');
  const btn  = document.getElementById('hamburger');
  menu.classList.toggle('open');
  btn.classList.toggle('open');
  document.body.style.overflow = menu.classList.contains('open') ? 'hidden' : '';
}
function closeMobileMenu() {
  document.getElementById('mobileMenu').classList.remove('open');
  document.getElementById('hamburger').classList.remove('open');
  document.body.style.overflow = '';
}

// ── Search (matches provider-dashboard exactly) ───────────────────────────────
const searchInput    = document.getElementById('searchInput');
const searchDropdown = document.getElementById('searchDropdown');
let debounceTimer    = null;

searchInput?.addEventListener('input', () => {
  clearTimeout(debounceTimer);
  const q = searchInput.value.trim();
  if (q.length < 2) { closeDropdown(); return; }
  debounceTimer = setTimeout(() => doSearch(q), 300);
});
searchInput?.addEventListener('focus', () => {
  if (searchInput.value.trim().length >= 2) doSearch(searchInput.value.trim());
});
document.addEventListener('click', (e) => {
  if (!document.getElementById('searchWrap')?.contains(e.target)) closeDropdown();
});

function closeDropdown() {
  searchDropdown.classList.remove('visible');
  searchDropdown.innerHTML = '';
}

function doSearch(q) {
  searchDropdown.innerHTML = '<div class="sd-loading">Searching...</div>';
  searchDropdown.classList.add('visible');
  fetch(`../../back-end/provider-search.php?q=${encodeURIComponent(q)}`)
    .then(r => r.json())
    .then(data => renderResults(data))
    .catch(() => { searchDropdown.innerHTML = '<div class="sd-empty">Something went wrong.</div>'; });
}

function renderResults(data) {
  const items = data.items || [];
  if (!items.length) {
    searchDropdown.innerHTML = '<div class="sd-empty">No items found.</div>'; return;
  }
  let html = '<div class="sd-section-title">Items</div>';
  items.forEach(item => {
    const thumb = item.photoUrl
      ? `<img src="${esc(item.photoUrl)}" alt="" onerror="this.style.display='none'">`
      : `<svg width="20" height="20" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/></svg>`;
    const badgeClass = item.listingType === 'donate' ? 'sd-badge-donate' : 'sd-badge-sell';
    const badgeLabel = item.listingType === 'donate' ? 'Donation' : 'Selling';
    html += `<a class="sd-row" href="#" onclick="event.preventDefault();closeDropdown();openDetailModal('${esc(item.id)}')">
      <div class="sd-thumb">${thumb}</div>
      <div class="sd-info"><div class="sd-name">${esc(item.name)}</div><div class="sd-sub">${esc(item.price)}</div></div>
      <span class="sd-badge ${badgeClass}">${badgeLabel}</span>
    </a>`;
  });
  searchDropdown.innerHTML = html;
}

function esc(str) {
  const d = document.createElement('div');
  d.textContent = String(str ?? '');
  return d.innerHTML;
}

// ── Mobile search — independent, renders into its own dropdown ────────────────
(function() {
  const mInput    = document.getElementById('mobileSearchInput');
  const mDropdown = document.getElementById('mobileSearchDropdown');
  if (!mInput || !mDropdown) return;
  let mTimer = null;

  mInput.addEventListener('input', function() {
    clearTimeout(mTimer);
    const q = this.value.trim();
    if (q.length < 2) { mDropdown.classList.remove('visible'); mDropdown.innerHTML = ''; return; }
    mDropdown.innerHTML = '<div class="sd-loading">Searching...</div>';
    mDropdown.classList.add('visible');
    mTimer = setTimeout(() => mDoSearch(q), 300);
  });

  function mDoSearch(q) {
    fetch(`../../back-end/provider-search.php?q=${encodeURIComponent(q)}`)
      .then(r => r.json())
      .then(data => mRenderResults(data))
      .catch(() => { mDropdown.innerHTML = '<div class="sd-empty">Something went wrong.</div>'; });
  }

  function mRenderResults(data) {
    const items = data.items || [];
    if (!items.length) { mDropdown.innerHTML = '<div class="sd-empty">No items found.</div>'; return; }
    let html = '<div class="sd-section-title">Items</div>';
    items.forEach(item => {
      const thumb = item.photoUrl
        ? `<img src="${mEsc(item.photoUrl)}" alt="" onerror="this.style.display='none'">`
        : `<svg width="20" height="20" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/></svg>`;
      const badgeClass = item.listingType === 'donate' ? 'sd-badge-donate' : 'sd-badge-sell';
      const badgeLabel = item.listingType === 'donate' ? 'Donation' : 'Selling';
      html += `<a class="sd-row" href="#" onclick="event.preventDefault();document.getElementById('mobileSearchDropdown').classList.remove('visible');openDetailModal('${mEsc(item.id)}')">
        <div class="sd-thumb">${thumb}</div>
        <div class="sd-info"><div class="sd-name">${mEsc(item.name)}</div><div class="sd-sub">${mEsc(item.price)}</div></div>
        <span class="sd-badge ${badgeClass}">${badgeLabel}</span>
      </a>`;
    });
    mDropdown.innerHTML = html;
  }

  function mEsc(str) {
    const d = document.createElement('div');
    d.textContent = String(str ?? '');
    return d.innerHTML;
  }
})();

// ── Detail Modal ──────────────────────────────────────────────────────────────
function openDetailModal(id){
  document.getElementById('detailModal').classList.add('open');
  document.getElementById('detailContent').innerHTML = '<div style="padding:60px;text-align:center;color:#8aa3c0;">Loading...</div>';
  fetch(`provider-items.php?action=get_item&id=${id}`)
    .then(r => r.json())
    .then(data => {
      window._lastDetailItem = data.item;
      if(!data.success){ document.getElementById('detailContent').innerHTML='<div style="padding:40px;text-align:center;color:#c0392b;">Could not load item.</div>'; return; }
      const it = data.item;

      const typeLabel  = it.type === 'donate'
        ? '<span class="badge badge-donate">Donating</span>'
        : '<span class="badge badge-sell">Selling</span>';
      const availBadge = it.isAvailable
        ? '<span class="badge badge-available">Available</span>'
        : '<span class="badge badge-unavailable">Unavailable</span>';
      const priceHtml  = it.type === 'donate'
        ? ''
        : `<div class="detail-price">${parseFloat(it.price).toFixed(2)} SAR</div>`;
      const thumbHtml  = it.photoUrl
        ? `<img src="${it.photoUrl}" alt="${it.name}">`
        : `<div class="detail-thumb-placeholder">No Image</div>`;
      const timesHtml  = (it.pickupTimes && it.pickupTimes.length)
        ? it.pickupTimes.map(t => `<span class="detail-time-chip">${t}</span>`).join('')
        : '<span style="color:#8aa3c0;font-size:12px;">No times added</span>';

      // Map — OpenStreetMap (no API key required)
      let mapSrc = '';
      const addr = it.location?.fullAddress || it.location?.name || '';
      if (it.location?.lat && it.location?.lng) {
        const lat = parseFloat(it.location.lat);
        const lng = parseFloat(it.location.lng);
        const d   = 0.006;
        mapSrc = `https://www.openstreetmap.org/export/embed.html?bbox=${lng-d},${lat-d},${lng+d},${lat+d}&layer=mapnik&marker=${lat},${lng}`;
      } else if (addr) {
        mapSrc = `https://www.openstreetmap.org/export/embed.html?query=${encodeURIComponent(addr)}&layer=mapnik`;
      }
      const mapHtml = mapSrc
        ? `<div class="map-accordion">
            <div class="map-accordion-header" onclick="toggleMapAccordion(this)">
              <div>
                <div class="map-accordion-title">
                  <svg width="14" height="14" fill="none" stroke="#1a3a6b" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="3"/></svg>
                  Pickup Location
                </div>
                <div class="map-accordion-addr">${addr}</div>
              </div>
              <svg class="map-accordion-chevron" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
            </div>
            <div class="map-accordion-body">
              <iframe src="${mapSrc}" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
            </div>
          </div>`
        : `<div style="font-size:13px;color:#8aa3c0;padding:8px 0;">No location info available.</div>`;

      document.getElementById('detailContent').innerHTML = `
        <div class="detail-scroll">
          <div class="detail-top">
            <div class="detail-thumb">${thumbHtml}</div>
            <div class="detail-top-info">
              <div class="detail-title">${it.name}</div>
              <div class="detail-category">
                <svg width="13" height="13" fill="none" stroke="#8aa3c0" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
                <span style="font-size:14px;color:#7a8fa8;">${it.category}</span>
              </div>
              <div class="detail-top-badges">${typeLabel} ${availBadge}</div>
              ${priceHtml}
            </div>
          </div>
          <div class="detail-body">
            <div class="detail-desc-block">
              <label>Description</label>
              <p>${it.description || 'No description.'}</p>
            </div>
            <div class="detail-grid">
              <div class="detail-field"><label>Quantity</label><span>${it.quantity}</span></div>
              <div class="detail-field"><label>Expiry Date</label><span>${it.expiryDate || '—'}</span></div>
              <div class="detail-field"><label>Pickup Date</label><span>${it.pickupDate || '—'}</span></div>
            </div>
            ${mapHtml}
            <div>
              <div style="font-size:10px;font-weight:700;color:#8aa3c0;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px;">Pickup Times</div>
              <div class="detail-times-wrap">${timesHtml}</div>
            </div>
          </div>
          <div class="detail-actions">
            <button class="detail-del-btn" onclick="closeDetailModal(); openDeleteConfirm('${it.id}')">Delete</button>
            <button class="detail-edit-btn" onclick="closeDetailModal(); openEditModal(window._lastDetailItem)">
              <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              Edit Item
            </button>
          </div>
        </div>`;
    }).catch(() => {
      document.getElementById('detailContent').innerHTML='<div style="padding:40px;text-align:center;color:#c0392b;">Failed to load.</div>';
    });
}
function closeDetailModal(){ document.getElementById('detailModal').classList.remove('open'); }

function toggleMapAccordion(header) {
  const body    = header.nextElementSibling;
  const chevron = header.querySelector('.map-accordion-chevron');
  body.classList.toggle('open');
  chevron.classList.toggle('open');
}

// ── Delete Confirm ────────────────────────────────────────────────────────────
function openDeleteConfirm(id){
  document.getElementById('deleteTargetId').value = id;
  document.getElementById('deleteModal').classList.add('open');
}
function closeDeleteConfirm(){ document.getElementById('deleteModal').classList.remove('open'); }
function confirmDelete(){
  const id = document.getElementById('deleteTargetId').value;
  if(!id) return;
  const fd = new FormData();
  fd.append('action', 'delete_item');
  fd.append('itemId', id);
  fetch('', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if(data.success){ closeDeleteConfirm(); showToast('✓ Item deleted successfully.', 'success'); setTimeout(() => location.reload(), 900); }
      else showToast('Could not delete item. Please try again.', 'error');
    })
    .catch(() => showToast('Something went wrong.', 'error'));
}

// ── Toast ─────────────────────────────────────────────────────────────────────
function showToast(msg, type='error'){
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.style.background = type === 'success' ? '#1a6b3a' : '#c0392b';
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}

// ── Add Modal ─────────────────────────────────────────────────────────────────
function openAddModal(){  document.getElementById('addItemModal').classList.add('open'); }
function closeAddModal(){ document.getElementById('addItemModal').classList.remove('open'); }

// ── Type Selection ────────────────────────────────────────────────────────────
function selectType(type){
  const donateInput = document.querySelector('input[name="itemType"][value="donate"]');
  const sellInput   = document.querySelector('input[name="itemType"][value="sell"]');
  const dc = document.getElementById('typeCardDonate');
  const sc = document.getElementById('typeCardSell');
  const pw = document.getElementById('priceWrap');
  if(type === 'donate'){
    if(donateInput) donateInput.checked = true;
    dc.classList.add('active'); dc.classList.remove('error');
    sc.classList.remove('active');
    pw.style.display = 'none';
    const pi = document.getElementById('priceInput'); if(pi) pi.value='';
  } else {
    if(sellInput) sellInput.checked = true;
    sc.classList.add('active'); sc.classList.remove('error');
    dc.classList.remove('active');
    pw.style.display = 'block';
  }
  clearFieldError('itemTypeError');
}

(function(){
  const checked = document.querySelector('input[name="itemType"]:checked');
  if(checked) selectType(checked.value);
})();

// ── Pickup Times ──────────────────────────────────────────────────────────────
let pickupTimes = <?= json_encode($_POST['pickupTimes'] ?? []) ?>;

function renderPickupTimes(){
  const list = document.getElementById('timeList');
  const hw   = document.getElementById('hiddenTimesWrap');
  list.innerHTML = ''; hw.innerHTML = '';
  pickupTimes.forEach((t,i) => {
    const chip = document.createElement('span');
    chip.className = 'time-chip';
    chip.innerHTML = `${t} <button type="button" onclick="removeTime(${i})" style="background:none;border:none;cursor:pointer;font-weight:700;font-size:14px;color:#4a6a9a;">x</button>`;
    list.appendChild(chip);
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='pickupTimes[]'; inp.value=t;
    hw.appendChild(inp);
  });
  document.querySelectorAll('#addItemForm .slot-btn').forEach(btn => {
    btn.classList.toggle('selected', pickupTimes.includes(btn.textContent.trim()));
  });
}
function toggleSlot(time, btn){
  pickupTimes = pickupTimes.includes(time) ? pickupTimes.filter(t=>t!==time) : [...pickupTimes, time];
  renderPickupTimes(); clearFieldError('pickupTimesError');
}
function addCustomTime(){
  const h=document.getElementById('customHour').value, m=document.getElementById('customMinute').value, ap=document.getElementById('customAmPm').value;
  if(!h||!m){ showToast('Please choose hour and minute.','error'); return; }
  const val=`${h}:${m} ${ap}`;
  if(!pickupTimes.includes(val)){ pickupTimes.push(val); renderPickupTimes(); }
  document.getElementById('customHour').value=''; document.getElementById('customMinute').value='';
  clearFieldError('pickupTimesError');
}
function removeTime(i){ pickupTimes.splice(i,1); renderPickupTimes(); }
renderPickupTimes();

// ── Validation ────────────────────────────────────────────────────────────────
function setFieldError(inputId, errorId, labelId){
  const el = document.getElementById(inputId);
  const er = document.getElementById(errorId);
  const lb = labelId ? document.getElementById(labelId) : null;
  if(el){ el.classList.add('error'); }
  if(er){ er.style.display='block'; }
  if(lb){ lb.classList.add('error-label'); }
}
function clearFieldError(errorId){
  const er = document.getElementById(errorId);
  if(er) er.style.display='none';
}
function clearAllErrors(){
  document.querySelectorAll('#addItemForm .form-input, #addItemForm .form-select, #addItemForm .form-textarea, #addItemForm .form-date').forEach(el => el.classList.remove('error'));
  document.querySelectorAll('#addItemForm .field-error').forEach(el => el.style.display='none');
  document.querySelectorAll('#addItemForm label').forEach(el => el.classList.remove('error-label'));
  document.getElementById('typeCardDonate')?.classList.remove('error');
  document.getElementById('typeCardSell')?.classList.remove('error');
}

function submitAddItem(){
  clearAllErrors();
  let valid = true;
  const failedFields = [];

  const type = document.querySelector('input[name="itemType"]:checked');
  if(!type){
    document.getElementById('typeCardDonate').classList.add('error');
    document.getElementById('typeCardSell').classList.add('error');
    document.getElementById('itemTypeError').style.display='block';
    failedFields.push('Listing Type');
    valid = false;
  }

  const name = document.getElementById('itemName').value.trim();
  if(!name){ setFieldError('itemName','itemNameError'); failedFields.push('Item Name'); valid=false; }

  const cat = document.getElementById('categoryId').value;
  if(!cat){ setFieldError('categoryId','categoryIdError'); failedFields.push('Category'); valid=false; }

  const desc = document.getElementById('itemDetails').value.trim();
  if(!desc){ setFieldError('itemDetails','itemDetailsError'); failedFields.push('Description'); valid=false; }

  const photo = document.getElementById('itemPhoto').files.length;
  if(!photo){ setFieldError('itemPhoto','itemPhotoError'); failedFields.push('Photo'); valid=false; }

  if(type && type.value==='sell'){
    const price = parseFloat(document.getElementById('priceInput').value);
    if(!price||price<=0){ setFieldError('priceInput','priceError'); failedFields.push('Price'); valid=false; }
  }

  const expiry = document.getElementById('expiryDate').value;
  if(!expiry){ setFieldError('expiryDate','expiryDateError'); failedFields.push('Expiry Date'); valid=false; }

  const qty = parseInt(document.getElementById('quantity').value);
  if(!qty||qty<1){ setFieldError('quantity','quantityError'); failedFields.push('Quantity'); valid=false; }

  const loc = document.getElementById('pickupLocationId').value;
  if(!loc){ setFieldError('pickupLocationId','pickupLocationIdError'); failedFields.push('Pickup Branch'); valid=false; }

  const pDate = document.getElementById('pickupDate').value;
  if(!pDate){ setFieldError('pickupDate','pickupDateError'); failedFields.push('Pickup Date'); valid=false; }

  if(pickupTimes.length===0){
    document.getElementById('pickupTimesError').style.display='block';
    failedFields.push('Pickup Times');
    valid=false;
  }

  if(!valid){
    showToast('Please fill in: ' + failedFields.join(', '), 'error');
    const firstError = document.querySelector('#addItemForm .form-input.error, #addItemForm .form-select.error, #addItemForm .form-textarea.error, #addItemForm .form-date.error');
    if(firstError) firstError.scrollIntoView({ behavior:'smooth', block:'center' });
    return;
  }

  document.getElementById('addItemForm').submit();
}

<?php if(!empty($formError)): ?>
document.getElementById('addItemModal').classList.add('open');
showToast(<?= json_encode($formError) ?>, 'error');
<?php endif; ?>

// ── Edit Modal ────────────────────────────────────────────────────────────────
let editPickupTimes = [];

function openEditModal(it) {
  if (!it) return;
  document.getElementById('editItemModal').classList.add('open');
  document.getElementById('editItemId').value           = it.id;
  document.getElementById('editExistingPhotoUrl').value = it.photoUrl;
  document.getElementById('editItemName').value         = it.name;
  document.getElementById('editItemDetails').value      = it.description;
  document.getElementById('editExpiryDate').value       = it.expiryDateRaw  || '<?= $today ?>';
  document.getElementById('editPickupDate').value       = it.pickupDateRaw  || '<?= $today ?>';
  document.getElementById('editQuantity').value         = it.quantity >= 1 ? it.quantity : 1;
  document.getElementById('editPrice').value            = it.price || '';

  const catSel = document.getElementById('editCategoryId');
  for (let o of catSel.options) o.selected = o.value === it.categoryId;

  const locSel = document.getElementById('editPickupLocationId');
  for (let o of locSel.options) o.selected = o.value === it.pickupLocationId;

  const thumb = document.getElementById('editCurrentPhotoThumb');
  const wrap  = document.getElementById('editCurrentPhotoWrap');
  if (it.photoUrl) { thumb.src = it.photoUrl; wrap.style.display = 'block'; }
  else             { wrap.style.display = 'none'; }

  selectEditType(it.type || 'donate');
  editPickupTimes = Array.isArray(it.pickupTimes) ? [...it.pickupTimes] : [];
  renderEditPickupTimes();
}

function fetchAndOpenEditModal(id) {
  fetch(`provider-items.php?action=get_item&id=${id}`)
    .then(r => r.json())
    .then(data => { if (data.success) openEditModal(data.item); else showToast('Could not load item.','error'); })
    .catch(() => showToast('Could not load item data.', 'error'));
}

function closeEditModal() { document.getElementById('editItemModal').classList.remove('open'); }

function selectEditType(type) {
  const dc = document.getElementById('editTypeCardDonate');
  const sc = document.getElementById('editTypeCardSell');
  const pw = document.getElementById('editPriceWrap');
  const di = document.querySelector('input[name="editItemType"][value="donate"]');
  const si = document.querySelector('input[name="editItemType"][value="sell"]');
  if (type === 'donate') {
    if (di) di.checked = true;
    dc.classList.add('active'); sc.classList.remove('active'); pw.style.display = 'none';
  } else {
    if (si) si.checked = true;
    sc.classList.add('active'); dc.classList.remove('active'); pw.style.display = 'block';
  }
}

function renderEditPickupTimes() {
  const list = document.getElementById('editTimeList');
  const hw   = document.getElementById('editHiddenTimesWrap');
  list.innerHTML = ''; hw.innerHTML = '';
  editPickupTimes.forEach((t, i) => {
    const chip = document.createElement('span');
    chip.className = 'time-chip';
    chip.innerHTML = `${t} <button type="button" onclick="removeEditTime(${i})" style="background:none;border:none;cursor:pointer;font-weight:700;font-size:14px;color:#4a6a9a;">x</button>`;
    list.appendChild(chip);
    const inp = document.createElement('input');
    inp.type='hidden'; inp.name='editPickupTimes[]'; inp.value=t;
    hw.appendChild(inp);
  });
  document.querySelectorAll('.edit-slot-btn').forEach(btn => {
    btn.classList.toggle('selected', editPickupTimes.includes(btn.textContent.trim()));
  });
}

function toggleEditSlot(time) {
  editPickupTimes = editPickupTimes.includes(time) ? editPickupTimes.filter(t=>t!==time) : [...editPickupTimes, time];
  renderEditPickupTimes();
}

function addEditCustomTime() {
  const h=document.getElementById('editCustomHour').value, m=document.getElementById('editCustomMinute').value, ap=document.getElementById('editCustomAmPm').value;
  if (!h||!m) { showToast('Please choose hour and minute.','error'); return; }
  const val=`${h}:${m} ${ap}`;
  if (!editPickupTimes.includes(val)) { editPickupTimes.push(val); renderEditPickupTimes(); }
  document.getElementById('editCustomHour').value=''; document.getElementById('editCustomMinute').value='';
}

function removeEditTime(i) { editPickupTimes.splice(i,1); renderEditPickupTimes(); }

function submitEditItem() {
  const name = document.getElementById('editItemName').value.trim();
  const cat  = document.getElementById('editCategoryId').value;
  const desc = document.getElementById('editItemDetails').value.trim();
  const type = document.querySelector('input[name="editItemType"]:checked');
  const loc  = document.getElementById('editPickupLocationId').value;
  const exp  = document.getElementById('editExpiryDate').value;
  const qty  = parseInt(document.getElementById('editQuantity').value);

  if (!name)        { showToast('Please fill in: Item Name', 'error'); return; }
  if (!cat)         { showToast('Please fill in: Category', 'error'); return; }
  if (!desc)        { showToast('Please fill in: Description', 'error'); return; }
  if (!type)        { showToast('Please select a listing type', 'error'); return; }
  if (!loc)         { showToast('Please fill in: Pickup Branch', 'error'); return; }
  if (!exp)         { showToast('Please fill in: Expiry Date', 'error'); return; }
  if (!qty || qty < 1){ showToast('Please fill in: Quantity', 'error'); return; }
  if (type.value === 'sell') {
    const price = parseFloat(document.getElementById('editPrice').value);
    if (!price || price <= 0) { showToast('Please fill in: Price', 'error'); return; }
  }
  if (editPickupTimes.length === 0) { showToast('Please add at least one pickup time.', 'error'); return; }
  document.getElementById('editItemForm').submit();
}

<?php if (!empty($editError ?? '')): ?>
document.getElementById('editItemModal').classList.add('open');
showToast(<?= json_encode($editError) ?>, 'error');
<?php endif; ?>
</script>
</body>
</html>