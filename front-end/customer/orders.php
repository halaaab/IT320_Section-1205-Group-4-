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
function fmtDate($value): string {
    if ($value instanceof MongoDB\BSON\UTCDateTime) {
        return $value->toDateTime()->format('j M Y');
    }
    return '-';
}

$customerId = $_SESSION['customerId'];
$customerName = $_SESSION['userName'] ?? 'Customer';
$orderModel = new Order();
$orderItemModel = new OrderItem();
$notificationModel = new Notification();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $orderId = trim($_POST['orderId'] ?? '');
    if ($orderId !== '') {
        $order = $orderModel->findById($orderId);
        if ($order && (string)$order['customerId'] === $customerId && ($order['orderStatus'] ?? '') === 'pending') {
            $orderModel->cancel($orderId);
            $message = 'Order cancelled successfully.';
        }
    }
}

$tab = $_GET['tab'] ?? 'current';
$orders = $orderModel->getByCustomer($customerId);
$notifications = $notificationModel->getByCustomer($customerId);
$unreadCount = $notificationModel->getUnreadCount($customerId);

$displayOrders = [];
foreach ($orders as $order) {
    $status = $order['orderStatus'] ?? 'pending';
    $isCurrent = $status === 'pending';
    if (($tab === 'current' && !$isCurrent) || ($tab === 'previous' && $isCurrent)) {
        continue;
    }

    $items = $orderItemModel->getByOrder((string)$order['_id']);
    $first = $items[0] ?? null;

    $displayOrders[] = [
        'order' => $order,
        'first' => $first,
        'itemsCount' => count($items),
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RePlate - Orders</title>
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
    .container{max-width:860px;margin:28px auto;padding:0 20px}
    .title{display:flex;gap:12px;align-items:center;font-family:'Playfair Display',serif;margin-bottom:18px}
    .title h1{margin:0;font-size:42px;color:var(--blue)} .back{width:34px;height:34px;border-radius:50%;background:#dfe8f6;display:grid;place-items:center;color:var(--blue);text-decoration:none}
    .tabs{display:flex;gap:10px;margin:18px 0 22px}
    .tab{padding:12px 24px;border-radius:14px;border:1px solid #e0b17d;background:#fff;color:var(--blue);font-weight:700}
    .tab.active{background:var(--orange);color:#fff;border-color:var(--orange)}
    .list-wrap{background:#e9edf2;border-radius:18px;padding:16px;border:1px solid #dde4ee}
    .order-card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:14px;display:grid;grid-template-columns:90px 1fr auto;gap:14px;align-items:center;margin-bottom:14px}
    .order-card:last-child{margin-bottom:0}
    .logo{width:90px;height:90px;border-radius:16px;object-fit:cover;border:1px solid var(--border);padding:10px;background:#fff}
    .provider{font-weight:700;color:#24345a;margin-bottom:6px}
    .meta{font-size:13px;color:var(--muted);display:grid;gap:6px}
    .amount{font-weight:700;color:#f08a2a;text-align:right}
    .cancel-btn{margin-top:10px;border:none;background:#f6a55d;color:#fff;border-radius:10px;padding:10px 16px;font-weight:700;cursor:pointer}
    .status{display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700}
    .status.pending{background:#fff1df;color:#d47417}
    .status.completed{background:#e8f7ec;color:#1f8d48}
    .status.cancelled{background:#ffeaea;color:#c64c4c}
    .notice{padding:14px 16px;background:#eef5ff;border:1px solid #cfdcf0;color:#2d4a84;border-radius:14px;margin-bottom:14px}
    .empty{padding:28px;text-align:center;color:var(--muted)}
    .modal{position:fixed;inset:0;background:rgba(18,32,66,.35);display:none;align-items:center;justify-content:center;padding:20px}
    .modal-box{background:#fff;border-radius:18px;max-width:360px;width:100%;padding:24px;border:1px solid var(--border);box-shadow:0 20px 40px rgba(17,35,83,.18);text-align:center}
    .modal-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:18px}
    .btn{border:none;border-radius:12px;padding:12px 14px;font-weight:700;cursor:pointer}
    .btn-yes{background:#e8f7ec;color:#17743a}.btn-no{background:#fff1f0;color:#b63a2c}
    .footer{margin-top:38px;background:linear-gradient(90deg,#2446ab,#6da9e9);color:#fff;text-align:center;padding:20px;font-size:13px}
    @media (max-width:760px){.order-card{grid-template-columns:1fr;text-align:left}.amount{text-align:left}.nav-links{display:none}}
  </style>
</head>
<body>
<header class="navbar">
  <div class="brand">
    <div class="brand-badge">R</div>
    <div>RePlate</div>
    <nav class="nav-links">
      <a href="../shared/landing.php" style="color:#fff;text-decoration:none">Home</a>
      <a href="category.php" style="color:#fff;text-decoration:none">Categories</a>
      <a href="cart.php" style="color:#fff;text-decoration:none">Cart</a>
      <a href="contact.php" style="color:#fff;text-decoration:none">Contact us</a>
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
    <a class="back" href="../shared/landing.php">‹</a>
    <h1>Orders</h1>
  </div>

  <?php if ($message !== ''): ?><div class="notice"><?= e($message) ?></div><?php endif; ?>

  <div class="tabs">
    <a class="tab <?= $tab === 'current' ? 'active' : '' ?>" href="?tab=current">Currently</a>
    <a class="tab <?= $tab === 'previous' ? 'active' : '' ?>" href="?tab=previous">Previously</a>
  </div>

  <div class="list-wrap">
    <?php if (empty($displayOrders)): ?>
      <div class="empty">No <?= $tab === 'current' ? 'current' : 'previous' ?> orders found.</div>
    <?php else: ?>
      <?php foreach ($displayOrders as $entry):
        $order = $entry['order']; $first = $entry['first']; $status = $order['orderStatus'] ?? 'pending'; ?>
        <div class="order-card">
          <?php if (!empty($first['photoUrl'] ?? '')): ?>
            <img class="logo" src="<?= e($first['photoUrl']) ?>" alt="<?= e($first['providerName'] ?? 'Order') ?>">
          <?php else: ?>
            <div class="logo" style="display:grid;place-items:center;color:#173b96;font-weight:700"><?= e(substr($first['providerName'] ?? 'RP',0,2)) ?></div>
          <?php endif; ?>

          <div>
            <div class="provider"><?= e($first['providerName'] ?? 'Provider') ?></div>
            <div class="meta">
              <div>📅 <?= e(fmtDate($order['placedAt'] ?? null)) ?></div>
              <div>🛒 Order number <?= e($order['orderNumber'] ?? '') ?></div>
              <div>📦 <?= (int)$entry['itemsCount'] ?> item(s)</div>
              <div><span class="status <?= e($status) ?>"><?= e(ucfirst($status)) ?></span></div>
            </div>
          </div>

          <div>
            <div class="amount"><?= money($order['totalAmount'] ?? 0) ?></div>
            <a href="order-details.php?orderId=<?= e((string)$order['_id']) ?>" style="display:inline-block;margin-top:10px;color:#173b96;font-weight:700">View details</a>
            <?php if ($status === 'pending'): ?>
              <button class="cancel-btn" type="button" onclick="openCancelModal('<?= e((string)$order['_id']) ?>')">Cancel</button>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</main>

<div class="modal" id="cancelModal">
  <div class="modal-box">
    <h3 style="margin-top:0;font-family:'Playfair Display',serif;color:#173b96;">Cancel order?</h3>
    <p style="color:#5f6f8a;">Are you sure you want to cancel your order?</p>
    <form method="post">
      <input type="hidden" name="action" value="cancel">
      <input type="hidden" name="orderId" id="modalOrderId" value="">
      <div class="modal-actions">
        <button class="btn btn-yes" type="submit">Yes</button>
        <button class="btn btn-no" type="button" onclick="closeCancelModal()">No</button>
      </div>
    </form>
  </div>
</div>

<footer class="footer">© RePlate • Riyadh • hello@replate.com</footer>
<script>
  function openCancelModal(orderId){
    document.getElementById('modalOrderId').value = orderId;
    document.getElementById('cancelModal').style.display = 'flex';
  }
  function closeCancelModal(){
    document.getElementById('cancelModal').style.display = 'none';
  }
</script>
</body>
</html>
