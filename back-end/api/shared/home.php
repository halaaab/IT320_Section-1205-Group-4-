<?php
// back-end/api/shared/home.php
// Called by: front-end/shared/landing.html
// Method: GET
// Returns: categories list + featured items

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error('Method not allowed.', 405);

$categoryModel = new Category();
$itemModel     = new Item();

$categories = $categoryModel->getAll();
$items      = $itemModel->getAvailable(['sort' => ['createdAt' => -1], 'limit' => 20]);

// Attach category name to each item for display
foreach ($items as &$item) {
    $item['_id']        = (string) $item['_id'];
    $item['providerId'] = (string) $item['providerId'];
    $item['categoryId'] = (string) $item['categoryId'];
}

foreach ($categories as &$cat) {
    $cat['_id'] = (string) $cat['_id'];
}

success([
    'categories' => $categories,
    'items'      => $items,
]);
