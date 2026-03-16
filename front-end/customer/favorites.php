<?php
// ================================================================
// favorites.php — Customer Saved Items
// ================================================================
// VARIABLES:
//   $favourites → array of saved items (full item objects)
// POST ACTION:
//   action=remove & itemId=xxx → removes item from favourites
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Favourite.php';
require_once '../../back-end/models/Item.php';
// ── Added to match profile page layout ──
require_once '../../back-end/models/Customer.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Provider.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId = $_SESSION['customerId'];
$favModel   = new Favourite();

// ── Handle remove ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
    $itemId = $_POST['itemId'] ?? '';
    if ($itemId) $favModel->remove($customerId, $itemId);
    header('Location: favorites.php');
    exit;
}

// Load saved item IDs, then fetch full item objects
$savedRefs  = $favModel->getByCustomer($customerId);  // array of { itemId }
$itemModel  = new Item();
$favourites = [];
foreach ($savedRefs as $ref) {
    $item = $itemModel->findById((string) $ref['itemId']);
    if ($item) $favourites[] = $item;
}

// ── EXAMPLE: Favourites loop ──
// foreach ($favourites as $item):
//   <a href="item-details.php?itemId=[item._id]">
//     <h3>[item.itemName]</h3>
//     <p>[item.listingType===donate ? Free : item.price.' SAR']</p>
//   </a>
//   <form method="POST">
//     <input type="hidden" name="action" value="remove" />
//     <input type="hidden" name="itemId" value="[item._id]" />
//     <button type="submit">Remove</button>
//   </form>
// endforeach

// ── Added: fetch customer name for sidebar ──
$customerModel = new Customer();
$customer      = $customerModel->findById($customerId);
$firstName     = explode(' ', $customer['fullName'] ?? 'Customer')[0];

// ── Added: expiry alerts (same logic as customer-profile.php) ──
$expiryAlerts = [];
$alertCount   = 0;
try {
    $now  = time();
    $soon = $now + 48 * 3600;

    $cartModel   = new Cart();
    $cart        = $cartModel->getOrCreate($customerId);
    $cartItemIds = array_map(fn($ci) => (string)$ci['itemId'], (array)($cart['cartItems'] ?? []));

    $favItemIds  = array_map(fn($f) => isset($f['itemId']) ? (string)$f['itemId'] : '', $savedRefs);
    $favItemIds  = array_values(array_filter($favItemIds));
    $watchedIds  = array_unique(array_merge($cartItemIds, $favItemIds));

    foreach ($watchedIds as $wid) {
        try {
            $witem = $itemModel->findById($wid);
            if (!$witem || !isset($witem['expiryDate'])) continue;
            $expiry = $witem['expiryDate']->toDateTime()->getTimestamp();
            if ($expiry >= $now && $expiry <= $soon) {
                $hoursLeft = ceil(($expiry - $now) / 3600);
                $source    = in_array($wid, $cartItemIds) ? 'cart' : 'favourites';
                $expiryAlerts[] = [
                    'id'        => $wid,
                    'name'      => $witem['itemName'] ?? 'Item',
                    'hoursLeft' => $hoursLeft,
                    'source'    => $source,
                ];
            }
        } catch (Throwable) { continue; }
    }
} catch (Throwable) { /* silently skip alerts if any model fails */ }
$alertCount = count($expiryAlerts);

// ── Added: attach provider info to each favourite item ──
$providerModel = new Provider();
foreach ($favourites as &$fav) {
    $prov = !empty($fav['providerId']) ? $providerModel->findById((string)$fav['providerId']) : null;
    $fav['_provName'] = $prov['businessName'] ?? '';
    $fav['_provLogo'] = $prov['businessLogo'] ?? '';
}
unset($fav);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RePlate – My Favourites</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Playfair Display', serif; background: #fff; min-height: 100vh; display: flex; flex-direction: column; }

    /* ── NAVBAR (identical to customer-profile.php) ── */
    nav.navbar { display: flex; align-items: center; justify-content: space-between; padding: 0 40px; height: 72px; background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%); position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 16px rgba(26,58,107,0.18); }
    .nav-logo { height: 100px; }
    .nav-left { display: flex; align-items: center; gap: 16px; }
    .nav-cart { width: 40px; height: 40px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.7); display: flex; align-items: center; justify-content: center; text-decoration: none; transition: background 0.2s; }
    .nav-cart:hover { background: rgba(255,255,255,0.15); }
    .nav-center { display: flex; align-items: center; gap: 40px; }
    .nav-center a { color: rgba(255,255,255,0.85); text-decoration: none; font-weight: 500; font-size: 15px; transition: color 0.2s; }
    .nav-center a:hover { color: #fff; }
    .nav-right { display: flex; align-items: center; gap: 12px; }
    .nav-search-wrap { position: relative; }
    .search-dropdown { display: none; position: absolute; top: calc(100% + 10px); right: 0; width: 380px; background: #fff; border-radius: 16px; box-shadow: 0 8px 40px rgba(26,58,107,0.18); border: 1.5px solid #e0eaf5; z-index: 9999; overflow: hidden; }
    .search-dropdown.open { display: block; }
    .search-section-label { font-size: 11px; font-weight: 700; color: #b0c4d8; letter-spacing: 0.08em; text-transform: uppercase; padding: 12px 16px 6px; }
    .search-item-row { display: flex; align-items: center; gap: 12px; padding: 10px 16px; cursor: pointer; transition: background 0.15s; text-decoration: none; }
    .search-item-row:hover { background: #f0f6ff; }
    .search-thumb { width: 38px; height: 38px; border-radius: 10px; background: #e0eaf5; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 18px; overflow: hidden; }
    .search-thumb img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
    .search-item-name { font-size: 14px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; }
    .search-item-sub { font-size: 12px; color: #7a8fa8; }
    .search-price { margin-left: auto; font-size: 13px; font-weight: 700; color: #e07a1a; white-space: nowrap; }
    .search-divider { height: 1px; background: #f0f5fc; margin: 4px 0; }
    .search-empty { padding: 24px 16px; text-align: center; color: #b0c4d8; font-size: 14px; font-family: 'Playfair Display', serif; }
    .search-no-match { padding: 8px 16px 12px; font-size: 13px; color: #b0c4d8; font-style: italic; }
    .search-loading { padding: 18px 16px; text-align: center; color: #b0c4d8; font-size: 13px; }
    .search-provider-logo { width: 38px; height: 38px; border-radius: 50%; background: #e0eaf5; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; color: #2255a4; }
    .search-provider-logo img { width: 100%; height: 100%; object-fit: cover; }
    .nav-search-wrap svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); opacity: 0.6; pointer-events: none; }
    .nav-search-wrap input { background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.4); border-radius: 50px; padding: 9px 16px 9px 36px; color: #fff; font-size: 14px; outline: none; width: 240px; font-family: 'Playfair Display', serif; transition: width 0.3s, background 0.2s; }
    .nav-search-wrap input::placeholder { color: rgba(255,255,255,0.6); }
    .nav-search-wrap input:focus { width: 300px; background: rgba(255,255,255,0.25); }
    .nav-avatar { width: 38px; height: 38px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); display: flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; background: rgba(255,255,255,0.15); }
    .nav-avatar:hover { background: rgba(255,255,255,0.25); }
    .nav-bell-wrap { position: relative; }
    .nav-bell { width: 38px; height: 38px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); display: flex; align-items: center; justify-content: center; cursor: pointer; background: none; transition: background 0.2s; }
    .nav-bell:hover { background: rgba(255,255,255,0.15); }
    .bell-badge { position: absolute; top: -3px; right: -3px; width: 18px; height: 18px; background: #e07a1a; border-radius: 50%; border: 2px solid transparent; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: #fff; pointer-events: none; }
    .notif-dropdown { display: none; position: absolute; top: 48px; right: 0; width: 320px; background: #fff; border-radius: 16px; box-shadow: 0 8px 40px rgba(26,58,107,0.18); border: 1.5px solid #e0eaf5; z-index: 9999; overflow: hidden; }
    .notif-dropdown.open { display: block; }
    .notif-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px 12px; border-bottom: 1.5px solid #f0f5fc; }
    .notif-header-title { font-size: 15px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; }
    .notif-empty { padding: 28px 18px; text-align: center; color: #b0c4d8; font-size: 14px; }
    .notif-item { display: flex; align-items: flex-start; gap: 12px; padding: 14px 18px; border-bottom: 1px solid #f5f8fc; transition: background 0.15s; }
    .notif-item:last-child { border-bottom: none; }
    .notif-item:hover { background: #f8fbff; }
    .notif-icon { width: 36px; height: 36px; border-radius: 50%; background: #fff4e6; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
    .notif-icon svg { stroke: #e07a1a; }
    .notif-text { flex: 1; }
    .notif-name { font-size: 14px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; margin-bottom: 3px; }
    .notif-meta { font-size: 12px; color: #7a8fa8; display: flex; align-items: center; gap: 6px; }
    .notif-source-tag { background: #e8f0ff; color: #2255a4; border-radius: 50px; padding: 2px 8px; font-size: 11px; font-weight: 700; }
    .notif-source-tag.cart { background: #e8f7ee; color: #1a6b3a; }
    .notif-hours { color: #e07a1a; font-weight: 700; }

    /* ── PAGE LAYOUT (identical to customer-profile.php) ── */
    .page-body { display: flex; flex: 1; }

    .sidebar { width: 240px; min-height: calc(100vh - 72px); background: #2255a4; display: flex; flex-direction: column; padding: 36px 24px 28px; flex-shrink: 0; }
    .sidebar-welcome { color: rgba(255,255,255,0.75); font-size: 18px; font-weight: 400; margin-bottom: 4px; }
    .sidebar-name { color: rgba(255,255,255,0.55); font-size: 42px; font-weight: 700; line-height: 1.1; margin-bottom: 36px; }
    .sidebar-nav { display: flex; flex-direction: column; gap: 16px; flex: 1; background: transparent; }
    .sidebar-link { display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,0.75); text-decoration: none; font-size: 16px; font-weight: 400; padding: 10px 8px; border-radius: 0; transition: color 0.2s; background: none !important; -webkit-tap-highlight-color: transparent; }
    .sidebar-link:hover { color: #fff; background: none !important; }
    .sidebar-link.active { color: #fff !important; font-weight: 700; border-bottom: 2px solid rgba(255,255,255,0.5); background: none !important; padding-bottom: 6px; }
    .sidebar-link svg { flex-shrink: 0; opacity: 0.8; }
    .sidebar-link.active svg { opacity: 1; }
    .sidebar-logout { margin-top: 24px; background: #fff; color: #1a3a6b; border: none; border-radius: 50px; padding: 12px 0; font-size: 16px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; width: 100%; transition: background 0.2s; text-align: center; }
    .sidebar-logout:hover { background: #e8f0ff; }
    .sidebar-footer { margin-top: 24px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,0.15); display: flex; flex-direction: column; gap: 12px; align-items: center; }
    .sidebar-footer-social { display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap; }
    .sidebar-social-icon { width: 30px; height: 30px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,0.45); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,0.8); font-size: 12px; font-weight: 700; text-decoration: none; transition: background 0.2s; flex-shrink: 0; }
    .sidebar-social-icon:hover { background: rgba(255,255,255,0.15); color: #fff; }
    .sidebar-footer-email { display: flex; align-items: center; justify-content: center; gap: 6px; color: rgba(255,255,255,0.7); font-size: 11px; }
    .sidebar-footer-copy { color: rgba(255,255,255,0.5); font-size: 11px; display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap; }

    .main { flex: 1; padding: 40px 48px; background: #fff; overflow-y: auto; }
    .dashboard-grid { display: grid; grid-template-columns: 1fr; gap: 32px; align-items: start; }
    .profile-col { display: flex; flex-direction: column; }

    /* ── NOTIFICATION PANEL (identical to customer-profile.php) ── */
    .notif-panel { background: #f8fbff; border-radius: 20px; border: 1.5px solid #e0eaf5; overflow: hidden; position: sticky; top: 24px; }
    .notif-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px 14px; border-bottom: 1.5px solid #e8f0fa; background: #fff; }
    .notif-panel-title { font-size: 17px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; display: flex; align-items: center; gap: 8px; }
    .notif-count-badge { background: #e07a1a; color: #fff; border-radius: 50px; padding: 2px 10px; font-size: 12px; font-weight: 700; }
    .notif-count-badge.zero { background: #e0eaf5; color: #8a9ab5; }
    .mark-read-btn { font-size: 12px; color: #2255a4; background: none; border: none; cursor: pointer; font-family: 'Playfair Display', serif; font-weight: 600; padding: 0; transition: color 0.2s; }
    .mark-read-btn:hover { color: #1a3a6b; }
    .notif-panel-body { max-height: 520px; overflow-y: auto; }
    .notif-panel-body::-webkit-scrollbar { width: 4px; }
    .notif-panel-body::-webkit-scrollbar-track { background: transparent; }
    .notif-panel-body::-webkit-scrollbar-thumb { background: #c8d8ee; border-radius: 4px; }
    .notif-card { display: flex; align-items: flex-start; gap: 12px; padding: 14px 20px; border-bottom: 1px solid #eef4fc; transition: background 0.15s; cursor: pointer; }
    .notif-card:last-child { border-bottom: none; }
    .notif-card:hover { background: #f0f6ff; }
    .notif-card.unread { background: #fff; border-left: 3px solid #e07a1a; }
    .notif-card.unread:hover { background: #fff9f4; }
    .notif-card-icon { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
    .notif-card-icon.expiry { background: #fff4e6; }
    .notif-card-icon.order { background: #e8f7ee; }
    .notif-card-icon.expiry svg { stroke: #e07a1a; }
    .notif-card-icon.order svg { stroke: #1a6b3a; }
    .notif-card-body { flex: 1; min-width: 0; }
    .notif-card-title { font-size: 13px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .notif-card-sub { font-size: 12px; color: #7a8fa8; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .tag { border-radius: 50px; padding: 2px 8px; font-size: 11px; font-weight: 700; }
    .tag-expiry { background: #fff4e6; color: #e07a1a; }
    .tag-cart { background: #e8f7ee; color: #1a6b3a; }
    .tag-fav { background: #e8f0ff; color: #2255a4; }
    .tag-order { background: #e8f7ee; color: #1a6b3a; }
    .notif-card-time { font-size: 11px; color: #b0c4d8; margin-top: 4px; }
    .notif-panel-empty { padding: 40px 20px; text-align: center; color: #b0c4d8; font-size: 14px; font-family: 'Playfair Display', serif; }
    .notif-panel-empty svg { display: block; margin: 0 auto 12px; }

    /* ── FAVOURITES CONTENT ── */
    .fav-title { font-size: 30px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; margin-bottom: 24px; }

    .fav-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
      gap: 18px;
    }

    .fav-card {
      background: #fff;
      border: 1.5px solid #dce7f5;
      border-radius: 18px;
      overflow: hidden;
      box-shadow: 0 4px 14px rgba(26,58,107,0.07);
      transition: transform 0.2s, box-shadow 0.2s;
      display: flex;
      flex-direction: column;
    }
    .fav-card:hover { transform: translateY(-3px); box-shadow: 0 10px 28px rgba(26,58,107,0.13); }

    .fav-card-top { position: relative; padding: 12px 12px 0; }
    .fav-prov-logo { position: absolute; top: 12px; left: 12px; height: 28px; max-width: 80px; object-fit: contain; }
    .fav-prov-name { position: absolute; top: 12px; left: 12px; font-size: 12px; font-weight: 700; color: #7a8fa8; font-style: italic; }
    .fav-heart-btn {
      position: absolute; top: 10px; right: 10px;
      width: 32px; height: 32px; border-radius: 50%;
      border: none; background: transparent;
      cursor: pointer; display: grid; place-items: center;
      font-size: 20px; color: #e04040;
      transition: transform 0.2s; z-index: 2;
    }
    .fav-heart-btn:hover { transform: scale(1.2); }
    .fav-img {
      width: 100%; height: 150px;
      object-fit: contain; margin-top: 8px;
      padding: 6px; border-radius: 12px;
      background: #f8fbff;
    }
    .fav-img-placeholder {
      width: 100%; height: 150px;
      background: linear-gradient(135deg, #e8f0ff, #dce7f5);
      border-radius: 12px; margin-top: 8px;
      display: grid; place-items: center;
      color: #7a8fa8; font-size: 12px;
    }

    .fav-card-body { padding: 10px 12px 14px; flex: 1; display: flex; flex-direction: column; }
    .fav-name-row { display: flex; align-items: baseline; justify-content: space-between; gap: 6px; margin-bottom: 4px; }
    .fav-name { font-weight: 700; font-size: 14px; color: #1a2a45; }
    .fav-price { font-weight: 700; font-size: 14px; color: #e07a1a; white-space: nowrap; }
    .fav-price-free { color: #1a6b3a; }
    .fav-desc { font-size: 12px; color: #7a8fa8; line-height: 1.5; margin-bottom: 10px; flex: 1; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; font-family: 'DM Sans', sans-serif; }
    .fav-view-btn {
      display: inline-block; background: #1a3a6b; color: #fff;
      border-radius: 50px; padding: 7px 16px;
      font-weight: 700; font-size: 13px; text-align: center;
      transition: background 0.2s; align-self: flex-start;
      text-decoration: none;
    }
    .fav-view-btn:hover { background: #2255a4; }

    .fav-empty {
      padding: 60px 24px; text-align: center; color: #b0c4d8;
    }
    .fav-empty h3 { font-size: 24px; font-weight: 700; color: #1a3a6b; margin-bottom: 10px; }
    .fav-empty a { display: inline-block; margin-top: 16px; background: #e07a1a; color: #fff; border-radius: 50px; padding: 12px 28px; font-weight: 700; text-decoration: none; }
  </style>
</head>
<body>

  <!-- ── NAVBAR (identical to customer-profile.php) ── -->
  <nav class="navbar">
    <div class="nav-left">
      <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
      <a href="../customer/cart.php" class="nav-cart">
        <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;"/>
      </a>
    </div>
    <div class="nav-center">
      <a href="../shared/landing.php">Home Page</a>
      <a href="../shared/landing.php#categories">Categories</a>
      <a href="../shared/landing.php#providers">Providers</a>
    </div>
    <div class="nav-right">
      <div class="nav-search-wrap" id="searchWrap">
        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" id="searchInput" placeholder="Search products or providers..." autocomplete="off"/>
        <div class="search-dropdown" id="searchDropdown"></div>
      </div>
      <div class="nav-bell-wrap">
        <button class="nav-bell" id="bellBtn" onclick="toggleNotifDropdown()">
          <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        </button>
        <?php if ($alertCount > 0): ?>
        <span class="bell-badge"><?= $alertCount ?></span>
        <?php endif; ?>

        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-header">
            <span class="notif-header-title">⏰ Expiring Soon</span>
            <span style="font-size:12px;color:#b0c4d8;"><?= $alertCount ?> alert<?= $alertCount !== 1 ? 's' : '' ?></span>
          </div>
          <?php if (empty($expiryAlerts)): ?>
          <div class="notif-empty">
            <svg width="32" height="32" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            No expiry alerts right now
          </div>
          <?php else: ?>
          <?php foreach ($expiryAlerts as $alert): ?>
          <div class="notif-item">
            <div class="notif-icon">
              <svg width="16" height="16" fill="none" stroke="#e07a1a" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="notif-text">
              <p class="notif-name"><?= htmlspecialchars($alert['name']) ?></p>
              <div class="notif-meta">
                <span class="notif-hours">⏳ <?= $alert['hoursLeft'] ?>h left</span>
                <span class="notif-source-tag <?= $alert['source'] === 'cart' ? 'cart' : '' ?>">
                  <?= $alert['source'] === 'cart' ? '🛒 Cart' : '♥ Favourites' ?>
                </span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <a href="customer-profile.php" class="nav-avatar">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
    </div>
  </nav>

  <div class="page-body">

    <!-- ── SIDEBAR (identical to customer-profile.php, Favourites marked active) ── -->
    <aside class="sidebar">
      <p class="sidebar-welcome">Welcome Back ,</p>
      <p class="sidebar-name"><?= htmlspecialchars($firstName) ?></p>
      <nav class="sidebar-nav">
        <a href="customer-profile.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
        <a href="favorites.php" class="sidebar-link active">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
          Favourites
        </a>
        <a href="orders.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
          Orders
        </a>
        <a href="#" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
          Notification
        </a>
        <a href="contact.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
          Contact Us
        </a>
      </nav>
      <button class="sidebar-logout" onclick="window.location.href='customer-profile.php?logout=1'">Log out</button>
      <div class="sidebar-footer">
        <div class="sidebar-footer-social">
          <a href="#" class="sidebar-social-icon">in</a>
          <a href="#" class="sidebar-social-icon">&#120143;</a>
          <a href="#" class="sidebar-social-icon">&#9834;</a>
          <img src="../../images/Replate-white.png" alt="RePlate" style="height:22px;object-fit:contain;opacity:0.75;margin-left:4px;"/>
        </div>
        <div class="sidebar-footer-email">
          <svg width="13" height="13" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2" viewBox="0 0 24 24">
            <rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/>
          </svg>
          <span>Replate@gmail.com</span>
        </div>
        <div class="sidebar-footer-copy">
          <span>© 2026</span>
          <img src="../../images/Replate-white.png" alt="" style="height:14px;object-fit:contain;opacity:0.5;"/>
          <span>All rights reserved.</span>
        </div>
      </div>
    </aside>

    <!-- ── MAIN CONTENT ── -->
    <main class="main">
      <div class="dashboard-grid">

        <!-- LEFT: Favourites grid -->
        <div class="profile-col">
          <h1 class="fav-title">My Favourites</h1>

          <?php if (empty($favourites)): ?>
            <div class="fav-empty">
              <h3>No favourites yet</h3>
              <p>Save items you love and they'll appear here.</p>
              <a href="category.php">Browse items</a>
            </div>
          <?php else: ?>
            <div class="fav-grid">
              <?php foreach ($favourites as $item):
                $itemId   = (string)$item['_id'];
                $isFree   = ($item['listingType'] ?? '') === 'donate';
                $provLogo = $item['_provLogo'] ?? '';
                $provName = $item['_provName'] ?? '';
              ?>
              <div class="fav-card">
                <div class="fav-card-top">
                  <?php if ($provLogo): ?>
                    <img class="fav-prov-logo" src="<?= htmlspecialchars($provLogo) ?>" alt="<?= htmlspecialchars($provName) ?>">
                  <?php else: ?>
                    <span class="fav-prov-name"><?= htmlspecialchars($provName) ?></span>
                  <?php endif; ?>

                  <!-- Heart button: clicking removes from favourites -->
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="remove"/>
                    <input type="hidden" name="itemId" value="<?= htmlspecialchars($itemId) ?>"/>
                    <button class="fav-heart-btn" type="submit" title="Remove from favourites">❤️</button>
                  </form>

                  <?php if (!empty($item['photoUrl'])): ?>
                    <img class="fav-img" src="<?= htmlspecialchars($item['photoUrl']) ?>" alt="<?= htmlspecialchars($item['itemName'] ?? '') ?>">
                  <?php else: ?>
                    <div class="fav-img-placeholder">No image</div>
                  <?php endif; ?>
                </div>

                <div class="fav-card-body">
                  <div class="fav-name-row">
                    <span class="fav-name"><?= htmlspecialchars($item['itemName'] ?? 'Item') ?></span>
                    <?php if ($isFree): ?>
                      <span class="fav-price fav-price-free">Free</span>
                    <?php else: ?>
                      <span class="fav-price"><?= number_format((float)($item['price'] ?? 0), 2) ?> ﷼</span>
                    <?php endif; ?>
                  </div>
                  <p class="fav-desc"><?= htmlspecialchars($item['description'] ?? '') ?></p>
                  <a class="fav-view-btn" href="item-details.php?itemId=<?= urlencode($itemId) ?>">View item</a>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div><!-- /profile-col -->

      </div><!-- /dashboard-grid -->
    </main>
  </div>

  <script>
    // ── Live Search (identical to customer-profile.php) ──
    const searchInput    = document.getElementById('searchInput');
    const searchDropdown = document.getElementById('searchDropdown');
    const searchWrap     = document.getElementById('searchWrap');
    let searchTimer = null;

    searchInput?.addEventListener('input', function() {
      clearTimeout(searchTimer);
      const q = this.value.trim();
      if (q.length < 2) { closeSearch(); return; }
      searchDropdown.innerHTML = '<div class="search-loading">Searching...</div>';
      searchDropdown.classList.add('open');
      searchTimer = setTimeout(() => doSearch(q), 280);
    });

    searchInput?.addEventListener('keydown', e => { if (e.key === 'Escape') closeSearch(); });

    document.addEventListener('click', e => {
      if (searchWrap && !searchWrap.contains(e.target)) closeSearch();
      const bellWrap = document.querySelector('.nav-bell-wrap');
      if (bellWrap && !bellWrap.contains(e.target)) {
        document.getElementById('notifDropdown')?.classList.remove('open');
      }
    });

    function closeSearch() { searchDropdown?.classList.remove('open'); }

    async function doSearch(q) {
      try {
        const res  = await fetch(`../../back-end/search.php?q=${encodeURIComponent(q)}`);
        const data = await res.json();
        renderResults(data, q);
      } catch(e) {
        searchDropdown.innerHTML = '<div class="search-empty">Something went wrong.</div>';
      }
    }

    function renderResults({ items = [], providers = [] }, q) {
      let html = '';
      html += '<div class="search-section-label">Providers</div>';
      if (providers.length) {
        providers.forEach(p => {
          const logo = p.businessLogo
            ? `<div class="search-provider-logo"><img src="${p.businessLogo}"/></div>`
            : `<div class="search-provider-logo">${p.businessName.charAt(0).toUpperCase()}</div>`;
          html += `<a class="search-item-row" href="providers-page.php?id=${p.id}">
            ${logo}
            <div><p class="search-item-name">${hl(p.businessName,q)}</p><p class="search-item-sub">${p.category}</p></div>
          </a>`;
        });
      } else {
        html += `<div class="search-no-match">No providers match "<em>${q}</em>"</div>`;
      }
      html += '<div class="search-divider"></div>';
      html += '<div class="search-section-label">Products</div>';
      if (items.length) {
        items.forEach(item => {
          const thumb = item.photoUrl
            ? `<div class="search-thumb"><img src="${item.photoUrl}"/></div>`
            : '<div class="search-thumb">🍱</div>';
          html += `<a class="search-item-row" href="item-details.php?id=${item.id}">
            ${thumb}
            <div><p class="search-item-name">${hl(item.name,q)}</p><p class="search-item-sub">Product</p></div>
            <span class="search-price">${item.price}</span>
          </a>`;
        });
      } else {
        html += `<div class="search-no-match">No products match "<em>${q}</em>"</div>`;
      }
      searchDropdown.innerHTML = html;
      searchDropdown.classList.add('open');
    }

    function hl(text, q) {
      return text.replace(
        new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi'),
        '<mark style="background:#fff4e6;color:#e07a1a;border-radius:3px;padding:0 2px;">$1</mark>'
      );
    }

    // ── Bell dropdown ──
    function toggleNotifDropdown() {
      document.getElementById('notifDropdown').classList.toggle('open');
    }

    // ── Mark all read ──
    function markAllRead() {
      document.querySelectorAll('.notif-card.unread').forEach(c => c.classList.remove('unread'));
      document.querySelector('.mark-read-btn')?.remove();
      const badge = document.querySelector('.notif-count-badge');
      if (badge) { badge.textContent = '0'; badge.classList.add('zero'); }
    }
  </script>
</body>
</html>
