<?php
// back-end/api/customer/checkout.php
// Called by: front-end/customer/checkout.html
// Method: POST
// Body: { totalAmount }
// Flow: read cart → create order → create order_items → clear cart → send notification

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('Method not allowed.', 405);

$customerId = requireCustomer();
$body       = getBody();

// 1. Get customer's cart
$cartModel = new Cart();
$cart      = $cartModel->getOrCreate($customerId);

if (empty($cart['cartItems'])) error('Your cart is empty.');

// 2. Enrich cart items with pickup location snapshot
$itemModel     = new Item();
$locationModel = new PickupLocation();
$providerModel = new Provider();

$enrichedItems = [];
$total         = 0;

foreach ($cart['cartItems'] as $cartItem) {
    $item     = $itemModel->findById((string) $cartItem['itemId']);
    $location = $locationModel->findById((string) $item['pickupLocationId']);
    $provider = $providerModel->findById((string) $item['providerId']);

    if (!$item || !$item['isAvailable']) {
        error("Item \"{$cartItem['itemName']}\" is no longer available.");
    }
    if ($item['quantity'] < $cartItem['quantity']) {
        error("Not enough stock for \"{$cartItem['itemName']}\".");
    }

    $lineTotal = $cartItem['price'] * $cartItem['quantity'];
    $total    += $lineTotal;

    $enrichedItems[] = [
        'itemId'             => (string) $cartItem['itemId'],
        'providerId'         => (string) $cartItem['providerId'],
        'itemName'           => $cartItem['itemName'],
        'providerName'       => $provider['businessName'] ?? '',
        'photoUrl'           => $item['photoUrl'] ?? '',
        'price'              => $cartItem['price'],
        'quantity'           => $cartItem['quantity'],
        'pickupLocation'     => ($location['street'] ?? '') . ', ' . ($location['city'] ?? ''),
        'selectedPickupTime' => $body['selectedPickupTime'] ?? ($item['pickupTimes'][0] ?? ''),
    ];
}

// 3. Create order
$orderModel = new Order();
$orderId    = $orderModel->create($customerId, ['totalAmount' => $total]);

// 4. Create order_items
$orderItemModel = new OrderItem();
$orderItemModel->createFromCart($orderId, $enrichedItems);

// 5. Decrease stock for each item
foreach ($enrichedItems as $ei) {
    $itemModel->decreaseQuantity($ei['itemId'], $ei['quantity']);
}

// 6. Clear cart
$cartModel->clear($customerId);

// 7. Send notification
$notifModel = new Notification();
$notifModel->notifyOrderPlaced($customerId, $orderId);

success(['orderId' => $orderId], 'Order placed successfully!');
