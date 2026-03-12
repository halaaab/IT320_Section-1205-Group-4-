<?php
// back-end/api/customer/favourites.php
// Called by: front-end/customer/favorites.html
// GET    → get all favourites
// POST   → add favourite    body: { itemId }
// DELETE → remove favourite body: { itemId }

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

$customerId  = requireCustomer();
$favModel    = new Favourite();
$itemModel   = new Item();
$method      = $_SERVER['REQUEST_METHOD'];

// ── GET all favourites with item details ──
if ($method === 'GET') {
    $favs  = $favModel->getByCustomer($customerId);
    $items = [];

    foreach ($favs as $fav) {
        $item = $itemModel->findById((string) $fav['itemId']);
        if ($item) {
            $item['_id']       = (string) $item['_id'];
            $item['savedAt']   = $fav['savedAt'];
            $item['favId']     = (string) $fav['_id'];
            $items[]           = $item;
        }
    }

    success(['favourites' => $items]);
}

// ── POST add to favourites ──
if ($method === 'POST') {
    $body   = getBody();
    $itemId = $body['itemId'] ?? '';
    if (!$itemId) error('itemId is required.');

    $result = $favModel->add($customerId, $itemId);
    if ($result === false) error('Item already in favourites.');

    success([], 'Added to favourites.');
}

// ── DELETE remove from favourites ──
if ($method === 'DELETE') {
    $body   = getBody();
    $itemId = $body['itemId'] ?? '';
    if (!$itemId) error('itemId is required.');

    $favModel->remove($customerId, $itemId);
    success([], 'Removed from favourites.');
}

error('Method not allowed.', 405);
