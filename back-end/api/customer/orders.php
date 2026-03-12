<?php
// back-end/api/customer/orders.php
// Called by: front-end/customer/orders.html      → GET (all orders)
//            front-end/customer/order-details.html → GET ?orderId=xxx
//            Cancel order                          → DELETE body: { orderId }
// Method: GET | DELETE

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

$customerId     = requireCustomer();
$orderModel     = new Order();
$orderItemModel = new OrderItem();
$method         = $_SERVER['REQUEST_METHOD'];

// ── GET single order detail ──
if ($method === 'GET' && !empty($_GET['orderId'])) {
    $orderId = $_GET['orderId'];
    $order   = $orderModel->findById($orderId);

    if (!$order || (string) $order['customerId'] !== $customerId) {
        error('Order not found.', 404);
    }

    $items           = $orderItemModel->getByOrder($orderId);
    $order['_id']    = (string) $order['_id'];

    foreach ($items as &$item) {
        $item['_id']     = (string) $item['_id'];
        $item['orderId'] = (string) $item['orderId'];
    }

    success(['order' => $order, 'items' => $items]);
}

// ── GET all orders ──
if ($method === 'GET') {
    $orders = $orderModel->getByCustomer($customerId);
    foreach ($orders as &$o) {
        $o['_id'] = (string) $o['_id'];
    }
    success(['orders' => $orders]);
}

// ── DELETE — cancel order ──
if ($method === 'DELETE') {
    $body    = getBody();
    $orderId = $body['orderId'] ?? '';
    if (!$orderId) error('orderId is required.');

    $order = $orderModel->findById($orderId);
    if (!$order || (string) $order['customerId'] !== $customerId) error('Order not found.', 404);
    if ($order['orderStatus'] !== 'pending') error('Only pending orders can be cancelled.');

    $orderModel->cancel($orderId);

    // Notify customer
    $notifModel = new Notification();
    $notifModel->create($customerId, 'order_cancelled',
        'Your order #' . $order['orderNumber'] . ' has been cancelled.',
        ['orderId' => $orderId]
    );

    success([], 'Order cancelled.');
}

error('Method not allowed.', 405);
