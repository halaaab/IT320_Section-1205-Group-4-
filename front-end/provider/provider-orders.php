<?php
// ================================================================
// provider-orders.php — Provider Orders List
// ================================================================
// VARIABLES:
//   $groupedOrders → orders grouped by status, each entry has:
//                    { order, myItems[] } — only this provider's items
//   $filter        → 'all' | 'pending' | 'completed'
// ================================================================

session_start();
require_once '../../../back-end/config/database.php';
require_once '../../../back-end/models/BaseModel.php';
require_once '../../../back-end/models/Order.php';
require_once '../../../back-end/models/OrderItem.php';

if (empty($_SESSION['providerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$providerId = $_SESSION['providerId'];
$filter     = $_GET['status'] ?? 'all';

// Get all order_items for this provider → group by orderId
$myOrderItems = (new OrderItem())->getByProvider($providerId);
$orderIds     = array_unique(array_map(fn($oi) => (string) $oi['orderId'], $myOrderItems));

$orderModel    = new Order();
$groupedOrders = [];

foreach ($orderIds as $oid) {
    $order = $orderModel->findById($oid);
    if (!$order) continue;
    if ($filter !== 'all' && $order['orderStatus'] !== $filter) continue;

    $myItems = array_values(array_filter(
        $myOrderItems,
        fn($oi) => (string) $oi['orderId'] === $oid
    ));

    $groupedOrders[] = [
        'order'   => $order,
        'myItems' => $myItems,
    ];
}

// Sort by placedAt descending
usort($groupedOrders, fn($a, $b) =>
    ($b['order']['placedAt']->toDateTime()->getTimestamp()) -
    ($a['order']['placedAt']->toDateTime()->getTimestamp())
);

// ── EXAMPLE: Orders loop ──
// <?php foreach ($groupedOrders as $g): ?>
//   <div>
//     <p>Order #<?= htmlspecialchars($g['order']['orderNumber']) ?></p>
//     <p>Status: <?= htmlspecialchars($g['order']['orderStatus']) ?></p>
//     <a href="provider-order-details.php?orderId=<?= $g['order']['_id'] ?>">View</a>
//   </div>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Provider Orders</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
