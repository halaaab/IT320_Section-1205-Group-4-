<?php
// ================================================================
// category.php — Items by Category
// ================================================================
// URL PARAMS:  ?categoryId=xxx  &type=all|donate|sell
// VARIABLES:
//   $category   → current category object { _id, name, icon }
//   $items      → array of items in this category
//   $type       → current filter: 'all' | 'donate' | 'sell'
//   $categories → all categories (for sidebar/tabs)
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Category.php';
require_once '../../back-end/models/Item.php';

$categoryModel = new Category();
$itemModel     = new Item();

$categoryId = $_GET['categoryId'] ?? '';
$type       = $_GET['type']       ?? 'all';

$category   = $categoryId ? $categoryModel->findById($categoryId) : null;
$categories = $categoryModel->getAll();

$items = [];
if ($categoryId) {
    $items = $itemModel->getByCategory($categoryId);
    if ($type !== 'all') {
        $items = array_values(array_filter($items, fn($i) => $i['listingType'] === $type));
    }
}

// ── EXAMPLE: Filter tabs in your HTML ──
// <a href="?categoryId=<?= $categoryId ?>&type=all"    class="<?= $type==='all'    ? 'active':'' ?>">All</a>
// <a href="?categoryId=<?= $categoryId ?>&type=sell"   class="<?= $type==='sell'   ? 'active':'' ?>">Buy</a>
// <a href="?categoryId=<?= $categoryId ?>&type=donate" class="<?= $type==='donate' ? 'active':'' ?>">Free</a>
//
// ── EXAMPLE: Item cards loop ──
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
  <title>RePlate – <?= htmlspecialchars($category['name'] ?? 'Category') ?></title>
  <!-- YOUR HTML HERE -->
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
