<?php
// back-end/api/provider/provider-orders.php
// Called by: front-end/provider/provider-orders.html       → GET (all orders)
//            front-end/provider/provider-order-details.html → GET ?orderId=xxx
//            Mark order complete                            → PUT body: { orderId }

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

$providerId     = requireProvider();
$orderItemModel = new OrderItem();
$orderModel     = new Order();
$notifModel     = new Notification();
$method         = $_SERVER['REQUEST_METHOD'];

// ── GET single order detail ──
if ($method === 'GET' && !empty($_GET['orderId'])) {
    $orderId    = $_GET['orderId'];
    $order      = $orderModel->findById($orderId);
    $orderItems = $orderItemModel->getByOrder($orderId);

    // Filter only items belonging to this provider
    $myItems = array_filter($orderItems, fn($i) => (string) $i['providerId'] === $providerId);
    $myItems = array_values($myItems);

    if (empty($myItems)) error('Order not found.', 404);

    $order['_id'] = (string) $order['_id'];
    foreach ($myItems as &$i) $i['_id'] = (string) $i['_id'];

    success(['order' => $order, 'items' => $myItems]);
}

// ── GET all orders for this provider ──
if ($method === 'GET') {
    $orderItems = $orderItemModel->getByProvider($providerId);

    // Group by orderId
    $grouped = [];
    foreach ($orderItems as $oi) {
        $orderId = (string) $oi['orderId'];
        if (!isset($grouped[$orderId])) {
            $order = $orderModel->findById($orderId);
            $grouped[$orderId] = [
                'orderId'     => $orderId,
                'orderNumber' => $order['orderNumber'] ?? '',
                'orderStatus' => $order['orderStatus'] ?? '',
                'placedAt'    => $order['placedAt']    ?? null,
                'items'       => [],
            ];
        }
        $oi['_id'] = (string) $oi['_id'];
        $grouped[$orderId]['items'][] = $oi;
    }

    success(['orders' => array_values($grouped)]);
}

// ── PUT mark order as completed ──
if ($method === 'PUT') {
    $body    = getBody();
    $orderId = $body['orderId'] ?? '';
    if (!$orderId) error('orderId is required.');

    $order = $orderModel->findById($orderId);
    if (!$order) error('Order not found.', 404);

    $orderModel->updateStatus($orderId, 'completed');

    // Notify customer
    $notifModel->create(
        (string) $order['customerId'],
        'order_completed',
        'Your order #' . $order['orderNumber'] . ' is ready for pickup! 🎉',
        ['orderId' => $orderId]
    );

    success([], 'Order marked as completed.');
}

error('Method not allowed.', 405);
