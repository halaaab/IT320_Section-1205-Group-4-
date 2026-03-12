<?php
// ================================================================
// provider-dashboard.php — Provider Home Dashboard
// ================================================================
// VARIABLES:
//   $stats        → { totalItems, activeItems, totalOrders, pendingOrders }
//   $expiringSoon → array of items expiring within 3 days
//   $recentOrders → array of latest 5 orders containing this provider's items
//   $providerName → logged-in provider's business name
// ================================================================

session_start();
require_once '../../../back-end/config/database.php';
require_once '../../../back-end/models/BaseModel.php';
require_once '../../../back-end/models/Item.php';
require_once '../../../back-end/models/Order.php';
require_once '../../../back-end/models/OrderItem.php';

if (empty($_SESSION['providerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$providerId   = $_SESSION['providerId'];
$providerName = $_SESSION['providerName'] ?? '';
$itemModel    = new Item();
$orderModel   = new Order();

$allItems     = $itemModel->getByProvider($providerId);
$activeItems  = array_filter($allItems, fn($i) => $i['isAvailable']);
$expiringSoon = $itemModel->getExpiringSoon($providerId, 3); // days threshold

// Orders where this provider has items
$recentOrderItems = (new OrderItem())->getByProvider($providerId);
$orderIds         = array_unique(array_column($recentOrderItems, 'orderId'));
$recentOrders     = [];
foreach (array_slice($orderIds, 0, 5) as $oid) {
    $o = $orderModel->findById((string) $oid);
    if ($o) $recentOrders[] = $o;
}

$stats = [
    'totalItems'    => count($allItems),
    'activeItems'   => count($activeItems),
    'totalOrders'   => count($orderIds),
    'pendingOrders' => count(array_filter($recentOrders, fn($o) => $o['orderStatus'] === 'pending')),
];

// ── EXAMPLE: Stats display ──
// <p>Total Items: <?= $stats['totalItems'] ?></p>
// <p>Active: <?= $stats['activeItems'] ?></p>
// <p>Total Orders: <?= $stats['totalOrders'] ?></p>
// <p>Pending Orders: <?= $stats['pendingOrders'] ?></p>
//
// ── EXAMPLE: Expiring soon ──
// <?php foreach ($expiringSoon as $item): ?>
//   <p><?= htmlspecialchars($item['itemName']) ?> — expires <?= $item['expiryDate'] ?></p>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Dashboard</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
