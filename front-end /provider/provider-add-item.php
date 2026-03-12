<?php
// ================================================================
// provider-add-item.php — Add New Item Listing
// ================================================================
// VARIABLES:
//   $categories → all categories for dropdown
//   $locations  → this provider's pickup locations for dropdown
//   $errors     → field errors on validation failure
//   $success    → bool — true after item created
// FORM FIELDS:
//   itemName, description, categoryId, pickupLocationId,
//   listingType (donate|sell), price (0 if donate),
//   quantity, expiryDate, pickupTimes[] (multiple checkboxes),
//   photoUrl (text input — URL to uploaded image)
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

    if (!$itemName)                              $errors['itemName']         = 'Item name is required.';
    if (!$categoryId)                            $errors['categoryId']       = 'Please select a category.';
    if (!$pickupLocationId)                      $errors['pickupLocationId'] = 'Please select a pickup location.';
    if (!in_array($listingType, ['donate','sell'])) $errors['listingType']   = 'Please choose Donate or Sell.';
    if ($listingType === 'sell' && $price <= 0)  $errors['price']            = 'Please enter a valid price.';
    if ($quantity < 1)                           $errors['quantity']         = 'Quantity must be at least 1.';
    if (!$expiryDate)                            $errors['expiryDate']       = 'Expiry date is required.';
    if (empty($pickupTimes))                     $errors['pickupTimes']      = 'Select at least one pickup time.';

    if (empty($errors)) {
        (new Item())->create([
            'providerId'       => $providerId,
            'categoryId'       => $categoryId,
            'pickupLocationId' => $pickupLocationId,
            'itemName'         => $itemName,
            'description'      => $description,
            'photoUrl'         => $photoUrl,
            'expiryDate'       => $expiryDate,
            'listingType'      => $listingType,
            'price'            => $listingType === 'donate' ? 0 : $price,
            'quantity'         => $quantity,
            'pickupTimes'      => $pickupTimes,
            'isAvailable'      => true,
        ]);
        $success = true;
    }
}

// ── EXAMPLE: Category dropdown ──
// <select name="categoryId">
//   <?php foreach ($categories as $c): ?>
//     <option value="<?= $c['_id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
//   <?php endforeach; ?>
// </select>
//
// ── EXAMPLE: Location dropdown ──
// <select name="pickupLocationId">
//   <?php foreach ($locations as $l): ?>
//     <option value="<?= $l['_id'] ?>"><?= htmlspecialchars($l['label'].' — '.$l['street']) ?></option>
//   <?php endforeach; ?>
// </select>
//
// ── EXAMPLE: Listing type radio ──
// <input type="radio" name="listingType" value="sell" />  Sell
// <input type="radio" name="listingType" value="donate" /> Donate (Free)
//
// ── EXAMPLE: Pickup times checkboxes ──
// <?php foreach (['Morning (8-12)','Afternoon (12-5)','Evening (5-9)'] as $t): ?>
//   <label>
//     <input type="checkbox" name="pickupTimes[]" value="<?= $t ?>" />
//     <?= $t ?>
//   </label>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Add Item</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
