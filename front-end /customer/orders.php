<?php
// ================================================================
// orders.php — Customer Orders List
// ================================================================
// VARIABLES:
//   $orders     → array of all orders for this customer
//                 each order: { _id, orderNumber, totalAmount,
//                   orderStatus, placedAt, completedAt }
//   $filter     → current status filter: 'all'|'pending'|'completed'|'cancelled'
// POST ACTION:
//   action=cancel & orderId=xxx → cancels a pending order
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Order.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId = $_SESSION['customerId'];
$orderModel = new Order();

// ── Handle cancel ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $orderId = $_POST['orderId'] ?? '';
    if ($orderId) $orderModel->cancel($orderId, $customerId);
    header('Location: orders.php');
    exit;
}

$filter = $_GET['status'] ?? 'all';
$orders = $orderModel->getByCustomer($customerId);

if ($filter !== 'all') {
    $orders = array_values(array_filter($orders, fn($o) => $o['orderStatus'] === $filter));
}

// ── EXAMPLE: Order list loop ──
// <?php foreach ($orders as $order): ?>
//   <a href="order-details.php?orderId=<?= $order['_id'] ?>">
//     <span>#<?= htmlspecialchars($order['orderNumber']) ?></span>
//     <span><?= number_format($order['totalAmount'], 2) ?> SAR</span>
//     <span><?= htmlspecialchars($order['orderStatus']) ?></span>
//   </a>
//   <?php if ($order['orderStatus'] === 'pending'): ?>
//     <form method="POST">
//       <input type="hidden" name="action"  value="cancel" />
//       <input type="hidden" name="orderId" value="<?= $order['_id'] ?>" />
//       <button type="submit">Cancel</button>
//     </form>
//   <?php endif; ?>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – My Orders</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
