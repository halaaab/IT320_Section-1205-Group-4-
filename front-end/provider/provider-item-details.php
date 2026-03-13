<?php
// ================================================================
// provider-item-details.php — Edit an Existing Item
// ================================================================
// URL PARAMS:  ?itemId=xxx
// VARIABLES:
//   $item       → current item data
//   $categories → all categories for dropdown
//   $locations  → this provider's pickup locations
//   $errors     → validation errors
//   $success    → bool — true after saved
// FORM FIELDS: same as provider-add-item.php (pre-filled)
// ================================================================

session_start();
require_once '../../../back-end/config/database.php';
require_once '../../../back-end/models/BaseModel.php';
require_once '../../../back-end/models/Item.php';
require_once '../../../back-end/models/Category.php';
require_once '../../../back-end/models/PickupLocation.php';

if (empty($_SESSION['providerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$providerId = $_SESSION['providerId'];
$itemId     = $_GET['itemId'] ?? '';
$itemModel  = new Item();
$item       = $itemId ? $itemModel->findById($itemId) : null;

// Security: only owner can edit
if (!$item || (string) $item['providerId'] !== $providerId) {
    header('Location: provider-items.php');
    exit;
}

$categories = (new Category())->getAll();
$locations  = (new PickupLocation())->getByProvider($providerId);
$errors     = [];
$success    = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemName         = trim($_POST['itemName']         ?? '');
    $description      = trim($_POST['description']      ?? '');
    $categoryId       = trim($_POST['categoryId']       ?? '');
    $pickupLocationId = trim($_POST['pickupLocationId'] ?? '');
    $listingType      = trim($_POST['listingType']      ?? '');
    $price            = (float) ($_POST['price']        ?? 0);
    $quantity         = (int)   ($_POST['quantity']     ?? 0);
    $expiryDate       = trim($_POST['expiryDate']       ?? '');
    $pickupTimes      = $_POST['pickupTimes']           ?? [];
    $photoUrl         = trim($_POST['photoUrl']         ?? '');

    if (!$itemName)   $errors['itemName']  = 'Item name is required.';
    if ($quantity < 0) $errors['quantity'] = 'Quantity cannot be negative.';
    if ($listingType === 'sell' && $price <= 0) $errors['price'] = 'Please enter a valid price.';

    if (empty($errors)) {
        $itemModel->updateById($itemId, [
            'itemName'         => $itemName,
            'description'      => $description,
            'categoryId'       => $categoryId,
            'pickupLocationId' => $pickupLocationId,
            'listingType'      => $listingType,
            'price'            => $listingType === 'donate' ? 0 : $price,
            'quantity'         => $quantity,
            'expiryDate'       => $expiryDate,
            'pickupTimes'      => $pickupTimes,
            'photoUrl'         => $photoUrl,
        ]);
        $item    = $itemModel->findById($itemId); // refresh
        $success = true;
    }
}

// ── EXAMPLE: Pre-filled form field ──
// <input name="itemName" value="<?= htmlspecialchars($item['itemName']) ?>" />
// <input name="expiryDate" type="date" value="<?= htmlspecialchars($item['expiryDate']) ?>" />
// <input type="radio" name="listingType" value="sell"
//   <?= $item['listingType']==='sell' ? 'checked' : '' ?> /> Sell
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Edit Item</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
