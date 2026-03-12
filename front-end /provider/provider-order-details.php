<?php
// ================================================================
// provider-order-details.php — Provider View of a Single Order
// ================================================================
// URL PARAMS:  ?orderId=xxx
// VARIABLES:
//   $order    → order document
//   $myItems  → only this provider's items in this order
// POST ACTION:
//   action=complete → marks order as completed
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
$orderId    = $_GET['orderId'] ?? '';
$order      = null;
$myItems    = [];

if ($orderId) {
    $orderModel = new Order();
    $order      = $orderModel->findById($orderId);

    $allItems = (new OrderItem())->getByOrder($orderId);
    $myItems  = array_values(array_filter(
        $allItems,
        fn($oi) => (string) $oi['providerId'] === $providerId
    ));

    // If this provider has no items in this order, redirect
    if ($order && empty($myItems)) {
        header('Location: provider-orders.php');
        exit;
    }

    // ── Handle mark complete ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete') {
        $orderModel->updateStatus($orderId, 'completed');
        header("Location: provider-order-details.php?orderId=$orderId");
        exit;
    }
}

// ── EXAMPLE: My items in this order ──
// <?php foreach ($myItems as $oi): ?>
//   <p><?= htmlspecialchars($oi['itemName']) ?> × <?= $oi['quantity'] ?></p>
//   <p>Pickup: <?= htmlspecialchars($oi['pickupLocation']) ?></p>
//   <p>Time: <?= htmlspecialchars($oi['selectedPickupTime']) ?></p>
// <?php endforeach; ?>
//
// <?php if ($order['orderStatus'] === 'pending'): ?>
//   <form method="POST">
//     <input type="hidden" name="action" value="complete" />
//     <button type="submit">Mark as Completed</button>
//   </form>
// <?php endif; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Order #<?= htmlspecialchars($order['orderNumber'] ?? '') ?></title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
