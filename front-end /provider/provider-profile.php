<?php
// ================================================================
// provider-profile.php — View & Edit Provider Profile
// ================================================================
// VARIABLES:
//   $provider   → provider object { businessName, email,
//                   phoneNumber, businessDescription, category,
//                   businessLogo }
//   $locations  → array of this provider's pickup locations
//   $success    → bool — true after save
//   $errors     → field validation errors
// POST ACTIONS:
//   action=update_profile → updates name/description/phone/logo
//   action=add_location   → adds new pickup location
//   action=delete_location & locationId=xxx → removes a location
//   action=set_default    & locationId=xxx → sets as default
// ================================================================

session_start();
require_once '../../../back-end/config/database.php';
require_once '../../../back-end/models/BaseModel.php';
require_once '../../../back-end/models/Provider.php';
require_once '../../../back-end/models/PickupLocation.php';

if (empty($_SESSION['providerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$providerId    = $_SESSION['providerId'];
$providerModel = new Provider();
$locationModel = new PickupLocation();
$provider      = $providerModel->findById($providerId);
unset($provider['passwordHash']);

$locations = $locationModel->getByProvider($providerId);
$errors    = [];
$success   = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── Update profile ──
    if ($action === 'update_profile') {
        $businessName = trim($_POST['businessName'] ?? '');
        $phone        = trim($_POST['phoneNumber']  ?? '');
        $description  = trim($_POST['businessDescription'] ?? '');
        $logoUrl      = trim($_POST['businessLogo'] ?? '');

        if (!$businessName) $errors['businessName'] = 'Business name is required.';

        if (empty($errors)) {
            $providerModel->updateById($providerId, [
                'businessName'        => $businessName,
                'phoneNumber'         => $phone,
                'businessDescription' => $description,
                'businessLogo'        => $logoUrl,
            ]);
            $_SESSION['providerName'] = $businessName;
            $provider = $providerModel->findById($providerId);
            unset($provider['passwordHash']);
            $success = true;
        }
    }

    // ── Add pickup location ──
    if ($action === 'add_location') {
        $label  = trim($_POST['label']  ?? '');
        $street = trim($_POST['street'] ?? '');
        $city   = trim($_POST['city']   ?? '');

        if (!$street || !$city) {
            $errors['address'] = 'Street and city are required.';
        } else {
            $locationModel->create($providerId, [
                'label'     => $label ?: 'Branch',
                'street'    => $street,
                'city'      => $city,
                'lat'       => (float) ($_POST['lat'] ?? 0),
                'lng'       => (float) ($_POST['lng'] ?? 0),
                'isDefault' => false,
            ]);
            $locations = $locationModel->getByProvider($providerId);
        }
    }

    // ── Delete location ──
    if ($action === 'delete_location') {
        $locationId = $_POST['locationId'] ?? '';
        if ($locationId) $locationModel->deleteById($locationId);
        $locations = $locationModel->getByProvider($providerId);
    }

    // ── Set default location ──
    if ($action === 'set_default') {
        $locationId = $_POST['locationId'] ?? '';
        if ($locationId) {
            $locationModel->clearDefault($providerId);
            $locationModel->setAsDefault($locationId);
        }
        $locations = $locationModel->getByProvider($providerId);
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../shared/login.php');
    exit;
}

// ── EXAMPLE: Profile form ──
// <form method="POST">
//   <input type="hidden" name="action" value="update_profile" />
//   <input name="businessName" value="<?= htmlspecialchars($provider['businessName']) ?>" />
//   <input name="phoneNumber"  value="<?= htmlspecialchars($provider['phoneNumber'] ?? '') ?>" />
//   <textarea name="businessDescription"><?= htmlspecialchars($provider['businessDescription'] ?? '') ?></textarea>
//   <button type="submit">Save</button>
// </form>
//
// ── EXAMPLE: Location list ──
// <?php foreach ($locations as $loc): ?>
//   <p><?= htmlspecialchars($loc['label']) ?> — <?= htmlspecialchars($loc['street'].', '.$loc['city']) ?></p>
//   <p><?= $loc['isDefault'] ? '⭐ Default' : '' ?></p>
//   <form method="POST" style="display:inline">
//     <input type="hidden" name="action"     value="set_default" />
//     <input type="hidden" name="locationId" value="<?= $loc['_id'] ?>" />
//     <button type="submit">Set Default</button>
//   </form>
//   <form method="POST" style="display:inline">
//     <input type="hidden" name="action"     value="delete_location" />
//     <input type="hidden" name="locationId" value="<?= $loc['_id'] ?>" />
//     <button type="submit">Delete</button>
//   </form>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Provider Profile</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
