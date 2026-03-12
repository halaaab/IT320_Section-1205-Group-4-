<?php
// back-end/api/provider/provider-dashboard.php
// Called by: front-end/provider/provider-dashboard.html
// Method: GET
// Returns: summary stats + recent orders + low stock items

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error('Method not allowed.', 405);

$providerId     = requireProvider();
$itemModel      = new Item();
$orderItemModel = new OrderItem();
$orderModel     = new Order();

// All items by this provider
$allItems    = $itemModel->getByProvider($providerId);
$activeItems = array_filter($allItems, fn($i) => $i['isAvailable']);
$totalItems  = count($allItems);
$activeCount = count($activeItems);

// All order_items for this provider
$orderItems    = $orderItemModel->getByProvider($providerId);
$totalOrders   = count($orderItems);
$totalRevenue  = array_sum(array_map(fn($oi) => $oi['price'] * $oi['quantity'], $orderItems));

// Items expiring soon
$expiringSoon = $itemModel->getExpiringSoon(24);
$expiringSoon = array_filter($expiringSoon, fn($i) => (string) $i['providerId'] === $providerId);
$expiringSoon = array_values($expiringSoon);

foreach ($allItems as &$i)      $i['_id'] = (string) $i['_id'];
foreach ($expiringSoon as &$i)  $i['_id'] = (string) $i['_id'];
foreach ($orderItems as &$oi)   $oi['_id'] = (string) $oi['_id'];

success([
    'stats' => [
        'totalItems'   => $totalItems,
        'activeItems'  => $activeCount,
        'totalOrders'  => $totalOrders,
        'totalRevenue' => $totalRevenue,
    ],
    'expiringSoon' => $expiringSoon,
    'recentOrders' => array_slice($orderItems, 0, 5),
]);
