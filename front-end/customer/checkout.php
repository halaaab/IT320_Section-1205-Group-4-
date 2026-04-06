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
require_once '../../back-end/models/Favourite.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId = $_SESSION['customerId'];
$cartModel  = new Cart();
$cart       = $cartModel->getOrCreate($customerId);
$cartItems  = $cart['cartItems'] ?? [];

// ── Expiry alerts ──
$expiryAlerts = []; $alertCount = 0;
$_now = time(); $_soon = $_now + 48*3600;
$_cids = array_map(fn($ci) => (string)$ci['itemId'], (array)($cartItems));
$_fv = new Favourite(); $_fvs = $_fv->getByCustomer($customerId);
$_fids = array_map(fn($f) => (string)$f['itemId'], $_fvs);
$_wids = array_unique(array_merge($_cids, $_fids));
$_im2 = new Item();
foreach ($_wids as $_wid) {
    try {
        $_wi = $_im2->findById($_wid);
        if (!$_wi || !isset($_wi['expiryDate'])) continue;
        $_exp = $_wi['expiryDate']->toDateTime()->getTimestamp();
        if ($_exp >= $_now && $_exp <= $_soon) {
            $expiryAlerts[] = ['name'=>$_wi['itemName']??'Item','hoursLeft'=>ceil(($_exp-$_now)/3600),'source'=>in_array($_wid,$_cids)?'cart':'favourites'];
        }
    } catch (Throwable) { continue; }
}
$alertCount = count($expiryAlerts);

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

            // ── Fire pickup_reminder for each provider group (same-day) ──
            $placedOrder = (new Order())->findById($orderId);
            $orderNum_   = $placedOrder['orderNumber'] ?? $orderId;
            foreach ($orderItems as $oi_) {
                $pt_ = $oi_['selectedPickupTime'] ?? 'Pickup time TBD';
                $pl_ = $oi_['pickupLocation']     ?? 'Provider location';
                // Only fire one reminder per provider (dedupe by location+time)
                $notificationModel->notifyPickupReminder($customerId, $orderId, $orderNum_, $pt_, $pl_);
                break; // one reminder per order is enough
            }

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
  <title>RePlate – Order Details</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700;800&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />

  <style>
    *{box-sizing:border-box}
    html,body{margin:0;padding:0}
    body{background:#e8eef5;color:#1b2f74;font-family:'Playfair Display',serif}
    a{text-decoration:none}

    /* ── NAV ── */
    nav{display:flex;align-items:center;justify-content:space-between;padding:0 48px;height:72px;background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(26,58,107,.18)}
    .nav-left{display:flex;align-items:center;gap:16px}
    .nav-logo{height:100px}
    .nav-cart{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,.7);display:flex;justify-content:center;align-items:center;cursor:pointer;text-decoration:none;transition:background .2s}
    .nav-cart:hover{background:rgba(255,255,255,.15)}
    .nav-center{display:flex;align-items:center;gap:40px}
    .nav-center a{color:rgba(255,255,255,.85);text-decoration:none;font-weight:500;font-size:15px;transition:color .2s}
    .nav-center a:hover,.nav-center a.active{color:#fff}
    .nav-center a.active{font-weight:600;border-bottom:2px solid #fff;padding-bottom:2px}
    .nav-right{display:flex;align-items:center;gap:12px}
    .nav-search-wrap{position:relative}
    .nav-search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.6;pointer-events:none}
    .nav-search-wrap input{background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.4);border-radius:50px;padding:9px 16px 9px 36px;color:#fff;font-size:14px;outline:none;width:240px;font-family:'Playfair Display',serif;transition:width .3s,background .2s}
    .nav-search-wrap input::placeholder{color:rgba(255,255,255,.6)}
    .nav-search-wrap input:focus{width:300px;background:rgba(255,255,255,.25)}
    .search-dropdown{display:none;position:absolute;top:calc(100% + 10px);right:0;width:380px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(26,58,107,.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden}
    .search-dropdown.open{display:block}
    .search-section-label{font-size:11px;font-weight:700;color:#b0c4d8;letter-spacing:.08em;text-transform:uppercase;padding:12px 16px 6px}
    .search-item-row{display:flex;align-items:center;gap:12px;padding:10px 16px;cursor:pointer;transition:background .15s;text-decoration:none}
    .search-item-row:hover{background:#f0f6ff}
    .search-thumb{width:38px;height:38px;border-radius:10px;background:#e0eaf5;flex-shrink:0;object-fit:cover;display:flex;align-items:center;justify-content:center;font-size:18px}
    .search-thumb img{width:100%;height:100%;object-fit:cover;border-radius:10px}
    .search-item-name{font-size:14px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif}
    .search-item-sub{font-size:12px;color:#7a8fa8}
    .search-price{margin-left:auto;font-size:13px;font-weight:700;color:#e07a1a;white-space:nowrap}
    .search-divider{height:1px;background:#f0f5fc;margin:4px 0}
    .search-empty{padding:24px 16px;text-align:center;color:#b0c4d8;font-size:14px}
    .search-loading{padding:18px 16px;text-align:center;color:#b0c4d8;font-size:13px}
    .search-provider-logo{width:38px;height:38px;border-radius:50%;background:#e0eaf5;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:#2255a4}
    .search-provider-logo img{width:100%;height:100%;object-fit:cover}
    .nav-avatar{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,.6);display:flex;align-items:center;justify-content:center;cursor:pointer}
    .nav-bell-wrap{position:relative}
    .nav-bell{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,.6);display:flex;align-items:center;justify-content:center;cursor:pointer;background:none;transition:background .2s}
    .nav-bell:hover{background:rgba(255,255,255,.15)}
    .notif-dropdown{display:none;position:absolute;top:48px;right:0;width:320px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(26,58,107,.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden}
    .notif-dropdown.open{display:block}
    .notif-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1.5px solid #f0f5fc}
    .notif-header-title{font-size:15px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif}
    .notif-empty{padding:28px 18px;text-align:center;color:#b0c4d8;font-size:14px}
    .bell-badge{position:absolute;top:-3px;right:-3px;width:18px;height:18px;background:#e07a1a;border-radius:50%;border:2px solid transparent;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none}
    .notif-item{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-bottom:1px solid #f5f8fc;transition:background .15s}
    .notif-item:last-child{border-bottom:none}
    .notif-item:hover{background:#f8fbff}
    .notif-icon{width:36px;height:36px;border-radius:50%;background:#fff4e6;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
    .notif-text{flex:1}
    .notif-name{font-size:14px;font-weight:700;color:#1a3a6b;margin-bottom:3px}
    .notif-meta{font-size:12px;color:#7a8fa8;display:flex;align-items:center;gap:6px}
    .notif-source-tag{background:#e8f0ff;color:#2255a4;border-radius:50px;padding:2px 8px;font-size:11px;font-weight:700}
    .notif-source-tag.cart{background:#e8f7ee;color:#1a6b3a}
    .notif-hours{color:#e07a1a;font-weight:700}

    /* ── PAGE LAYOUT ── */
    .page-wrap{max-width:900px;margin:0 auto;padding:28px 20px 60px}
    .page-title-row{display:flex;align-items:center;gap:20px;margin:0 0 28px}
    .back-btn{width:46px;height:46px;border-radius:50%;background:#cdd9e8;color:#1b3f92;display:flex;align-items:center;justify-content:center;font-size:28px;line-height:1;flex-shrink:0;font-weight:700;text-decoration:none}
    .back-btn:hover{background:#bfcee2}
    .page-title{font-size:62px;line-height:.95;margin:0;color:#183482;font-weight:700}

    /* ── ERROR ── */
    .error-box{background:#fff1f0;border:1.5px solid #f5c0bc;border-radius:16px;padding:14px 20px;color:#a03030;margin-bottom:20px;font-size:17px}

    /* ── PROVIDER CARD ── */
    .provider-block{background:#fff;border:1.8px solid #d2dce8;border-radius:28px;margin-bottom:24px;overflow:hidden;box-shadow:0 2px 12px rgba(26,58,107,.06)}
    .provider-inner{display:grid;grid-template-columns:1fr 220px;gap:0}
    .provider-left{padding:24px 26px}
    .provider-right{padding:24px 20px;border-left:1.5px solid #e6edf5;display:flex;flex-direction:column;gap:12px}

    /* Provider logo text */
    .prov-logo-text{font-size:32px;font-weight:700;color:#c85a3a;letter-spacing:1px;text-transform:uppercase;margin-bottom:18px;font-family:'Playfair Display',serif}

    /* Order heading */
    .order-heading{font-size:22px;font-weight:700;color:#183482;margin:0 0 14px}

    /* Item row in checkout */
    .checkout-item-row{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
    .checkout-item-name{font-size:18px;font-weight:700;color:#183482}
    .checkout-item-price{font-size:17px;color:#c87a30;font-weight:700}
    .rial{font-size:13px;margin-right:2px}

    /* Payment method */
    .payment-line{font-size:17px;color:#183482;margin-top:14px;display:flex;align-items:center;gap:8px}
    .payment-label{font-weight:700}

    /* Pickup time (subtle) */
    .pickup-time-row{margin-top:14px}
    .pickup-time-row label{font-size:14px;color:#6a7fa0;display:block;margin-bottom:6px}
    .pickup-time-row select{width:100%;border:1.5px solid #d2dce8;border-radius:12px;padding:8px 12px;font-family:'Playfair Display',serif;font-size:14px;color:#183482;background:#f8fafc;outline:none;cursor:pointer}

    /* Right side – map */
    .pickup-label{font-size:16px;font-weight:700;color:#183482;text-align:center;margin-bottom:10px}
    .provider-map{width:100%;height:160px;border-radius:18px;border:1.5px solid #d2dce8;background:#dde8f2;overflow:hidden}
    .map-fallback{width:100%;height:160px;border-radius:18px;background:linear-gradient(135deg,#86b1d8 0%,#d5e6f1 100%);display:flex;align-items:center;justify-content:center;text-align:center;color:#183482;font-size:13px;padding:12px}
    .pickup-address{font-size:13px;color:#4d6186;text-align:center;margin-top:8px}

    /* ── TOTAL & PLACE ORDER ── */
    .total-section{margin-top:8px;padding-bottom:8px}
    .total-row{display:flex;align-items:center;gap:14px;margin-bottom:24px}
    .total-label{font-size:32px;font-weight:700;color:#183482}
    .total-amount{font-size:32px;font-weight:700;color:#ea8b2c}
    .rial-lg{font-size:22px;margin-right:2px}
    .place-order-btn{display:block;width:100%;background:#173993;color:#fff;border:none;border-radius:22px;padding:22px 20px;font-size:30px;font-family:'Playfair Display',serif;cursor:pointer;text-align:center;transition:background .2s}
    .place-order-btn:hover{background:#0f2874}

    /* ── FOOTER ── */
    footer{background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);padding:28px 48px;display:flex;flex-direction:column;align-items:center;gap:14px;margin-top:40px}
    .footer-top{display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:center}
    .social-icon{width:42px;height:42px;border-radius:50%;border:1.5px solid rgba(255,255,255,.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s}
    .social-icon:hover{background:rgba(255,255,255,.15)}
    .footer-divider{width:1px;height:22px;background:rgba(255,255,255,.3)}
    .footer-brand{display:flex;align-items:center;gap:8px;color:#fff;font-size:16px;font-weight:700}
    .footer-email{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.9);font-size:14px}
    .footer-bottom{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.7);font-size:13px;flex-wrap:wrap;justify-content:center}

    @media(max-width:700px){
      .provider-inner{grid-template-columns:1fr}
      .provider-right{border-left:none;border-top:1.5px solid #e6edf5}
      .page-title{font-size:42px}
      nav{padding:0 18px}
      .nav-center{display:none}
      footer{padding:24px 18px}
    }
  </style>
</head>
<body>

<!-- NAV -->
<nav>
  <div class="nav-left">
    <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate Logo" />
    <a href="../customer/cart.php" class="nav-cart">
      <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;" />
    </a>
  </div>
  <div class="nav-center">
    <a href="../shared/landing.php">Home Page</a>
    <a href="../shared/landing.php#categories">Categories</a>
    <a href="../shared/landing.php#providers">Providers</a>
  </div>
  <div class="nav-right">
    <div class="nav-search-wrap" id="searchWrap">
      <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
      </svg>
      <input type="text" id="searchInput" placeholder="Search products or providers..." autocomplete="off"/>
      <div class="search-dropdown" id="searchDropdown"></div>
    </div>
    <div class="nav-bell-wrap">
      <button class="nav-bell" type="button" onclick="toggleNotifDropdown()">
        <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
      </button>
      <?php if ($alertCount > 0): ?><span class="bell-badge"><?= $alertCount ?></span><?php endif; ?>
      <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-header">
          <span class="notif-header-title">⏰ Expiring Soon</span>
          <span style="font-size:12px;color:#b0c4d8;"><?= $alertCount ?> alert<?= $alertCount!==1?'s':'' ?></span>
        </div>
        <?php if (empty($expiryAlerts)): ?>
        <div class="notif-empty">
          <svg width="32" height="32" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
          No expiry alerts right now
        </div>
        <?php else: ?>
        <?php foreach ($expiryAlerts as $alert): ?>
        <div class="notif-item">
          <div class="notif-icon"><svg width="16" height="16" fill="none" stroke="#e07a1a" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
          <div class="notif-text">
            <p class="notif-name"><?= htmlspecialchars($alert['name']) ?></p>
            <div class="notif-meta">
              <span class="notif-hours">⏳ <?= $alert['hoursLeft'] ?>h left</span>
              <span class="notif-source-tag <?= $alert['source']==='cart'?'cart':'' ?>"><?= $alert['source']==='cart'?'🛒 Cart':'♥ Favourites' ?></span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <a href="customer-profile.php" class="nav-avatar">
      <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
      </svg>
    </a>
  </div>
</nav>

<div class="page-wrap">
  <div class="page-title-row">
    <a class="back-btn" href="cart.php">‹</a>
    <h1 class="page-title">Order details</h1>
  </div>

  <?php if ($error): ?>
    <div class="error-box"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <?php if (empty($enriched)): ?>
    <div style="text-align:center;padding:60px 12px;color:#6d7da0;font-size:24px;">Your cart is empty.</div>
  <?php else: ?>

  <form method="POST">
    <?php foreach ($groupedByProvider as $providerId => $group): ?>
      <?php
        $provider     = $group['provider'];
        $location     = $group['location'];
        $providerName = $provider['businessName'] ?? 'Provider';
        $mapId        = 'providerMap_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $providerId);
        $lat          = $location['coordinates']['lat'] ?? null;
        $lng          = $location['coordinates']['lng'] ?? null;
        $times        = $group['pickupTimes'];
        if (empty($times)) $times = ['Anytime'];
      ?>
      <div class="provider-block">
        <div class="provider-inner">

          <!-- LEFT: order items -->
          <div class="provider-left">
            <div class="prov-logo-text"><?= htmlspecialchars(strtoupper($providerName)) ?></div>
            <div class="order-heading">Order</div>

            <?php foreach ($group['items'] as $entry): ?>
              <?php $ci = $entry['cartItem']; ?>
              <div class="checkout-item-row">
                <span class="checkout-item-name"><?= htmlspecialchars($ci['itemName'] ?? 'Item') ?></span>
                <span class="checkout-item-price"><span class="rial">﷼</span><?= number_format((float)($ci['price'] ?? 0), 2) ?></span>
              </div>
            <?php endforeach; ?>

            <div class="payment-line">
              <span class="payment-label">Payment method:</span>
              <span>Cash</span>
              <span>💵</span>
            </div>

            <!-- Pickup time (required by backend) -->
            <div class="pickup-time-row">
              <label for="pickup_<?= htmlspecialchars($providerId) ?>">Pickup time</label>
              <select id="pickup_<?= htmlspecialchars($providerId) ?>"
                      name="selectedPickupTime[<?= htmlspecialchars($providerId) ?>]" required>
                <?php foreach ($times as $time): ?>
                  <option value="<?= htmlspecialchars($time) ?>"><?= htmlspecialchars($time) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- RIGHT: pickup map -->
          <div class="provider-right">
            <div class="pickup-label">Pick up location</div>
            <?php if ($lat !== null && $lng !== null): ?>
              <div id="<?= htmlspecialchars($mapId) ?>" class="provider-map"></div>
            <?php else: ?>
              <div class="map-fallback">Location not available</div>
            <?php endif; ?>
            <div class="pickup-address"><?= htmlspecialchars($group['locationStr'] ?: 'Pickup location') ?></div>
          </div>

        </div>
      </div>
    <?php endforeach; ?>

    <!-- TOTAL & PLACE ORDER -->
    <div class="total-section">
      <div class="total-row">
        <span class="total-label">Total Amount</span>
        <span class="total-amount"><span class="rial-lg">﷼</span><?= number_format($total, 2) ?></span>
      </div>
      <button type="submit" class="place-order-btn">Place order</button>
    </div>

  </form>

  <?php endif; ?>
</div>

<!-- FOOTER -->
<footer>
  <div class="footer-top">
    <a href="#" class="social-icon">in</a>
    <a href="#" class="social-icon">&#120143;</a>
    <a href="#" class="social-icon">&#9834;</a>
    <div class="footer-divider"></div>
    <div class="footer-brand">
      <img src="../../images/Replate-white.png" alt="RePlate" style="height:24px;object-fit:contain;" />
      <span>RePlate</span>
    </div>
    <div class="footer-divider"></div>
    <div class="footer-email">
      <svg width="16" height="16" fill="none" stroke="rgba(255,255,255,.85)" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
      <span>Replate@gmail.com</span>
    </div>
  </div>
  <div class="footer-bottom">
    <span>© 2026</span>
    <img src="../../images/Replate-white.png" alt="" style="height:15px;object-fit:contain;opacity:.8;" />
    <span>All rights reserved.</span>
  </div>
</footer>

<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script>
  function toggleNotifDropdown(){
    const el = document.getElementById('notifDropdown');
    if(el) el.classList.toggle('open');
  }

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
            zoomControl: false, dragging: false, scrollWheelZoom: false,
            doubleClickZoom: false, boxZoom: false, keyboard: false, tap: false, touchZoom: false
          }).setView([<?= (float)$lat ?>, <?= (float)$lng ?>], 14);
          L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
          }).addTo(map);
          L.marker([<?= (float)$lat ?>, <?= (float)$lng ?>]).addTo(map);
          setTimeout(function(){ map.invalidateSize(); }, 150);
        })();
      <?php endif; ?>
    <?php endforeach; ?>
  });

  (function(){
    const input = document.getElementById('searchInput');
    const dropdown = document.getElementById('searchDropdown');
    const wrap = document.getElementById('searchWrap');
    if(!input||!dropdown||!wrap) return;
    let timer = null;
    function render(data){
      const items = Array.isArray(data.items)?data.items:[];
      const providers = Array.isArray(data.providers)?data.providers:[];
      if(!items.length&&!providers.length){ dropdown.innerHTML='<div class="search-empty">No matches found</div>'; dropdown.classList.add('open'); return; }
      let html='';
      if(items.length){ html+='<div class="search-section-label">Items</div>'; items.forEach(item=>{ const thumb=item.photoUrl?`<div class="search-thumb"><img src="${item.photoUrl}" alt=""></div>`:'<div class="search-thumb">🛍</div>'; html+=`<a class="search-item-row" href="item-details.php?id=${item.id}">${thumb}<div><div class="search-item-name">${item.name}</div><div class="search-item-sub">${item.listingType||''}</div></div><div class="search-price">${item.price||''}</div></a>`; }); }
      if(providers.length){ if(items.length) html+='<div class="search-divider"></div>'; html+='<div class="search-section-label">Providers</div>'; providers.forEach(p=>{ const logo=p.businessLogo?`<div class="search-provider-logo"><img src="${p.businessLogo}" alt=""></div>`:`<div class="search-provider-logo">${(p.businessName||'P').charAt(0)}</div>`; html+=`<a class="search-item-row" href="../shared/landing.php#providers">${logo}<div><div class="search-item-name">${p.businessName||''}</div><div class="search-item-sub">${p.category||''}</div></div></a>`; }); }
      dropdown.innerHTML=html; dropdown.classList.add('open');
    }
    input.addEventListener('input',function(){ const q=this.value.trim(); clearTimeout(timer); if(q.length<2){ dropdown.classList.remove('open'); dropdown.innerHTML=''; return; } dropdown.innerHTML='<div class="search-loading">Searching...</div>'; dropdown.classList.add('open'); timer=setTimeout(()=>{ fetch('../../back-end/search.php?q='+encodeURIComponent(q)).then(r=>r.json()).then(render).catch(()=>{ dropdown.innerHTML='<div class="search-empty">Search unavailable</div>'; dropdown.classList.add('open'); }); },220); });
    document.addEventListener('click',function(e){ const notif=document.getElementById('notifDropdown'); const bellWrap=document.querySelector('.nav-bell-wrap'); if(notif&&bellWrap&&!bellWrap.contains(e.target)) notif.classList.remove('open'); if(!wrap.contains(e.target)) dropdown.classList.remove('open'); });
  })();
</script>
</body>
</html>