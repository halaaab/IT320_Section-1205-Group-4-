<?php
// back-end/api/customer/cart.php
// Called by: front-end/customer/cart.html
// GET    → get cart contents
// POST   → add item       body: { itemId, providerId, quantity, itemName, price }
// PUT    → update qty     body: { itemId, quantity }
// DELETE → remove item    body: { itemId }

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

$customerId = requireCustomer();
$cartModel  = new Cart();
$method     = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $cart = $cartModel->getOrCreate($customerId);
    $cart['_id'] = (string) $cart['_id'];
    success(['cart' => $cart]);
}

if ($method === 'POST') {
    $body = getBody();
    if (empty($body['itemId'])) error('itemId is required.');
    $cartModel->addItem($customerId, $body);
    success([], 'Item added to cart.');
}

if ($method === 'PUT') {
    $body = getBody();
    if (empty($body['itemId']) || !isset($body['quantity'])) error('itemId and quantity required.');
    if ((int) $body['quantity'] <= 0) {
        $cartModel->removeItem($customerId, $body['itemId']);
        success([], 'Item removed from cart.');
    }
    $cartModel->updateQuantity($customerId, $body['itemId'], (int) $body['quantity']);
    success([], 'Cart updated.');
}

if ($method === 'DELETE') {
    $body = getBody();
    if (empty($body['itemId'])) error('itemId is required.');
    $cartModel->removeItem($customerId, $body['itemId']);
    success([], 'Item removed from cart.');
}

error('Method not allowed.', 405);
