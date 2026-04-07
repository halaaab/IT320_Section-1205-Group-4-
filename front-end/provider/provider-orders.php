<?php
session_start();

require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Order.php';
require_once '../../back-end/models/OrderItem.php';
require_once '../../back-end/models/Item.php';

if (empty($_SESSION['providerId'])) {
header('Location: ../shared/login.php');
exit;
}

if (isset($_GET['logout'])) {
session_destroy();
header('Location: ../shared/landing.php');
exit;
}

$providerId = $_SESSION['providerId'];
$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, ['pending', 'completed'], true)) {
    $tab = 'pending';
}

$providerModel = new Provider();
$orderModel = new Order();
$orderItemModel = new OrderItem();
$itemModel = new Item();
$providerItemsForSearch = $itemModel->getByProvider($providerId);

$provider = $providerModel->findById($providerId);

$providerName = $provider['businessName'] ?? 'Provider';
$providerEmail = $provider['email'] ?? '';
$providerPhone = $provider['phoneNumber'] ?? '';
$providerLogo = $provider['businessLogo'] ?? '';
$firstName = explode(' ', $providerName)[0] ?? 'Provider';

/*
|--------------------------------------------------------------------------
| Get order items for this provider only
|--------------------------------------------------------------------------
*/
$providerOrderItems = $orderItemModel->findAll([
'providerId' => new MongoDB\BSON\ObjectId($providerId)
]);

/*
|--------------------------------------------------------------------------
| Group provider items by orderId, then show each item separately
|--------------------------------------------------------------------------
*/
$groupedOrders = [];

foreach ($providerOrderItems as $orderItem) {
$orderId = (string)($orderItem['orderId'] ?? '');

if (!$orderId) {
continue;
}

if (!isset($groupedOrders[$orderId])) {
$groupedOrders[$orderId] = [
'orderId' => $orderId,
'items' => [],
];
}

$groupedOrders[$orderId]['items'][] = $orderItem;
}

/*
|--------------------------------------------------------------------------
| Build final orders list for cards
|--------------------------------------------------------------------------
*/
$ordersToShow = [];

foreach ($groupedOrders as $orderId => $group) {
    $order = $orderModel->findById($orderId);

    if (!$order) {
        continue;
    }
    /* 
--- ThisCode is no longer needed ---
    $providerTotal = 0;
    foreach ($group['items'] as $it) {
        $providerTotal += ((float)($it['price'] ?? 0)) * ((int)($it['quantity'] ?? 1));
    }*/

    $placedDateObj = null;
$placedDate = '';

if (!empty($order['placedAt']) && $order['placedAt'] instanceof MongoDB\BSON\UTCDateTime) {
    $placedDateObj = $order['placedAt']->toDateTime();
    $placedDate = $placedDateObj->format('j F Y');
}

    foreach ($group['items'] as $it) {
    $itemStatus = strtolower(trim($it['itemStatus'] ?? 'pending'));

    if ($tab === 'pending' && $itemStatus !== 'pending') {
        continue;
    }

    if ($tab === 'completed' && $itemStatus !== 'completed') {
        continue;
    }

    $displayPhoto = $it['photoUrl'] ?? '';

    $ordersToShow[] = [
        'orderId' => $orderId,
        'itemId' => (string)($it['_id'] ?? ''),
        'orderNumber' => $order['orderNumber'] ?? '',
        'status' => $itemStatus,
        'photoUrl' => $displayPhoto,
        'itemName' => $it['itemName'] ?? 'Order Item',
        'placedDate' => $placedDate,
        'placedDateObj' => $placedDateObj,
        'pickupTime' => $it['selectedPickupTime'] ?? '',
        'total' => ((float)($it['price'] ?? 0)) * ((int)($it['quantity'] ?? 1)),
    ];
    }
}

usort($ordersToShow, function ($a, $b) {
    return $b['placedDateObj'] <=> $a['placedDateObj'];
});
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Provider Orders</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: 'Playfair Display', serif; background: #f4f7fc; min-height: 100vh; display: flex; flex-direction: column; }

/* ── NAVBAR ── */
nav.navbar {
display: flex; align-items: center; justify-content: space-between;
padding: 0 40px; height: 72px;
background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%);
position: sticky; top: 0; z-index: 100;
box-shadow: 0 2px 16px rgba(26,58,107,0.18);
}
.nav-left { display: flex; align-items: center; gap: 0; }
.nav-logo { height: 90px; }
.nav-search-wrap { position: relative; }
.nav-search-wrap svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); opacity: 0.6; pointer-events: none; }
.nav-search-wrap input { background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.4); border-radius: 50px; padding: 10px 18px 10px 40px; color: #fff; font-size: 14px; outline: none; width: 260px; font-family: 'Playfair Display', serif; transition: width 0.3s, background 0.2s; }
.nav-search-wrap input::placeholder { color: rgba(255,255,255,0.6); }
.nav-search-wrap input:focus { width: 320px; background: rgba(255,255,255,0.25); }
.nav-right { display: flex; align-items: center; gap: 14px; }
.nav-provider-info { display: flex; align-items: center; gap: 14px; }
.nav-provider-logo { width: 46px; height: 46px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #fff; overflow: hidden; flex-shrink: 0; }
.nav-provider-logo img { width: 100%; height: 100%; object-fit: cover; }
.nav-provider-text { display: flex; flex-direction: column; }
.nav-provider-name { font-size: 15px; font-weight: 700; color: #fff; }
.nav-provider-email { font-size: 12px; color: rgba(255,255,255,0.75); }

/* ── LAYOUT ── */
.page-body { display: flex; flex: 1; }

/* ── SIDEBAR ── */
.sidebar { width: 240px; min-height: calc(100vh - 72px); background: linear-gradient(180deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%); display: flex; flex-direction: column; padding: 36px 24px 28px; flex-shrink: 0; }
.sidebar-welcome { color: rgba(255,255,255,0.75); font-size: 17px; font-weight: 400; margin-bottom: 4px; }
.sidebar-name { color: rgba(255,255,255,0.55); font-size: 38px; font-weight: 700; line-height: 1.1; margin-bottom: 36px; }
.sidebar-nav { display: flex; flex-direction: column; gap: 16px; flex: 1; }
.sidebar-link { display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,0.75); text-decoration: none; font-size: 16px; font-weight: 400; padding: 10px 8px; border-radius: 0; transition: color 0.2s; background: none !important; -webkit-tap-highlight-color: transparent; }
.sidebar-link:hover { color: #fff; }
.sidebar-link.active { color: #fff !important; font-weight: 700; border-bottom: 2px solid rgba(255,255,255,0.5); background: none !important; padding-bottom: 6px; }
.sidebar-link svg { flex-shrink: 0; opacity: 0.8; }
.sidebar-link.active svg { opacity: 1; }
.sidebar-logout { margin-top: 24px; background: #fff; color: #1a3a6b; border: none; border-radius: 50px; padding: 12px 0; font-size: 15px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; width: 100%; text-align: center; transition: background 0.2s; }
.sidebar-logout:hover { background: #e8f0ff; }
.sidebar-footer { margin-top: 24px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,0.15); display: flex; flex-direction: column; gap: 10px; align-items: center; }
.sidebar-footer-social { display: flex; align-items: center; justify-content: center; gap: 8px; }
.sidebar-social-icon { width: 28px; height: 28px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,0.4); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,0.8); font-size: 11px; font-weight: 700; text-decoration: none; transition: background 0.2s; }
.sidebar-social-icon:hover { background: rgba(255,255,255,0.15); }
.sidebar-footer-copy { color: rgba(255,255,255,0.45); font-size: 10px; display: flex; align-items: center; justify-content: center; gap: 4px; flex-wrap: wrap; }

 .main-content {
flex: 1;
padding: 40px 0;
background: #eef3f9;
display: flex;
flex-direction: column;
align-items: center;
}
        .tab-btn {

            min-width: 180px;

            text-align: center;

            padding: 14px 28px;

            border-radius: 999px;

            font-size: 19px;

            text-decoration: none;

            border: 1.8px solid #e48a2a;

            color: #222;

            background: #fff;

            transition: 0.2s;

        }



        .tab-btn.active {

            background: #f0851f;

            color: #fff;

            border-color: #f0851f;

        }

.orders-page-wrap {
  max-width: 900px;
  margin: 0 auto;
  width: 100%;
}

.page-header {
  margin: 0 0 20px 0;
}

.tabs-row {
  display: flex;
  gap: 16px;
  margin-bottom: 28px;
}

.orders-list {
  width: 100%;
  max-width: 760px;
  display: flex;
  flex-direction: column;
  gap: 28px;
  margin: 0;
  padding: 24px 28px;
  background: #f7fbff;
  border: 1px solid #d7e1ee;
  border-radius: 24px;
}
.order-card {
width: 100%;
background: #f7fbff;
border: 1.5px solid #bfcddd;
border-radius: 26px;
padding: 18px 20px 10px;
text-decoration: none;
display: flex;
flex-direction: column;
transition: 0.2s;
color: inherit;
}




        .order-card:hover {

            box-shadow: 0 8px 22px rgba(26,58,107,0.10);

            transform: translateY(-1px);

        }



        .order-top {

            display: flex;

            align-items: center;

            justify-content: space-between;

            gap: 20px;

        }



        .order-left-block {

            display: flex;

            align-items: center;

            gap: 18px;

        }



        .order-item-img {

            width: 92px;

            height: 92px;

            object-fit: contain;

            border-radius: 16px;

            object-position: center;

            background: #fff;

            border: 1px solid #d7e1ee;

            flex-shrink: 0;
        }



        .order-placeholder {
    width: 92px;
    height: 92px;
    border-radius: 16px;
    background: #f7fbff;
    border: 1px solid #d7e1ee;
    color: #8aa0bd;
    font-size: 12px;

    display: flex;
    align-items: center;
    justify-content: center;

    text-align: center;
    padding: 10px;       /* ⬅️ more spacing */
    line-height: 1.3;    /* ⬅️ better text spacing */

        }



        .order-text h3 {

            font-size: 18px;

            color: #183482;

            margin-bottom: 8px;

        }



        .order-meta {

            display: flex;

            align-items: center;

            gap: 8px;

            color: #284e96;

            font-size: 15px;

            margin-bottom: 8px;

        }



        .order-meta svg {

            flex-shrink: 0;

        }



        .order-bottom {
margin-top: 10px;
border-top: 1px solid #bfcddd;
padding-top: 12px;

display: flex;
align-items: center;
justify-content: space-between; /* هذا المهم */
}
.view-order-btn {
background:#183482;
color:#fff;
border: 1.5px solid #d7e1ee;
border-radius: 999px;
padding: 8px 20px;
font-size: 14px;
font-weight: 600;
text-decoration: none;
transition: 0.2s;
margin-right: 15px;
}

.view-order-btn:hover {
background:#10275f;
transform:translateY(-1px);
}




        .completed-status {

            color: #5dbb74;

            font-size: 18px;

            font-weight: 700;

        }



        .order-price {

            color: #e48a2a;

            font-size: 18px;

            font-weight: 700;

            display: flex;

            align-items: center;

            gap: 4px;

            margin-left: auto;

        }



        .currency-icon {

            height: 14px;

            object-fit: contain;

        }



        .empty-box {

            width: 100%;

            max-width: 760px;

            text-align: center;

            color: #7d90aa;

            font-size: 20px;

            padding: 50px 20px;

            background: #f7fbff;

            border: 1.5px dashed #c8d5e4;

            border-radius: 24px;

        }
        .donation-text {
color: #e48a2a;
font-size: 18px;
font-weight: 700;
margin-left: auto;
}

.page-header h1 {
  font-size: 34px;
  font-weight: 700;
  font-family: 'Playfair Display', serif;
  background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  display: inline-block;
}

.page-header h1 span {
  -webkit-text-fill-color: transparent;
}
/* ── MOBILE HEADER / HAMBURGER ── */
.hamburger {
  display: none;
  flex-direction: column;
  gap: 5px;
  cursor: pointer;
  background: none;
  border: none;
  padding: 6px;
}

.hamburger span {
  display: block;
  width: 24px;
  height: 2.5px;
  background: #fff;
  border-radius: 2px;
  transition: all 0.3s;
}

.hamburger.open span:nth-child(1) {
  transform: translateY(7.5px) rotate(45deg);
}

.hamburger.open span:nth-child(2) {
  opacity: 0;
}

.hamburger.open span:nth-child(3) {
  transform: translateY(-7.5px) rotate(-45deg);
}

.mobile-menu {
  display: none;
  position: fixed;
  inset: 0;
  top: 72px;
  background: linear-gradient(180deg, #1a3a6b 0%, #2255a4 100%);
  z-index: 99;
  flex-direction: column;
  padding: 24px 20px;
}

.mobile-menu.open {
  display: flex;
}

.mobile-menu a {
  color: rgba(255,255,255,0.9);
  font-size: 22px;
  font-weight: 700;
  padding: 18px 0;
  border-bottom: 1px solid rgba(255,255,255,0.12);
  text-decoration: none;
}

.mobile-search {
  margin-top: 22px;
  position: relative;
}

.mobile-search svg {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  opacity: 0.6;
  pointer-events: none;
}

.mobile-search input {
  width: 100%;
  background: rgba(255,255,255,0.15);
  border: 1.5px solid rgba(255,255,255,0.4);
  border-radius: 50px;
  padding: 12px 16px 12px 40px;
  color: #fff;
  outline: none;
  font-family: 'Playfair Display', serif;
}

.mobile-search input::placeholder {
  color: rgba(255,255,255,0.6);
}
.search-dropdown {
  display: none;
  position: absolute;
  top: calc(100% + 10px);
  left: 0;
  width: 360px;
  max-width: calc(100vw - 40px);
  background: #fff;
  border-radius: 18px;
  border: 1.5px solid #e0eaf5;
  box-shadow: 0 12px 40px rgba(26,58,107,0.18);
  z-index: 9999;
  overflow: hidden;
}

.search-dropdown.visible {
  display: block;
}

.sd-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  text-decoration: none;
  color: inherit;
  transition: background 0.15s;
}

.sd-row:hover {
  background: #f4f8ff;
}

.sd-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  background: #edf3fb;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #1a3a6b;
  flex-shrink: 0;
}

.sd-info {
  min-width: 0;
}

.sd-name {
  font-size: 14px;
  font-weight: 700;
  color: #1a3a6b;
}

.sd-sub {
  font-size: 12px;
  color: #7a8fa8;
  margin-top: 2px;
}

.mobile-search {
  position: relative;
}

.mobile-search .search-dropdown {
  top: calc(100% + 12px);
  left: 0;
  width: 100%;
}
.sd-section-title {
  font-size: 13px;
  font-weight: 700;
  color: #183482;
  padding: 12px 16px 6px;
  background: #fff;
}

.search-dropdown {
  width: 405px;
  border-radius: 20px;
  overflow: hidden;
}

.sd-row {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 16px;
}

.sd-icon {
  width: 48px;
  height: 48px;
  border-radius: 14px;
  overflow: hidden;
  flex-shrink: 0;
  background: #edf3fb;
}

.sd-icon img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 14px;
}

.sd-info {
  min-width: 0;
  flex: 1;
}

.sd-name {
  font-size: 16px;
  font-weight: 700;
  color: #183482;
  line-height: 1.2;
  margin-bottom: 2px;
}

.sd-sub {
  font-size: 13px;
  color: #7a8fa8;
  line-height: 1.3;
}
.sd-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 88px;
  padding: 6px 12px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  flex-shrink: 0;
}

.sd-badge.donation {
  background: #dff3e8;
  color: #2f8f57;
}

.sd-badge.sell {
  background: #fff3e4;
  color: #e48a2a;
}
@media (max-width: 768px) {
  .hamburger {
    display: flex;
  }

  .sidebar {
    display: none;
  }

  .page-body {
    display: block;
  }

  .nav-search-wrap {
    display: none;
  }

  .nav-provider-text {
    display: none;
  }

  nav.navbar {
    padding: 0 16px;
  }

  .nav-logo {
    height: 70px;
  }

  .main-content {
    width: 100%;
    padding: 20px 16px;
    margin: 0;
    align-items: stretch;
  }

  .orders-page-wrap {
    width: 100%;
    max-width: 100%;
  }

  .tabs-row {
    display: flex;
    gap: 8px;
    width: 100%;
  }

  .tab-btn {
    flex: 1;
    min-width: 0;
    width: auto;
    padding: 10px 8px;
    font-size: 14px;
  }

  .orders-list {
    width: 100%;
    max-width: 100%;
    padding: 16px;
    gap: 16px;
  }

  .order-top,
  .order-left-block,
  .order-bottom {
    flex-direction: column;
    align-items: flex-start;
  }

  .order-item-img,
  .order-placeholder {
    width: 82px;
    height: 82px;
  }

  .order-price,
  .donation-text {
    margin-left: 0;
  }

  .view-order-btn {
    margin-right: 0;
  }
  .mobile-search .search-dropdown {
  top: calc(100% + 8px);
  width: 100%;
  max-width: 100%;
  border-radius: 16px;
}

.mobile-search .sd-row {
  padding: 10px 12px;
  gap: 10px;
}

.mobile-search .sd-icon {
  width: 42px;
  height: 42px;
  border-radius: 12px;
}

.mobile-search .sd-icon img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 12px;
}

.mobile-search .sd-info {
  min-width: 0;
  flex: 1;
}

.mobile-search .sd-name {
  font-size: 15px;
  font-weight: 700;
  line-height: 1.2;
  color: #183482;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.mobile-search .sd-sub {
  font-size: 13px;
  line-height: 1.2;
  margin-top: 3px;
}

.mobile-search .sd-section-title {
  font-size: 12px;
  padding: 10px 12px 6px;
}
.mobile-search .sd-badge {
  min-width: 78px;
  padding: 5px 10px;
  font-size: 11px;
}
}
</style>
</head>
<body>
<nav class="navbar">
  <div class="nav-left">
    <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
  </div>

  <div class="nav-right">
    <div class="nav-search-wrap" id="searchWrap">
      <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="11" cy="11" r="8"/>
        <path d="M21 21l-4.35-4.35"/>
      </svg>
      <input
        type="text"
        id="searchInput"
        placeholder="Search orders..."
        autocomplete="off"
      />
      <div class="search-dropdown" id="searchDropdown"></div>
    </div>

    <div class="nav-provider-info">
      <div class="nav-provider-logo">
        <?php if ($providerLogo): ?>
          <img src="<?= htmlspecialchars($providerLogo) ?>" alt="<?= htmlspecialchars($providerName) ?>"/>
        <?php else: ?>
          <?= mb_strtoupper(mb_substr($providerName, 0, 1)) ?>
        <?php endif; ?>
      </div>
      <div class="nav-provider-text">
        <span class="nav-provider-name"><?= htmlspecialchars($providerName) ?></span>
        <span class="nav-provider-email"><?= htmlspecialchars($providerEmail) ?></span>
      </div>
    </div>

    <button id="hamburger" class="hamburger" onclick="toggleMobileMenu()" aria-label="Open menu">
      <span></span>
      <span></span>
      <span></span>
    </button>
  </div>
</nav>

<div class="mobile-menu" id="mobileMenu">
  <div class="mobile-search">
    <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="7"></circle>
      <path d="m21 21-4.3-4.3"></path>
    </svg>
    <input
      type="text"
      id="mobileSearchInput"
      placeholder="Search orders..."
      autocomplete="off"
    />
    <div class="search-dropdown" id="mobileSearchDropdown"></div>
  </div>

  <a href="provider-dashboard.php" onclick="closeMobileMenu()">Dashboard</a>
  <a href="provider-items.php" onclick="closeMobileMenu()">Items</a>
  <a href="provider-orders.php" onclick="closeMobileMenu()">Orders</a>
  <a href="provider-profile.php" onclick="closeMobileMenu()">Profile</a>
  <a href="provider-dashboard.php?logout=1" onclick="closeMobileMenu()">Log out</a>
</div>

<div class="page-body">
<aside class="sidebar">
<p class="sidebar-welcome">Welcome Back ,</p>
<p class="sidebar-name"><?= htmlspecialchars($firstName) ?></p>
<nav class="sidebar-nav">
<a href="provider-dashboard.php" class="sidebar-link ">
<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
Dashboard
</a>
<a href="provider-items.php" class="sidebar-link ">
<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 3H8a2 2 0 00-2 2v2h12V5a2 2 0 00-2-2z"/></svg>
Items
</a>
<a href="provider-orders.php" class="sidebar-link active">
<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
Orders
</a>
<a href="provider-profile.php" class="sidebar-link">
<svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
Profile
</a>
</nav>
<button class="sidebar-logout" onclick="window.location.href='provider-dashboard.php?logout=1'">Logout</button>
<div class="sidebar-footer">
<div class="sidebar-footer-social">
<a href="#" class="sidebar-social-icon">in</a>
<a href="#" class="sidebar-social-icon">&#120143;</a>
<a href="#" class="sidebar-social-icon">&#9834;</a>

</div>
<div class="sidebar-footer-copy">
<span>©️ 2026</span>
<img src="../../images/Replate-white.png" alt="" style="height:40px;object-fit:contain;opacity:0.45;"/>
<span>All rights reserved.</span>
</div>
</div>
</aside>
<main class="main-content">

  <div class="orders-page-wrap">
  <div class="page-header">
    <h1><span>My</span> Orders</h1>
  </div>

  <div class="tabs-row">

            <a href="provider-orders.php?tab=pending" class="tab-btn <?= $tab === 'pending' ? 'active' : '' ?>">Pending</a>

            <a href="provider-orders.php?tab=completed" class="tab-btn <?= $tab === 'completed' ? 'active' : '' ?>">Completed</a>

        </div>



        <div class="orders-list">

            <?php if (!empty($ordersToShow)): ?>

                <?php foreach ($ordersToShow as $order): ?>

                    <div class="order-card">

                        <div class="order-top">

 <div class="order-left-block">

  <div class="order-image-wrap">
    <?php if (!empty($order['photoUrl'])): ?>
      <img class="order-item-img"
           src="<?= htmlspecialchars($order['photoUrl']) ?>"
           alt="<?= htmlspecialchars($order['itemName']) ?>"
           onerror="this.style.display='none'; this.parentNode.querySelector('.order-placeholder').style.display='flex';">

      <div class="order-placeholder" style="display:none;">No image</div>
    <?php else: ?>
      <div class="order-placeholder" style="display:flex;">No image</div>
    <?php endif; ?>
  </div>

  <div class="order-text">
                                    <h3><?= htmlspecialchars($order['itemName']) ?></h3>



                                    <div class="order-meta">

                                        <svg width="18" height="18" fill="none" stroke="#173993" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>

                                        <span><?= htmlspecialchars($order['placedDate']) ?><?= !empty($order['pickupTime']) ? '   ' . htmlspecialchars($order['pickupTime']) : '' ?></span>

                                    </div>



                                    <div class="order-meta">

                                        <svg width="18" height="18" fill="none" stroke="#173993" stroke-width="2" viewBox="0 0 24 24"><rect x="5" y="3" width="14" height="18" rx="2"/><line x1="8" y1="8" x2="16" y2="8"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="8" y1="16" x2="13" y2="16"/></svg>

                                        <span>Order number: <?= htmlspecialchars($order['orderNumber']) ?></span>

                                    </div>

                                </div>

                            </div>

                        </div>


                        <div class="order-bottom">
                        <a class="view-order-btn"
   href="provider-order-details.php?orderId=<?= urlencode($order['orderId']) ?>&itemId=<?= urlencode($order['itemId']) ?>">
   View Order
</a>
<?php if (($order['status'] ?? '') === 'completed'): ?>
    <span class="completed-status">Completed</span>
<?php else: ?>
    <span>Pending</span>
<?php endif; ?>


                           
                            <?php if ((float)$order['total'] <= 0): ?>
<div class="order-price donation-text">Donation</div>
<?php else: ?>
<div class="order-price">
<?= number_format($order['total'], 2) ?>
<img src="../../images/SAR.png" class="currency-icon" alt="price">
</div>
<?php endif; ?>


                        </div>

                    </div>

                <?php endforeach; ?>

            <?php else: ?>

                <div class="empty-box">

                    No <?= htmlspecialchars($tab) ?> orders yet.

                </div>

            <?php endif; ?>

        </div>

</div>

<script>
function toggleMobileMenu() {
  const menu = document.getElementById('mobileMenu');
  const btn = document.getElementById('hamburger');
  menu.classList.toggle('open');
  btn.classList.toggle('open');
  document.body.style.overflow = menu.classList.contains('open') ? 'hidden' : '';
}

function closeMobileMenu() {
  document.getElementById('mobileMenu').classList.remove('open');
  document.getElementById('hamburger').classList.remove('open');
  document.body.style.overflow = '';
}
</script>
<script>
const itemSearchData = <?= json_encode(array_map(function($item) {
  return [
    'id' => (string)($item['_id'] ?? ''),
    'name' => $item['itemName'] ?? 'Item',
    'photoUrl' => $item['photoUrl'] ?? '',
    'listingType' => strtolower($item['listingType'] ?? ''),
    'price' => (float)($item['price'] ?? 0),
  ];
}, $providerItemsForSearch), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function renderItemResults(results, dropdownId, isMobile = false) {
  const dropdown = document.getElementById(dropdownId);
  if (!dropdown) return;

  if (!results.length) {
    dropdown.innerHTML = '<div style="padding:14px;text-align:center;color:#8a9ab5;font-size:13px;">No matches found</div>';
    dropdown.classList.add('visible');
    return;
  }

  let html = '<div class="sd-section-title">Items</div>';

  results.forEach(item => {
    const thumb = item.photoUrl
      ? `<div class="sd-icon"><img src="${item.photoUrl}" alt=""></div>`
      : '<div class="sd-icon">🍱</div>';

    const priceText = item.listingType === 'donate' || item.price <= 0
      ? 'Free'
      : `${Number(item.price).toFixed(2)} SAR`;

    const badgeText = item.listingType === 'donate' ? 'Donation' : 'Sell';
    const badgeClass = item.listingType === 'donate' ? 'sd-badge donation' : 'sd-badge sell';

    html += `
      <a class="sd-row" href="provider-items.php" ${isMobile ? 'onclick="closeMobileMenu()"' : ''}>
        ${thumb}
        <div class="sd-info">
          <div class="sd-name">${item.name || 'Item'}</div>
          <div class="sd-sub">${priceText}</div>
        </div>
        <span class="${badgeClass}">${badgeText}</span>
      </a>
    `;
  });

  dropdown.innerHTML = html;
  dropdown.classList.add('visible');
}

function setupItemSearch(inputId, dropdownId, isMobile = false) {
  const input = document.getElementById(inputId);
  const dropdown = document.getElementById(dropdownId);
  if (!input || !dropdown) return;

  let timer = null;

  input.addEventListener('input', function () {
    clearTimeout(timer);
    const q = this.value.trim().toLowerCase();

    if (q.length < 1) {
      dropdown.classList.remove('visible');
      dropdown.innerHTML = '';
      return;
    }

    dropdown.innerHTML = '<div style="padding:14px;text-align:center;color:#8a9ab5;font-size:13px;">Searching...</div>';
    dropdown.classList.add('visible');

    timer = setTimeout(() => {
      const results = itemSearchData.filter(item =>
        (item.name || '').toLowerCase().includes(q)
      );

      renderItemResults(results, dropdownId, isMobile);
    }, 180);
  });

  input.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
      dropdown.classList.remove('visible');
    }
  });
}

setupItemSearch('searchInput', 'searchDropdown', false);
setupItemSearch('mobileSearchInput', 'mobileSearchDropdown', true);

document.addEventListener('click', e => {
  const searchWrap = document.getElementById('searchWrap');
  const searchDropdown = document.getElementById('searchDropdown');
  const mobileSearchInput = document.getElementById('mobileSearchInput');
  const mobileSearchDropdown = document.getElementById('mobileSearchDropdown');

  if (searchWrap && searchDropdown && !searchWrap.contains(e.target)) {
    searchDropdown.classList.remove('visible');
  }

  if (
    mobileSearchDropdown &&
    mobileSearchInput &&
    !mobileSearchInput.contains(e.target) &&
    !mobileSearchDropdown.contains(e.target)
  ) {
    mobileSearchDropdown.classList.remove('visible');
  }
});
</script>
</body>

</html> 
    
