<?php
// ================================================================
// order-details.php — Single Order Detail
// ================================================================
// URL PARAMS:  ?orderId=xxx   (?new=1 means just placed)
// VARIABLES:
//   $order      → order document { orderNumber, totalAmount,
//                   orderStatus, placedAt, paymentMethod }
//   $orderItems → array of order_items for this order
//                 each: { itemName, providerName, price, quantity,
//                         pickupLocation, selectedPickupTime, photoUrl }
//   $isNew      → bool — true if just placed (show confirmation)
// POST ACTION:
//   action=cancel → cancels this order if still pending
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Order.php';
require_once '../../back-end/models/OrderItem.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId = $_SESSION['customerId'];
$orderId    = $_GET['orderId'] ?? '';
$isNew      = isset($_GET['new']);
$order      = null;
$orderItems = [];

if ($orderId) {
    $orderModel = new Order();
    $order      = $orderModel->findById($orderId);

    // Security: only the owner can view
    if ($order && (string) $order['customerId'] !== $customerId) {
        header('Location: orders.php');
        exit;
    }

    $orderItems = (new OrderItem())->getByOrder($orderId);

    // ── Handle cancel ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
        $orderModel->cancel($orderId, $customerId);
        header("Location: order-details.php?orderId=$orderId");
        exit;
    }
}

// ── EXAMPLE: Order details display ──
// <p>Order #<?= htmlspecialchars($order['orderNumber'] ?? '') ?></p>
// <p>Status: <?= htmlspecialchars($order['orderStatus'] ?? '') ?></p>
// <p>Total: <?= number_format($order['totalAmount'] ?? 0, 2) ?> SAR</p>
//
// <?php foreach ($orderItems as $oi): ?>
//   <div>
//     <span><?= htmlspecialchars($oi['itemName']) ?></span>
//     <span>by <?= htmlspecialchars($oi['providerName']) ?></span>
//     <span>Pickup: <?= htmlspecialchars($oi['pickupLocation']) ?></span>
//     <span>Time: <?= htmlspecialchars($oi['selectedPickupTime']) ?></span>
//     <span><?= $oi['price'] ?> SAR × <?= $oi['quantity'] ?></span>
//   </div>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Order Details</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
