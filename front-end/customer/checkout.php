<?php
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/PickupLocation.php';
require_once '../../back-end/models/Order.php';
require_once '../../back-end/models/OrderItem.php';
require_once '../../back-end/models/Notification.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function money($amount): string { return number_format((float)$amount, 2) . ' SAR'; }

$customerId = $_SESSION['customerId'];
$customerName = $_SESSION['userName'] ?? 'Customer';

$cartModel = new Cart();
$itemModel = new Item();
$providerModel = new Provider();
$pickupLocationModel = new PickupLocation();
$orderModel = new Order();
$orderItemModel = new OrderItem();
$notificationModel = new Notification();

$cart       = $cartModel->getOrCreate($customerId);
$cartItems  = $cart['cartItems'] ?? [];
$notifications = $notificationModel->getByCustomer($customerId);
$unreadCount = $notificationModel->getUnreadCount($customerId);

$error = '';
$success = '';
$enriched = [];
$total = 0.0;

foreach ($cartItems as $ci) {
    $item = $itemModel->findById((string)$ci['itemId']);
    if (!$item) { continue; }

    $provider = $providerModel->findById((string)$ci['providerId']);
    $location = !empty($item['pickupLocationId']) ? $pickupLocationModel->findById((string)$item['pickupLocationId']) : null;
    $lineTotal = (float)($ci['price'] ?? 0) * (int)($ci['quantity'] ?? 1);
    $total += $lineTotal;

    $enriched[] = [
        'cartItem'      => $ci,
        'item'          => $item,
        'provider'      => $provider,
        'location'      => $location,
        'locationStr'   => $location ? trim(($location['street'] ?? '') . ', ' . ($location['city'] ?? '')) : 'Pickup location not available',
        'pickupTimes'   => $item['pickupTimes'] ?? [],
        'lineTotal'     => $lineTotal,
        'providerLogo'  => $provider['businessLogo'] ?? '',
        'providerName'  => $provider['businessName'] ?? 'Provider',
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($enriched)) {
        $error = 'Your cart is empty.';
    } else {
        $pickupSelections = $_POST['pickupTime'] ?? [];
        $orderItems = [];

        foreach ($enriched as $row) {
            $ci   = $row['cartItem'];
            $item = $row['item'];

            if (!$item || !($item['isAvailable'] ?? false)) {
                $error = 'One of the selected items is no longer available.';
                break;
            }

            $availableQty = (int)($item['quantity'] ?? 0);
            if ($availableQty < (int)$ci['quantity']) {
                $error = 'Not enough stock for ' . ($ci['itemName'] ?? 'an item') . '.';
                break;
            }

            $itemId = (string)$ci['itemId'];
            $pickupTime = trim($pickupSelections[$itemId] ?? '');
            if ($pickupTime === '') {
                $pickupTime = $row['pickupTimes'][0] ?? '';
            }

            $orderItems[] = [
                'itemId'             => $itemId,
                'providerId'         => (string)$ci['providerId'],
                'itemName'           => $ci['itemName'] ?? 'Item',
                'providerName'       => $row['providerName'],
                'photoUrl'           => $item['photoUrl'] ?? '',
                'price'              => (float)($ci['price'] ?? 0),
                'quantity'           => (int)($ci['quantity'] ?? 1),
                'pickupLocation'     => $row['locationStr'],
                'selectedPickupTime' => $pickupTime,
            ];
        }

        if ($error === '') {
            $orderId = $orderModel->create($customerId, ['totalAmount' => $total]);
            $orderItemModel->createFromCart($orderId, $orderItems);

            foreach ($orderItems as $oi) {
                $itemModel->decreaseQuantity($oi['itemId'], $oi['quantity']);
            }

            $cartModel->clear($customerId);
            $notificationModel->notifyOrderPlaced($customerId, $orderId);
            header('Location: order-details.php?orderId=' . urlencode($orderId) . '&new=1');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RePlate - Checkout</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root{--blue:#1d3e97;--light:#eef3fa;--border:#d7dfeb;--orange:#f58b2d;--muted:#70819d;--ink:#1f2f55}
    *{box-sizing:border-box} body{margin:0;background:#eef2f7;font-family:'DM Sans',sans-serif;color:var(--ink)}
    .navbar{background:linear-gradient(90deg,#173b96,#6ca8ea);color:#fff;padding:14px 28px;display:flex;justify-content:space-between;align-items:center}
    .nav-links{display:flex;gap:18px;font-size:14px}.brand{display:flex;gap:14px;align-items:center;font-weight:700}.brand-badge{width:34px;height:34px;border-radius:10px;background:#fff;color:#173b96;display:grid;place-items:center}
    .nav-right{display:flex;align-items:center;gap:12px}.search{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:9px 14px;color:#fff;min-width:190px}
    .icon-btn{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;border:1px solid rgba(255,255,255,.45);background:rgba(255,255,255,.12)}
    .badge{position:relative}.badge span{position:absolute;top:-4px;right:-2px;background:var(--orange);color:#fff;border-radius:999px;font-size:10px;min-width:18px;height:18px;display:grid;place-items:center;padding:0 4px}
    .container{max-width:1100px;margin:28px auto;padding:0 20px}
    .title{display:flex;gap:12px;align-items:center;font-family:'Playfair Display',serif;margin-bottom:18px}
    .title h1{margin:0;font-size:42px;color:var(--blue)} .back{width:34px;height:34px;border-radius:50%;background:#dfe8f6;display:grid;place-items:center;color:var(--blue);text-decoration:none}
    .layout{display:grid;grid-template-columns:1.25fr .8fr;gap:24px}
    .card{background:#fff;border:1px solid var(--border);border-radius:18px;box-shadow:0 10px 24px rgba(24,54,110,.08)}
    .order-card{display:grid;grid-template-columns:1.2fr .9fr;gap:16px;padding:18px;margin-bottom:18px}
    .shop-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}
    .shop-logo{width:84px;height:84px;border-radius:16px;object-fit:cover;border:1px solid var(--border);padding:10px;background:#fff}
    .shop-name{font-family:'Playfair Display',serif;color:#d54f3f;font-size:26px}
    .row{display:flex;justify-content:space-between;gap:14px;padding:8px 0;font-size:14px;border-bottom:1px solid #eef2f7}
    .row:last-child{border-bottom:none}
    .label{color:var(--blue);font-weight:700}.value{color:#55657f;text-align:right}
    .mini-map{border:1px solid var(--border);border-radius:16px;padding:12px;background:#fbfcfe}
    .map-box{height:160px;border-radius:14px;background:linear-gradient(135deg,#bfd3ec,#e3effa);display:grid;place-items:center;color:var(--blue);font-weight:700;margin-bottom:10px;text-align:center;padding:12px}
    select{width:100%;padding:11px 12px;border:1px solid var(--border);border-radius:12px;background:#fff;font:inherit}
    .summary{padding:22px;position:sticky;top:18px}
    .summary h3{margin:0 0 14px;font-family:'Playfair Display',serif;font-size:28px;color:var(--blue)}
    .summary-line{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #edf1f6;color:#5d6c87}.summary-line.total{border:none;font-size:22px;font-weight:700;color:var(--blue);padding-top:16px}
    .primary-btn{width:100%;border:none;border-radius:14px;background:var(--blue);color:#fff;padding:15px 18px;font-size:18px;font-weight:700;cursor:pointer;margin-top:16px}
    .empty,.message{padding:18px;border-radius:14px;margin-bottom:16px}.empty{background:#fff6e9;border:1px solid #f4d0a0;color:#8a5a12}.message.error{background:#fff1f0;border:1px solid #efb1ab;color:#b23a2c}
    .footer{margin-top:38px;background:linear-gradient(90deg,#2446ab,#6da9e9);color:#fff;text-align:center;padding:20px;font-size:13px}
    @media (max-width:900px){.layout,.order-card{grid-template-columns:1fr}.nav-links{display:none}}
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
      <a href="providers-list.php" style="color:#fff;text-decoration:none">Providers</a>
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
    <a class="back" href="cart.php">‹</a>
    <h1>Order details</h1>
  </div>

  <?php if ($error !== ''): ?><div class="message error"><?= e($error) ?></div><?php endif; ?>

  <div class="layout">
    <section>
      <?php if (empty($enriched)): ?>
        <div class="empty">Your cart is empty. Add items before placing an order.</div>
      <?php else: ?>
        <form method="post">
          <?php foreach ($enriched as $row): 
            $ci = $row['cartItem']; $item = $row['item']; $location = $row['location']; ?>
            <div class="card order-card">
              <div>
                <div class="shop-head">
                  <?php if (!empty($row['providerLogo'])): ?>
                    <img class="shop-logo" src="<?= e($row['providerLogo']) ?>" alt="<?= e($row['providerName']) ?>">
                  <?php else: ?>
                    <div class="shop-logo" style="display:grid;place-items:center;color:var(--blue);font-weight:700"><?= e(substr($row['providerName'],0,2)) ?></div>
                  <?php endif; ?>
                  <div>
                    <div class="shop-name"><?= e($row['providerName']) ?></div>
                    <div style="color:var(--muted);font-size:14px;">Review before placing order</div>
                  </div>
                </div>

                <div class="row"><div class="label">Order</div><div class="value"><?= e($ci['itemName'] ?? 'Item') ?></div></div>
                <div class="row"><div class="label">Quantity</div><div class="value"><?= (int)($ci['quantity'] ?? 1) ?></div></div>
                <div class="row"><div class="label">Price</div><div class="value"><?= money($ci['price'] ?? 0) ?></div></div>
                <div class="row"><div class="label">Line total</div><div class="value"><?= money($row['lineTotal']) ?></div></div>
                <div class="row"><div class="label">Payment method</div><div class="value">Cash</div></div>
              </div>

              <div class="mini-map">
                <div class="map-box">
                  Pickup location<br>
                  <?= e($location['label'] ?? 'Main Branch') ?>
                </div>
                <div style="font-size:13px;color:#5d6c87;margin-bottom:10px;">
                  <?= e($row['locationStr']) ?><br>
                  <?php if (!empty($location['coordinates']['lat']) && !empty($location['coordinates']['lng'])): ?>
                    Lat: <?= e($location['coordinates']['lat']) ?>, Lng: <?= e($location['coordinates']['lng']) ?>
                  <?php endif; ?>
                </div>
                <label style="font-size:13px;font-weight:700;color:var(--blue);display:block;margin-bottom:6px;">Pick up time</label>
                <select name="pickupTime[<?= e((string)$ci['itemId']) ?>]">
                  <?php foreach (($row['pickupTimes'] ?: ['Any available time']) as $time): ?>
                    <option value="<?= e($time) ?>"><?= e($time) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>
          <?php endforeach; ?>
          <button class="primary-btn" type="submit">Place order</button>
        </form>
      <?php endif; ?>
    </section>

    <aside class="card summary">
      <h3>Total Amount</h3>
      <div class="summary-line"><span>Items</span><strong><?= count($enriched) ?></strong></div>
      <div class="summary-line"><span>Payment</span><strong>Cash</strong></div>
      <div class="summary-line"><span>Delivery</span><strong>Pickup</strong></div>
      <div class="summary-line total"><span>Total</span><span><?= money($total) ?></span></div>
      <a href="cart.php" style="display:block;margin-top:12px;color:var(--blue);text-align:center;">Back to cart</a>
    </aside>
  </div>
</main>

<footer class="footer">© RePlate • Riyadh • hello@replate.com</footer>
</body>
</html>
