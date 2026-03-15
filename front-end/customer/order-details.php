<?php
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Order.php';
require_once '../../back-end/models/OrderItem.php';
require_once '../../back-end/models/Notification.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($amount): string { return number_format((float)$amount, 2) . ' SAR'; }
function fmtDateTime($value): string {
    if ($value instanceof MongoDB\BSON\UTCDateTime) {
        return $value->toDateTime()->format('j M Y g:ia');
    }
    return '-';
}

$customerId = $_SESSION['customerId'];
$customerName = $_SESSION['userName'] ?? 'Customer';
$orderId = trim($_GET['orderId'] ?? '');
$isNew = isset($_GET['new']);

$orderModel = new Order();
$orderItemModel = new OrderItem();
$notificationModel = new Notification();

$order = $orderId !== '' ? $orderModel->findById($orderId) : null;
if (!$order || (string)$order['customerId'] !== $customerId) {
    header('Location: orders.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    if (($order['orderStatus'] ?? '') === 'pending') {
        $orderModel->cancel($orderId);
    }
    header('Location: order-details.php?orderId=' . urlencode($orderId));
    exit;
}

$orderItems = $orderItemModel->getByOrder($orderId);
$notifications = $notificationModel->getByCustomer($customerId);
$unreadCount = $notificationModel->getUnreadCount($customerId);
$status = $order['orderStatus'] ?? 'pending';
$firstItem = $orderItems[0] ?? null;
$providerName = $firstItem['providerName'] ?? 'Provider';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RePlate - Order details</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root{--blue:#1d3e97;--light:#eef2f7;--card:#fff;--border:#d6deea;--orange:#f58b2d;--muted:#73829b}
    *{box-sizing:border-box} body{margin:0;background:var(--light);font-family:'DM Sans',sans-serif;color:#213153}
    .navbar{background:linear-gradient(90deg,#173b96,#6ca8ea);color:#fff;padding:14px 28px;display:flex;justify-content:space-between;align-items:center}
    .brand{display:flex;gap:14px;align-items:center;font-weight:700}.brand-badge{width:34px;height:34px;border-radius:10px;background:#fff;color:#173b96;display:grid;place-items:center}
    .nav-links{display:flex;gap:18px;font-size:14px}.nav-right{display:flex;gap:12px;align-items:center}
    .search{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:9px 14px;color:#fff;min-width:190px}
    .icon-btn{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;border:1px solid rgba(255,255,255,.45);background:rgba(255,255,255,.12)}
    .badge{position:relative}.badge span{position:absolute;top:-4px;right:-2px;background:var(--orange);color:#fff;border-radius:999px;font-size:10px;min-width:18px;height:18px;display:grid;place-items:center;padding:0 4px}
    .container{max-width:920px;margin:28px auto;padding:0 20px}
    .title{display:flex;gap:12px;align-items:center;font-family:'Playfair Display',serif;margin-bottom:18px}
    .title h1{margin:0;font-size:42px;color:var(--blue)} .back{width:34px;height:34px;border-radius:50%;background:#dfe8f6;display:grid;place-items:center;color:var(--blue);text-decoration:none}
    .banner{margin:16px auto 20px;max-width:520px;background:#fff;border:1px solid var(--border);border-radius:18px;padding:26px;text-align:center;box-shadow:0 18px 38px rgba(17,35,83,.12)}
    .banner h3{margin:0;color:var(--blue);font-family:'Playfair Display',serif;font-size:28px}
    .summary{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px 20px;display:grid;grid-template-columns:120px 1fr;gap:18px;align-items:center;margin-bottom:18px}
    .provider-logo{width:120px;height:120px;border-radius:18px;object-fit:cover;border:1px solid var(--border);padding:10px;background:#fff}
    .provider-name{font-family:'Playfair Display',serif;font-size:36px;color:#173b96}
    .status{display:inline-block;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700}
    .status.pending{background:#fff1df;color:#d47417}.status.completed{background:#e8f7ec;color:#1f8d48}.status.cancelled{background:#ffeaea;color:#c64c4c}
    .items{background:#fff;border:1px solid var(--border);border-radius:18px;padding:18px 18px 4px}
    .item-card{display:grid;grid-template-columns:90px 1fr auto;gap:16px;align-items:center;padding:14px;border:1px solid #e7edf5;border-radius:16px;margin-bottom:14px}
    .item-photo{width:90px;height:90px;border-radius:16px;object-fit:cover;background:#eef2f7;border:1px solid var(--border)}
    .item-name{font-weight:700;font-size:17px;color:#24345a;margin-bottom:6px}
    .item-desc{font-size:13px;color:var(--muted);line-height:1.5}
    .item-price{font-weight:700;color:#f08a2a}
    .info{margin-top:18px;padding-top:16px;border-top:1px solid #e9edf3;display:grid;gap:10px}
    .info-row strong{color:#173b96}
    .actions{margin-top:18px;display:flex;gap:12px;flex-wrap:wrap}
    .btn{border:none;border-radius:12px;padding:12px 18px;font-weight:700;cursor:pointer}
    .btn-primary{background:#173b96;color:#fff}.btn-cancel{background:#fff1f0;color:#b63a2c}.btn-secondary{background:#eef3fa;color:#173b96}
    .footer{margin-top:38px;background:linear-gradient(90deg,#2446ab,#6da9e9);color:#fff;text-align:center;padding:20px;font-size:13px}
    @media (max-width:760px){.summary,.item-card{grid-template-columns:1fr}.nav-links{display:none}.provider-logo,.item-photo{width:100%;height:180px}}
  </style>
</head>
<body>
<header class="navbar">
  <div class="brand">
    <div class="brand-badge">R</div>
    <div>RePlate</div>
    <nav class="nav-links">
      <a href="../shared/landing.php" style="color:#fff;text-decoration:none">Home</a>
      <a href="orders.php" style="color:#fff;text-decoration:none">Orders</a>
      <a href="cart.php" style="color:#fff;text-decoration:none">Cart</a>
    </nav>
  </div>
  <div class="nav-right">
    <input class="search" value="<?= e($customerName) ?>" readonly>
    <a class="icon-btn badge" href="orders.php">🔔<?php if ($unreadCount > 0): ?><span><?= (int)$unreadCount ?></span><?php endif; ?></a>
    <a class="icon-btn" href="customer-profile.php">👤</a>
  </div>
</header>

<main class="container">
  <div class="title">
    <a class="back" href="orders.php">‹</a>
    <h1>Order number: <?= e($order['orderNumber'] ?? '') ?></h1>
  </div>

  <?php if ($isNew): ?>
    <div class="banner">
      <h3>Order placed successfully!</h3>
      <p style="color:#65748d;margin-bottom:0;">Your order was saved and is now waiting for pickup.</p>
    </div>
  <?php endif; ?>

  <section class="summary">
    <?php if (!empty($firstItem['photoUrl'] ?? '')): ?>
      <img class="provider-logo" src="<?= e($firstItem['photoUrl']) ?>" alt="<?= e($providerName) ?>">
    <?php else: ?>
      <div class="provider-logo" style="display:grid;place-items:center;color:#173b96;font-weight:700"><?= e(substr($providerName,0,2)) ?></div>
    <?php endif; ?>
    <div>
      <div class="provider-name"><?= e($providerName) ?></div>
      <div style="margin-top:8px;"><span class="status <?= e($status) ?>"><?= e(ucfirst($status)) ?></span></div>
      <div style="margin-top:12px;color:#667692;">Placed at <?= e(fmtDateTime($order['placedAt'] ?? null)) ?></div>
    </div>
  </section>

  <section class="items">
    <?php foreach ($orderItems as $oi): ?>
      <div class="item-card">
        <?php if (!empty($oi['photoUrl'] ?? '')): ?>
          <img class="item-photo" src="<?= e($oi['photoUrl']) ?>" alt="<?= e($oi['itemName'] ?? 'Item') ?>">
        <?php else: ?>
          <div class="item-photo" style="display:grid;place-items:center;color:#8795ab;">No image</div>
        <?php endif; ?>

        <div>
          <div class="item-name"><?= e($oi['itemName'] ?? 'Item') ?></div>
          <div class="item-desc">
            Provider: <?= e($oi['providerName'] ?? 'Provider') ?><br>
            Quantity: <?= (int)($oi['quantity'] ?? 1) ?><br>
            Pickup time: <?= e($oi['selectedPickupTime'] ?? '-') ?><br>
            Pickup location: <?= e($oi['pickupLocation'] ?? '-') ?>
          </div>
        </div>

        <div class="item-price"><?= money(($oi['price'] ?? 0) * ($oi['quantity'] ?? 1)) ?></div>
      </div>
    <?php endforeach; ?>

    <div class="info">
      <div class="info-row"><strong>Total amount:</strong> <?= money($order['totalAmount'] ?? 0) ?></div>
      <div class="info-row"><strong>Payment method:</strong> <?= e(ucfirst($order['paymentMethod'] ?? 'Cash')) ?></div>
      <div class="info-row"><strong>Order status:</strong> <?= e(ucfirst($status)) ?></div>
    </div>

    <div class="actions">
      <a href="orders.php" class="btn btn-secondary" style="text-decoration:none;">Back to orders</a>
      <?php if ($status === 'pending'): ?>
        <form method="post" onsubmit="return confirm('Cancel this order?');" style="margin:0;">
          <input type="hidden" name="action" value="cancel">
          <button class="btn btn-cancel" type="submit">Cancel order</button>
        </form>
      <?php endif; ?>
    </div>
  </section>
</main>

<footer class="footer">© RePlate • Riyadh • hello@replate.com</footer>
</body>
</html>
