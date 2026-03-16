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

$customerId = $_SESSION['customerId'];
$cartModel  = new Cart();
$cart       = $cartModel->getOrCreate($customerId);
$cartItems  = $cart['cartItems'] ?? [];

$error = '';
$total = 0.0;

$itemModel           = new Item();
$providerModel       = new Provider();
$pickupLocationModel = new PickupLocation();
$orderModel          = new Order();
$orderItemModel      = new OrderItem();
$notificationModel   = new Notification();

$enriched = [];

foreach ($cartItems as $ci) {
    $item = $itemModel->findById((string)($ci['itemId'] ?? ''));

    if (!$item) {
        continue;
    }

    $providerId = (string)($ci['providerId'] ?? ($item['providerId'] ?? ''));
    $provider   = $providerId ? $providerModel->findById($providerId) : null;

    $location = null;

    if (!empty($item['pickupLocationId'])) {
        $location = $pickupLocationModel->findById((string)$item['pickupLocationId']);
    }

    if (!$location && $providerId) {
        $location = $pickupLocationModel->getDefault($providerId);
    }

    $lineTotal = ((float)($ci['price'] ?? 0)) * ((int)($ci['quantity'] ?? 1));
    $total += $lineTotal;

    $locationStr = '';
    if ($location) {
        $parts = array_filter([
            $location['street'] ?? '',
            $location['city'] ?? '',
            $location['zip'] ?? '',
        ]);
        $locationStr = implode(', ', $parts);
    }

    $pickupTimes = [];
    if (!empty($item['pickupTimes']) && is_array($item['pickupTimes'])) {
        $pickupTimes = array_values(array_filter($item['pickupTimes']));
    }

    $enriched[] = [
        'cartItem'    => $ci,
        'item'        => $item,
        'provider'    => $provider,
        'providerId'  => $providerId,
        'location'    => $location,
        'locationStr' => $locationStr,
        'pickupTimes' => $pickupTimes,
        'lineTotal'   => $lineTotal,
    ];
}

/*
|--------------------------------------------------------------------------
| Group cart items by provider
|--------------------------------------------------------------------------
*/
$groupedByProvider = [];

foreach ($enriched as $entry) {
    $providerId = $entry['providerId'] ?: 'unknown_provider';

    if (!isset($groupedByProvider[$providerId])) {
        $mergedPickupTimes = $entry['pickupTimes'];

        $groupedByProvider[$providerId] = [
            'providerId'    => $providerId,
            'provider'      => $entry['provider'],
            'location'      => $entry['location'],
            'locationStr'   => $entry['locationStr'],
            'items'         => [],
            'pickupTimes'   => $mergedPickupTimes,
            'groupSubtotal' => 0.0,
        ];
    } else {
        $groupedByProvider[$providerId]['pickupTimes'] = array_values(array_unique(array_merge(
            $groupedByProvider[$providerId]['pickupTimes'],
            $entry['pickupTimes']
        )));
    }

    $groupedByProvider[$providerId]['items'][] = $entry;
    $groupedByProvider[$providerId]['groupSubtotal'] += $entry['lineTotal'];
}

/*
|--------------------------------------------------------------------------
| Handle POST: Place order
|--------------------------------------------------------------------------
| selectedPickupTime is now per provider:
| selectedPickupTime[providerId] = "4:00 PM - 6:00 PM"
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($enriched)) {
        $error = 'Your cart is empty.';
    } else {
        $selectedPickupTimes = $_POST['selectedPickupTime'] ?? [];
        $orderItems = [];

        foreach ($groupedByProvider as $providerId => $group) {
            $providerSelectedTime = trim($selectedPickupTimes[$providerId] ?? '');

            foreach ($group['items'] as $entry) {
                $ci   = $entry['cartItem'];
                $item = $itemModel->findById((string)($ci['itemId'] ?? ''));

                if (!$item) {
                    $error = 'One of the items in your cart no longer exists.';
                    break 2;
                }

                if (empty($item['isAvailable'])) {
                    $error = 'Item "' . ($ci['itemName'] ?? 'Unknown Item') . '" is no longer available.';
                    break 2;
                }

                $requestedQty = (int)($ci['quantity'] ?? 1);
                $availableQty = (int)($item['quantity'] ?? 0);

                if ($availableQty < $requestedQty) {
                    $error = 'Not enough stock for "' . ($ci['itemName'] ?? 'Unknown Item') . '".';
                    break 2;
                }

                $fallbackPickupTime = $entry['pickupTimes'][0] ?? 'Anytime';

                $orderItems[] = [
                    'itemId'             => (string)$ci['itemId'],
                    'providerId'         => (string)$ci['providerId'],
                    'itemName'           => $ci['itemName'] ?? '',
                    'providerName'       => $entry['provider']['businessName'] ?? '',
                    'photoUrl'           => $item['photoUrl'] ?? '',
                    'price'              => (float)($ci['price'] ?? 0),
                    'quantity'           => (int)($ci['quantity'] ?? 1),
                    'pickupLocation'     => $entry['locationStr'] ?: 'Provider pickup location',
                    'selectedPickupTime' => $providerSelectedTime ?: $fallbackPickupTime,
                ];
            }
        }

        if (!$error) {
            $orderId = $orderModel->create($customerId, [
                'totalAmount' => $total,
            ]);

            $orderItemModel->createFromCart($orderId, $orderItems);

            foreach ($orderItems as $oi) {
                $itemModel->decreaseQuantity($oi['itemId'], (int)$oi['quantity']);
            }

            $cartModel->clear($customerId);
            $notificationModel->notifyOrderPlaced($customerId, $orderId);

            header("Location: order-details.php?orderId={$orderId}&new=1");
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>RePlate – Checkout</title>

  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

  <style>
    :root {
      --blue: #051e6b;
      --orange: #f67b1c;
      --cream: #fffaf5;
      --text: #12223b;
      --muted: #6e7583;
      --line: #e6e8ee;
      --white: #ffffff;
      --card-shadow: 0 12px 32px rgba(5, 30, 107, 0.08);
      --radius-xl: 28px;
      --radius-lg: 22px;
      --radius-md: 16px;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
    }

    html, body {
      min-height: 100%;
    }

    body {
      font-family: 'Inter', sans-serif;
      color: var(--text);
      background: #f7f8fc;
    }

    a {
      text-decoration: none;
      color: inherit;
    }

    img {
      max-width: 100%;
      display: block;
    }

    .site-header {
      background: var(--white);
      border-bottom: 1px solid var(--line);
      position: sticky;
      top: 0;
      z-index: 50;
    }

    .site-header .inner {
      width: min(1200px, calc(100% - 32px));
      margin: 0 auto;
      min-height: 84px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
    }

    .brand {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .brand img {
      height: 42px;
      object-fit: contain;
    }

    .brand span {
      font-family: 'Playfair Display', serif;
      font-size: 1.6rem;
      font-weight: 700;
      color: var(--blue);
    }

    .nav-links {
      display: flex;
      align-items: center;
      gap: 22px;
      flex-wrap: wrap;
    }

    .nav-links a {
      color: var(--blue);
      font-weight: 600;
      font-size: 0.98rem;
    }

    .nav-links a.active {
      color: var(--orange);
    }

    .page-shell {
      width: min(1200px, calc(100% - 32px));
      margin: 28px auto 48px;
    }

    .breadcrumb {
      margin-bottom: 18px;
      color: var(--muted);
      font-size: 0.95rem;
    }

    .page-title-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 24px;
      flex-wrap: wrap;
    }

    .page-title-row h1 {
      font-family: 'Playfair Display', serif;
      color: var(--blue);
      font-size: clamp(2rem, 4vw, 2.8rem);
      line-height: 1.05;
    }

    .checkout-grid {
      display: grid;
      grid-template-columns: 1.55fr 0.85fr;
      gap: 28px;
      align-items: start;
    }

    .main-column {
      display: flex;
      flex-direction: column;
      gap: 22px;
    }

    .summary-column {
      position: sticky;
      top: 110px;
    }

    .section-card {
      background: var(--white);
      border: 1px solid var(--line);
      border-radius: var(--radius-xl);
      box-shadow: var(--card-shadow);
      padding: 24px;
    }

    .section-title {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 18px;
    }

    .section-title h2 {
      font-family: 'Playfair Display', serif;
      color: var(--blue);
      font-size: 1.6rem;
    }

    .section-title p {
      color: var(--muted);
      font-size: 0.95rem;
    }

    .provider-block {
      border: 1px solid var(--line);
      border-radius: 24px;
      overflow: hidden;
      background: #fcfdff;
      margin-bottom: 20px;
    }

    .provider-block:last-child {
      margin-bottom: 0;
    }

    .provider-head {
      padding: 20px 20px 16px;
      background: linear-gradient(180deg, #f6f9ff 0%, #ffffff 100%);
      border-bottom: 1px solid var(--line);
    }

    .provider-head-top {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 14px;
      margin-bottom: 10px;
      flex-wrap: wrap;
    }

    .provider-name {
      font-family: 'Playfair Display', serif;
      color: var(--blue);
      font-size: 1.45rem;
      font-weight: 700;
    }

    .provider-tag {
      background: rgba(246, 123, 28, 0.12);
      color: var(--orange);
      padding: 8px 14px;
      border-radius: 999px;
      font-weight: 700;
      font-size: 0.85rem;
      white-space: nowrap;
    }

    .pickup-address {
      color: var(--muted);
      font-size: 0.98rem;
      line-height: 1.6;
      margin-bottom: 16px;
    }

    .map-wrap {
      border-radius: 20px;
      overflow: hidden;
      border: 1px solid #d9e0ef;
      background: #edf3ff;
      height: 280px;
      position: relative;
    }

    .provider-map {
      width: 100%;
      height: 100%;
    }

    .map-caption {
      margin-top: 10px;
      color: var(--muted);
      font-size: 0.88rem;
    }

    .provider-body {
      padding: 18px 20px 20px;
      display: grid;
      gap: 14px;
    }

    .provider-items-title {
      font-weight: 800;
      color: var(--blue);
      font-size: 1rem;
      margin-bottom: 2px;
    }

    .checkout-item {
      display: grid;
      grid-template-columns: 84px 1fr auto;
      gap: 14px;
      align-items: center;
      background: var(--white);
      border: 1px solid var(--line);
      border-radius: 18px;
      padding: 12px;
    }

    .checkout-item .item-thumb {
      width: 84px;
      height: 84px;
      border-radius: 16px;
      overflow: hidden;
      background: #f2f4f8;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .checkout-item .item-thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .checkout-item .item-info h4 {
      font-size: 1rem;
      margin-bottom: 6px;
      color: var(--text);
      font-weight: 700;
    }

    .checkout-item .item-info .meta {
      color: var(--muted);
      font-size: 0.92rem;
      line-height: 1.55;
    }

    .checkout-item .item-price {
      text-align: right;
      min-width: 110px;
    }

    .checkout-item .item-price .line-total {
      color: var(--blue);
      font-weight: 800;
      font-size: 1rem;
      margin-bottom: 6px;
    }

    .checkout-item .item-price .unit {
      color: var(--muted);
      font-size: 0.86rem;
    }

    .pickup-time-box {
      margin-top: 6px;
      display: grid;
      gap: 10px;
    }

    .pickup-time-box label {
      color: var(--blue);
      font-weight: 700;
      font-size: 0.96rem;
    }

    .pickup-time-box select {
      width: 100%;
      border: 1px solid #d4d9e5;
      background: #fff;
      border-radius: 14px;
      padding: 12px 14px;
      font: inherit;
      color: var(--text);
      outline: none;
    }

    .pickup-time-box select:focus {
      border-color: var(--orange);
      box-shadow: 0 0 0 4px rgba(246, 123, 28, 0.12);
    }

    .order-summary-card {
      background: var(--white);
      border: 1px solid var(--line);
      border-radius: var(--radius-xl);
      box-shadow: var(--card-shadow);
      padding: 24px;
    }

    .order-summary-card h3 {
      font-family: 'Playfair Display', serif;
      color: var(--blue);
      font-size: 1.55rem;
      margin-bottom: 18px;
    }

    .summary-list {
      display: grid;
      gap: 14px;
      margin-bottom: 18px;
    }

    .summary-row {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      color: var(--text);
      font-size: 0.97rem;
    }

    .summary-row span:last-child {
      font-weight: 700;
      color: var(--blue);
    }

    .summary-total {
      border-top: 1px dashed #d8dbe4;
      padding-top: 16px;
      margin-top: 8px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      font-size: 1.08rem;
      font-weight: 800;
      color: var(--blue);
    }

    .summary-note {
      margin-top: 14px;
      color: var(--muted);
      font-size: 0.9rem;
      line-height: 1.6;
    }

    .primary-btn,
    .secondary-btn {
      width: 100%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-height: 54px;
      border-radius: 999px;
      font-weight: 800;
      font-size: 1rem;
      cursor: pointer;
      transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
      border: none;
      margin-top: 18px;
    }

    .primary-btn {
      background: var(--orange);
      color: #fff;
      box-shadow: 0 16px 30px rgba(246, 123, 28, 0.25);
    }

    .primary-btn:hover {
      transform: translateY(-1px);
    }

    .secondary-btn {
      background: transparent;
      border: 2px solid var(--blue);
      color: var(--blue);
      margin-top: 12px;
    }

    .secondary-btn:hover {
      background: rgba(5, 30, 107, 0.04);
    }

    .empty-state {
      text-align: center;
      padding: 48px 22px;
    }

    .empty-state h2 {
      font-family: 'Playfair Display', serif;
      color: var(--blue);
      font-size: 2rem;
      margin-bottom: 12px;
    }

    .empty-state p {
      color: var(--muted);
      margin-bottom: 22px;
      line-height: 1.7;
    }

    .alert-error {
      border: 1px solid #f4b7b7;
      background: #fff3f3;
      color: #9b1c1c;
      border-radius: 18px;
      padding: 16px 18px;
      margin-bottom: 18px;
      font-weight: 600;
      line-height: 1.55;
    }

    .site-footer {
      background: var(--blue);
      color: #fff;
      margin-top: 48px;
    }

    .site-footer .inner {
      width: min(1200px, calc(100% - 32px));
      margin: 0 auto;
      padding: 24px 0;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      flex-wrap: wrap;
    }

    .footer-brand {
      display: flex;
      align-items: center;
      gap: 10px;
      font-family: 'Playfair Display', serif;
      font-weight: 700;
      font-size: 1.25rem;
    }

    .footer-brand img {
      height: 34px;
      object-fit: contain;
    }

    .footer-links {
      display: flex;
      gap: 18px;
      flex-wrap: wrap;
      font-size: 0.95rem;
      opacity: 0.96;
    }

    @media (max-width: 992px) {
      .checkout-grid {
        grid-template-columns: 1fr;
      }

      .summary-column {
        position: static;
      }
    }

    @media (max-width: 720px) {
      .site-header .inner,
      .site-footer .inner,
      .page-shell {
        width: min(100% - 20px, 1200px);
      }

      .checkout-item {
        grid-template-columns: 72px 1fr;
      }

      .checkout-item .item-price {
        grid-column: 2 / 3;
        text-align: left;
        min-width: auto;
      }

      .provider-head-top {
        align-items: stretch;
      }

      .map-wrap {
        height: 230px;
      }
    }
  </style>
</head>
<body>

<header class="site-header">
  <div class="inner">
    <a class="brand" href="../shared/landing.php">
      <img src="../../images/Replate-logo.png" alt="RePlate">
      <span>RePlate</span>
    </a>

    <nav class="nav-links">
      <a href="../shared/landing.php">Home</a>
      <a href="providers-list.php">Providers</a>
      <a href="cart.php">Cart</a>
      <a href="checkout.php" class="active">Checkout</a>
      <a href="orders.php">Orders</a>
      <a href="customer-profile.php">Profile</a>
    </nav>
  </div>
</header>

<div class="page-shell">
  <div class="breadcrumb">Home / Cart / <strong>Checkout</strong></div>

  <div class="page-title-row">
    <h1>Checkout</h1>
  </div>

  <?php if ($error): ?>
    <div class="alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (empty($enriched)): ?>
    <div class="section-card empty-state">
      <h2>Your cart is empty</h2>
      <p>Add items to your cart first, then come back here to review provider pickup locations and place your order.</p>
      <a class="primary-btn" href="providers-list.php" style="max-width: 280px; margin: 0 auto;">Browse Providers</a>
    </div>
  <?php else: ?>
    <form method="POST">
      <div class="checkout-grid">
        <div class="main-column">

          <div class="section-card">
            <div class="section-title">
              <div>
                <h2>Pickup by Provider</h2>
                <p>Each provider section shows one read-only pickup map using the provider’s saved location.</p>
              </div>
            </div>

            <?php foreach ($groupedByProvider as $providerId => $group): ?>
              <?php
                $provider = $group['provider'];
                $location = $group['location'];

                $providerName = $provider['businessName'] ?? 'Provider';
                $providerCategory = $provider['category'] ?? 'Pickup';
                $mapId = 'providerMap_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $providerId);

                $lat = $location['coordinates']['lat'] ?? null;
                $lng = $location['coordinates']['lng'] ?? null;

                $times = $group['pickupTimes'];
                if (empty($times)) {
                    $times = ['Anytime'];
                }
              ?>
              <section class="provider-block">
                <div class="provider-head">
                  <div class="provider-head-top">
                    <div>
                      <div class="provider-name"><?= htmlspecialchars($providerName) ?></div>
                    </div>
                    <div class="provider-tag"><?= htmlspecialchars($providerCategory) ?></div>
                  </div>

                  <div class="pickup-address">
                    <?= htmlspecialchars($group['locationStr'] ?: 'Pickup location available after provider setup.') ?>
                  </div>

                  <?php if ($lat !== null && $lng !== null): ?>
                    <div class="map-wrap">
                      <div id="<?= htmlspecialchars($mapId) ?>" class="provider-map"></div>
                    </div>
                    <div class="map-caption">
                      View-only provider pickup location.
                    </div>
                  <?php else: ?>
                    <div class="map-wrap" style="display:flex; align-items:center; justify-content:center; color:#6e7583; font-weight:600;">
                      Location coordinates are not available.
                    </div>
                  <?php endif; ?>
                </div>

                <div class="provider-body">
                  <div class="provider-items-title">Items from <?= htmlspecialchars($providerName) ?></div>

                  <?php foreach ($group['items'] as $entry): ?>
                    <?php
                      $ci   = $entry['cartItem'];
                      $item = $entry['item'];
                      $thumb = $item['photoUrl'] ?? '';
                    ?>
                    <div class="checkout-item">
                      <div class="item-thumb">
                        <?php if ($thumb): ?>
                          <img src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($ci['itemName'] ?? 'Item') ?>">
                        <?php else: ?>
                          <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#8b94a7;font-size:.9rem;">No image</div>
                        <?php endif; ?>
                      </div>

                      <div class="item-info">
                        <h4><?= htmlspecialchars($ci['itemName'] ?? 'Item') ?></h4>
                        <div class="meta">
                          Quantity: <?= (int)($ci['quantity'] ?? 1) ?><br>
                          Pickup from: <?= htmlspecialchars($group['locationStr']) ?>
                        </div>
                      </div>

                      <div class="item-price">
                        <div class="line-total"><?= number_format((float)$entry['lineTotal'], 2) ?> SAR</div>
                        <div class="unit"><?= number_format((float)($ci['price'] ?? 0), 2) ?> SAR each</div>
                      </div>
                    </div>
                  <?php endforeach; ?>

                  <div class="pickup-time-box">
                    <label for="pickup_<?= htmlspecialchars($providerId) ?>">Select pickup time for <?= htmlspecialchars($providerName) ?></label>
                    <select
                      id="pickup_<?= htmlspecialchars($providerId) ?>"
                      name="selectedPickupTime[<?= htmlspecialchars($providerId) ?>]"
                      required
                    >
                      <?php foreach ($times as $time): ?>
                        <option value="<?= htmlspecialchars($time) ?>"><?= htmlspecialchars($time) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </section>
            <?php endforeach; ?>
          </div>
        </div>

        <aside class="summary-column">
          <div class="order-summary-card">
            <h3>Order Summary</h3>

            <div class="summary-list">
              <div class="summary-row">
                <span>Providers</span>
                <span><?= count($groupedByProvider) ?></span>
              </div>

              <div class="summary-row">
                <span>Total Items</span>
                <span><?= count($enriched) ?></span>
              </div>

              <?php foreach ($groupedByProvider as $group): ?>
                <div class="summary-row">
                  <span><?= htmlspecialchars($group['provider']['businessName'] ?? 'Provider') ?></span>
                  <span><?= number_format((float)$group['groupSubtotal'], 2) ?> SAR</span>
                </div>
              <?php endforeach; ?>
            </div>

            <div class="summary-total">
              <span>Total</span>
              <span><?= number_format($total, 2) ?> SAR</span>
            </div>

            <div class="summary-note">
              Pickup locations are provided by each provider and shown here in view-only mode. After placing your order, the selected pickup details will be saved with your order items.
            </div>

            <button type="submit" class="primary-btn">Place Order</button>
            <a href="cart.php" class="secondary-btn">Back to Cart</a>
          </div>
        </aside>
      </div>
    </form>
  <?php endif; ?>
</div>

<footer class="site-footer">
  <div class="inner">
    <div class="footer-brand">
      <img src="../../images/Replate-white.png" alt="RePlate">
      <span>RePlate</span>
    </div>

    <div class="footer-links">
      <a href="../shared/landing.php">Home</a>
      <a href="providers-list.php">Providers</a>
      <a href="orders.php">Orders</a>
      <a href="contact.php">Contact</a>
    </div>
  </div>
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function () {
    <?php foreach ($groupedByProvider as $providerId => $group): ?>
      <?php
        $location = $group['location'];
        $lat = $location['coordinates']['lat'] ?? null;
        $lng = $location['coordinates']['lng'] ?? null;
        $mapId = 'providerMap_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $providerId);
      ?>
      <?php if ($lat !== null && $lng !== null): ?>
        (function () {
          const map = L.map('<?= $mapId ?>', {
            zoomControl: true,
            dragging: true,
            scrollWheelZoom: false,
            doubleClickZoom: false,
            boxZoom: false,
            keyboard: false,
            tap: false,
            touchZoom: true
          }).setView([<?= (float)$lat ?>, <?= (float)$lng ?>], 14);

          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
          }).addTo(map);

          L.marker([<?= (float)$lat ?>, <?= (float)$lng ?>]).addTo(map);

          map.dragging.disable();
          map.scrollWheelZoom.disable();
          map.doubleClickZoom.disable();
          map.boxZoom.disable();
          map.keyboard.disable();

          if (map.tap) map.tap.disable();

          setTimeout(function () {
            map.invalidateSize();
          }, 150);
        })();
      <?php endif; ?>
    <?php endforeach; ?>
  });
</script>

</body>
</html>
