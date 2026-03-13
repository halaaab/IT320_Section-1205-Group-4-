<?php
// ================================================================
// landing.php — Home / Landing Page
// ================================================================
// VARIABLES AVAILABLE TO YOUR HTML:
//   $categories  → array of all categories from DB
//   $items       → array of available items (latest 20)
//   $providers   → array of all providers (latest 8)
//   $isLoggedIn  → bool — true if customer is logged in
//   $userName    → string — logged-in customer's name
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Category.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Provider.php';

$categoryModel = new Category();
$itemModel     = new Item();
$providerModel = new Provider();

$categories = $categoryModel->getAll();
$items      = $itemModel->getAvailable(['sort' => ['createdAt' => -1], 'limit' => 20]);
$providers  = $providerModel->findAll([], ['limit' => 8]);

$isLoggedIn = !empty($_SESSION['customerId']);
$userName   = $_SESSION['userName'] ?? '';

// ── EXAMPLE: How to loop items in your HTML ──
// <?php foreach ($items as $item): ?>
//   <div class="product-card">
//     <h3><?= htmlspecialchars($item['itemName']) ?></h3>
//     <p><?= $item['listingType'] === 'donate' ? 'Free' : $item['price'] . ' SAR' ?></p>
//     <a href="item-details.php?itemId=<?= $item['_id'] ?>">View</a>
//   </div>
// <?php endforeach; ?>

// ── EXAMPLE: How to loop categories ──
// <?php foreach ($categories as $cat): ?>
//   <a href="../customer/category.php?categoryId=<?= $cat['_id'] ?>">
//     <?= htmlspecialchars($cat['name']) ?>
//   </a>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Landing</title>
  <!-- YOUR HTML HERE -->
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
