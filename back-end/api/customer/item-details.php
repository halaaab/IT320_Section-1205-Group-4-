<?php
// back-end/api/customer/item-details.php
// Called by: front-end/customer/item-details.html
// Method: GET  ?itemId=xxx
// Returns: item + provider info + pickup location

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error('Method not allowed.', 405);

$itemId = $_GET['itemId'] ?? '';
if (!$itemId) error('Item ID is required.');

$itemModel     = new Item();
$providerModel = new Provider();
$locationModel = new PickupLocation();
$categoryModel = new Category();

$item = $itemModel->findById($itemId);
if (!$item) error('Item not found.', 404);

// Get provider info
$provider = $providerModel->findById((string) $item['providerId']);
unset($provider['passwordHash']);

// Get pickup location
$location = $locationModel->findById((string) $item['pickupLocationId']);

// Get category name
$category = $categoryModel->findById((string) $item['categoryId']);

// Clean up IDs
$item['_id']              = (string) $item['_id'];
$item['providerId']       = (string) $item['providerId'];
$item['categoryId']       = (string) $item['categoryId'];
$item['pickupLocationId'] = (string) $item['pickupLocationId'];
$provider['_id']          = (string) $provider['_id'];

success([
    'item'     => $item,
    'provider' => $provider,
    'location' => $location,
    'category' => $category,
]);
