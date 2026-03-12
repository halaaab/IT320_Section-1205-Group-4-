<?php
// back-end/api/customer/category.php
// Called by: front-end/customer/category.html
// Method: GET  ?categoryId=xxx&type=all|donate|sell
// Returns: items in a category

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error('Method not allowed.', 405);

$categoryId = $_GET['categoryId'] ?? '';
$type       = $_GET['type']       ?? 'all'; // all | donate | sell

if (!$categoryId) error('Category ID is required.');

$itemModel = new Item();

$filter = ['isAvailable' => true];
if ($type !== 'all') $filter['listingType'] = $type;

$items = $itemModel->getByCategory($categoryId);

// Filter by type if needed
if ($type !== 'all') {
    $items = array_filter($items, fn($i) => $i['listingType'] === $type);
    $items = array_values($items);
}

foreach ($items as &$item) {
    $item['_id']        = (string) $item['_id'];
    $item['providerId'] = (string) $item['providerId'];
}

success(['items' => $items]);
