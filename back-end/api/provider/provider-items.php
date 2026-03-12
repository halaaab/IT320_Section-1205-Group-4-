<?php
// back-end/api/provider/provider-items.php
// Called by: front-end/provider/provider-items.html      → GET (all items)
//            front-end/provider/provider-item-details.html → GET ?itemId=xxx
//            Toggle availability                           → PUT body: { itemId, isAvailable }
//            Delete item                                   → DELETE body: { itemId }

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

$providerId = requireProvider();
$itemModel  = new Item();
$method     = $_SERVER['REQUEST_METHOD'];

// ── GET single item detail ──
if ($method === 'GET' && !empty($_GET['itemId'])) {
    $item = $itemModel->findById($_GET['itemId']);
    if (!$item || (string) $item['providerId'] !== $providerId) error('Item not found.', 404);

    $locationModel = new PickupLocation();
    $categoryModel = new Category();

    $location        = $locationModel->findById((string) $item['pickupLocationId']);
    $category        = $categoryModel->findById((string) $item['categoryId']);
    $item['_id']     = (string) $item['_id'];

    success(['item' => $item, 'location' => $location, 'category' => $category]);
}

// ── GET all items for provider ──
if ($method === 'GET') {
    $items = $itemModel->getByProvider($providerId);
    foreach ($items as &$i) $i['_id'] = (string) $i['_id'];
    success(['items' => $items]);
}

// ── PUT toggle availability ──
if ($method === 'PUT') {
    $body   = getBody();
    $itemId = $body['itemId'] ?? '';
    if (!$itemId) error('itemId is required.');

    $item = $itemModel->findById($itemId);
    if (!$item || (string) $item['providerId'] !== $providerId) error('Item not found.', 404);

    $itemModel->updateById($itemId, ['isAvailable' => (bool) $body['isAvailable']]);
    success([], 'Item updated.');
}

// ── DELETE item ──
if ($method === 'DELETE') {
    $body   = getBody();
    $itemId = $body['itemId'] ?? '';
    if (!$itemId) error('itemId is required.');

    $item = $itemModel->findById($itemId);
    if (!$item || (string) $item['providerId'] !== $providerId) error('Item not found.', 404);

    $itemModel->deleteById($itemId);
    success([], 'Item deleted.');
}

error('Method not allowed.', 405);
