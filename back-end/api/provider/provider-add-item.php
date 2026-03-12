<?php
// back-end/api/provider/provider-add-item.php
// Called by: front-end/provider/provider-add-item.html
// GET  → get provider's pickup locations + categories (for dropdowns)
// POST → create new item
//        body: { categoryId, pickupLocationId, itemName, description,
//                photoUrl, expiryDate, listingType, price, quantity, pickupTimes[] }

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

$providerId = requireProvider();
$method     = $_SERVER['REQUEST_METHOD'];

// ── GET dropdown data ──
if ($method === 'GET') {
    $categoryModel = new Category();
    $locationModel = new PickupLocation();

    $categories = $categoryModel->getAll();
    $locations  = $locationModel->getByProvider($providerId);

    foreach ($categories as &$c) $c['_id'] = (string) $c['_id'];
    foreach ($locations  as &$l) $l['_id'] = (string) $l['_id'];

    success(['categories' => $categories, 'locations' => $locations]);
}

// ── POST create item ──
if ($method === 'POST') {
    $body = getBody();

    $required = ['categoryId', 'pickupLocationId', 'itemName', 'expiryDate', 'listingType', 'quantity'];
    foreach ($required as $field) {
        if (empty($body[$field])) error("$field is required.");
    }

    if (!in_array($body['listingType'], Item::LISTING_TYPES)) error('Invalid listing type.');
    if ($body['listingType'] === 'sell' && empty($body['price'])) error('Price is required for sell items.');

    $itemId = (new Item())->create($providerId, $body);
    success(['itemId' => $itemId], 'Item added successfully!');
}

error('Method not allowed.', 405);
