<?php
// ================================================================
// providers-page.php — Single Provider Profile + Their Items
// ================================================================
// URL PARAMS:  ?providerId=xxx
// VARIABLES:
//   $provider   → provider object { businessName, businessDescription,
//                   businessLogo, category, phoneNumber }
//   $items      → available items listed by this provider
//   $location   → provider's default pickup location
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/PickupLocation.php';

$providerId    = $_GET['providerId'] ?? '';
$provider      = null;
$items         = [];
$location      = null;

if ($providerId) {
    $providerModel = new Provider();
    $provider      = $providerModel->findById($providerId);
    if ($provider) {
        unset($provider['passwordHash']);
        $items    = (new Item())->getByProvider($providerId);
        $items    = array_values(array_filter($items, fn($i) => $i['isAvailable']));
        $location = (new PickupLocation())->getDefault($providerId);
    }
}

// ── EXAMPLE: Provider header ──
// <h1><?= htmlspecialchars($provider['businessName'] ?? '') ?></h1>
// <p><?= htmlspecialchars($provider['category'] ?? '') ?></p>
// <p><?= htmlspecialchars($provider['businessDescription'] ?? '') ?></p>
//
// ── EXAMPLE: Items loop ──
// <?php foreach ($items as $item): ?>
//   <a href="item-details.php?itemId=<?= $item['_id'] ?>">
//     <h3><?= htmlspecialchars($item['itemName']) ?></h3>
//     <p><?= $item['listingType']==='donate' ? 'Free' : $item['price'].' SAR' ?></p>
//   </a>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – <?= htmlspecialchars($provider['businessName'] ?? 'Provider') ?></title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
