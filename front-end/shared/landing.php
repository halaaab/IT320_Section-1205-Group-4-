<?php
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Category.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Favourite.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Notification.php';

// ── AJAX handlers (JSON requests only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
    header('Content-Type: application/json');
    if (empty($_SESSION['customerId'])) {
        echo json_encode(['success' => false, 'error' => 'unauthenticated']); exit;
    }
    $input  = json_decode(file_get_contents('php://input'), true);
    $ajaxAction = $input['action'] ?? 'toggle_fav';
    $cid    = $_SESSION['customerId'];

    // ── Toggle favourite ──
    if ($ajaxAction === 'toggle_fav') {
        $itemId = trim($input['itemId'] ?? '');
        if (!$itemId) { echo json_encode(['success' => false, 'error' => 'missing itemId']); exit; }
        $favModel = new Favourite();
        if ($favModel->isSaved($cid, $itemId)) {
            $favModel->remove($cid, $itemId);
            echo json_encode(['success' => true, 'action' => 'removed', 'liked' => false]);
        } else {
            $favModel->add($cid, $itemId);
            echo json_encode(['success' => true, 'action' => 'added', 'liked' => true]);
        }
        exit;
    }

    // ── Mark one notification as read ──
    if ($ajaxAction === 'mark_read') {
        $notifId = trim($input['notifId'] ?? '');
        if ($notifId) (new Notification())->markRead($notifId);
        echo json_encode(['success' => true]); exit;
    }

    // ── Mark all notifications as read ──
    if ($ajaxAction === 'mark_all_read') {
        (new Notification())->markAllRead($cid);
        echo json_encode(['success' => true]); exit;
    }

    echo json_encode(['success' => false, 'error' => 'unknown action']); exit;
}
$categoryModel = new Category();
$itemModel     = new Item();
$providerModel = new Provider();

// Load homepage data without hard limits
$categories = $categoryModel->getAll();
$providers  = $providerModel->findAll();
$allItems   = $itemModel->getAvailable();

// Split items into selling and donation items
$sellingItems  = [];
$donationItems = [];

foreach ($allItems as $item) {
    $listingType = strtolower(trim($item['listingType'] ?? ''));

    if ($listingType === 'donate') {
        $donationItems[] = $item;
    } else {
        $sellingItems[] = $item;
    }
}

// Sort selling items by lowest price, then append donations after them
usort($sellingItems, function ($a, $b) {
    return (float)($a['price'] ?? 0) <=> (float)($b['price'] ?? 0);
});

$items = array_merge($sellingItems, $donationItems);

$providerMap = [];
foreach ($providers as $provider) {
    $providerMap[(string)$provider['_id']] = $provider;
}

$categoryMap = [];
foreach ($categories as $category) {
    $categoryMap[(string)$category['_id']] = $category;
}

$isLoggedIn = !empty($_SESSION['customerId']);
$userName   = $_SESSION['userName'] ?? '';

// Cart count + notifications for logged-in customer
$cartCount     = 0;
$favItemIds    = [];
$notifications = [];
$unreadCount   = 0;
if ($isLoggedIn) {
    $customerId  = $_SESSION['customerId'];
    $now  = time();

    // Cart
    $cartModel   = new Cart();
    $cart        = $cartModel->getOrCreate($customerId);
    $cartItems   = (array)($cart['cartItems'] ?? []);
    $cartCount   = array_sum(array_map(fn($ci) => (int)($ci['quantity'] ?? 1), $cartItems));
    $cartItemIds = array_map(fn($ci) => (string)$ci['itemId'], $cartItems);

    // Favourites
    $favModel   = new Favourite();
    $favs       = $favModel->getByCustomer($customerId);
    $favItemIds = array_map(fn($f) => (string)$f['itemId'], $favs);

    // -- Write expiry_alert notifications for ALL items in cart/favs with a future expiry --
    // Three urgency tiers: red (<3 days), orange (3-7 days), yellow (>7 days)
    // Re-fires if the urgency level escalates since the last alert
    $notifModel  = new Notification();
    $watchedIds  = array_unique(array_merge($cartItemIds, $favItemIds));
    $itemModel2  = new Item();
    foreach ($watchedIds as $wid) {
        try {
            $witem = $itemModel2->findById($wid);
            if (!$witem || !isset($witem['expiryDate'])) continue;
            $expiry = $witem['expiryDate']->toDateTime()->getTimestamp();
            if ($expiry < $now) continue; // already expired, skip
            $daysLeft = ($expiry - $now) / 86400;
            // Determine urgency tier
            if ($daysLeft < 3)       { $urgency = 'red';     }
            elseif ($daysLeft < 7)   { $urgency = 'orange'; }
            else                     { $urgency = 'yellow';  }
            // Format time string
            $hoursLeft = (int)ceil($expiry - $now) / 3600;
            $timeStr   = $daysLeft < 2 ? ceil($hoursLeft) . 'h' : ceil($daysLeft) . ' days';
          $inCart = in_array($wid, $cartItemIds, true);
$inFav  = in_array($wid, $favItemIds, true);

if ($inCart && $inFav) {
    $lbl = 'Cart & Favourites';
} elseif ($inCart) {
    $lbl = 'Cart';
} else {
    $lbl = 'Favourites';
}
            // Check if we already sent this urgency level for this item (avoid spam)
            // We use the urgency word in the message to detect if tier changed
            $recentAlert = $notifModel->findAll([
                'customerId'    => Notification::toObjectId($customerId),
                'type'          => 'expiry_alert',
                'relatedItemId' => Notification::toObjectId($wid),
                'createdAt'     => ['$gte' => new MongoDB\BSON\UTCDateTime(($now - 86400) * 1000)],
            ], ['sort' => ['createdAt' => -1], 'limit' => 1]);
            // Only skip if last alert was the SAME urgency tier (re-fire when it escalates)
            if (!empty($recentAlert)) {
                $lastMsg = $recentAlert[0]['message'] ?? '';
                if (str_contains($lastMsg, '[' . $urgency . ']')) continue;
            }
           $notifModel->create(
    $customerId,
    'expiry_alert',
    '[' . $urgency . '] ' . $witem['itemName'] . ' (' . $lbl . ') expires in ' . $timeStr . '!',
    ['itemId' => $wid]
);
        } catch (Throwable) { continue; }
    }

    // -- Read all notifications, no limit --
    $notifications = (array)$notifModel->getByCustomer($customerId);
    $unreadCount   = $notifModel->getUnreadCount($customerId);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RePlate – Help Riyadh Go Green</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;1,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>

  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Playfair Display', serif;
      background: #fafdff;
    }

    /* ── NAVBAR ── */
    nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 48px;
      height: 72px;
      background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%);
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 2px 16px rgba(26, 58, 107, 0.18);
    }

    .nav-left {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .nav-logo {
      height: 100px;
    }

    .nav-cart-wrap { position: relative; display: flex; align-items: center; }

    .nav-cart {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      border: 2px solid rgba(255, 255, 255, 0.7);
      display: flex;
      justify-content: center;
      align-items: center;
      cursor: pointer;
      transition: background 0.2s;
      text-decoration: none;
    }

    .nav-cart:hover { background: rgba(255, 255, 255, 0.15); }

    .cart-badge {
      position: absolute;
      top: -5px;
      right: -5px;
      min-width: 19px;
      height: 19px;
      background: #e53935;
      border-radius: 50%;
      border: 2px solid #2255a4;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 10px;
      font-weight: 700;
      color: #fff;
      font-family: 'DM Sans', sans-serif;
      pointer-events: none;
      animation: cartPop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    @keyframes cartPop {
      0%   { transform: scale(0); opacity: 0; }
      70%  { transform: scale(1.25); opacity: 1; }
      100% { transform: scale(1); opacity: 1; }
    }

    .nav-avatar svg { stroke: #fff; }

    .nav-center {
      display: flex;
      align-items: center;
      gap: 40px;
    }

    .nav-center a {
      color: rgba(255, 255, 255, 0.85);
      text-decoration: none;
      font-weight: 500;
      font-size: 15px;
      transition: color 0.2s;
    }

    .nav-center a:hover { color: #fff; }

    .nav-center a.active {
      color: #fff;
      font-weight: 600;
      border-bottom: 2px solid #fff;
      padding-bottom: 2px;
    }

    .nav-right {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .nav-search-wrap { position: relative; }
    .search-dropdown { display: none; position: absolute; top: calc(100% + 10px); right: 0; width: 380px; background: #fff; border-radius: 16px; box-shadow: 0 8px 40px rgba(26,58,107,0.18); border: 1.5px solid #e0eaf5; z-index: 9999; overflow: hidden; }
    .search-dropdown.open { display: block; }
    .search-section-label { font-size: 11px; font-weight: 700; color: #b0c4d8; letter-spacing: 0.08em; text-transform: uppercase; padding: 12px 16px 6px; }
    .search-item-row { display: flex; align-items: center; gap: 12px; padding: 10px 16px; cursor: pointer; transition: background 0.15s; text-decoration: none; }
    .search-item-row:hover { background: #f0f6ff; }
    .search-thumb { width: 38px; height: 38px; border-radius: 10px; background: #e0eaf5; flex-shrink: 0; object-fit: cover; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .search-thumb img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
    .search-item-name { font-size: 14px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; }
    .search-item-sub { font-size: 12px; color: #7a8fa8; }
    .search-price { margin-left: auto; font-size: 13px; font-weight: 700; color: #e07a1a; white-space: nowrap; }
    .search-divider { height: 1px; background: #f0f5fc; margin: 4px 0; }
    .search-empty { padding: 24px 16px; text-align: center; color: #b0c4d8; font-size: 14px; font-family: 'Playfair Display', serif; }
    .search-loading { padding: 18px 16px; text-align: center; color: #b0c4d8; font-size: 13px; }
    .search-no-match { padding: 8px 16px 12px; font-size: 13px; color: #b0c4d8; font-style: italic; }
    .search-provider-logo { width: 38px; height: 38px; border-radius: 50%; background: #e0eaf5; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; color: #2255a4; }
    .search-provider-logo img { width: 100%; height: 100%; object-fit: cover; }

    .nav-search-wrap svg {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      opacity: 0.6;
      pointer-events: none;
    }

    .nav-search-wrap input {
      background: rgba(255, 255, 255, 0.15);
      border: 1.5px solid rgba(255, 255, 255, 0.4);
      border-radius: 50px;
      padding: 9px 16px 9px 36px;
      color: #fff;
      font-size: 14px;
      outline: none;
      width: 240px;
      font-family: 'Playfair Display', serif;
      transition: width 0.3s, background 0.2s;
    }

    .nav-search-wrap input::placeholder { color: rgba(255, 255, 255, 0.6); }
    .nav-search-wrap input:focus { width: 300px; background: rgba(255, 255, 255, 0.25); }

    .nav-avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      border: 2px solid rgba(255, 255, 255, 0.6);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }

    .btn-signup {
      background: #fff;
      color: #1a3a6b;
      border: none;
      border-radius: 50px;
      padding: 9px 22px;
      font-weight: 700;
      font-size: 14px;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      transition: transform 0.15s, box-shadow 0.15s;
    }

    .btn-signup:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(0,0,0,0.15); }

    .btn-login {
      background: transparent;
      color: #fff;
      border: 2px solid #fff;
      border-radius: 50px;
      padding: 8px 22px;
      font-weight: 700;
      font-size: 14px;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      transition: background 0.2s;
    }

    .btn-login:hover { background: rgba(255, 255, 255, 0.15); }

    .nav-bell-wrap { position: relative; }
    .nav-bell { width: 38px; height: 38px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); display: flex; align-items: center; justify-content: center; cursor: pointer; background: none; transition: background 0.2s; }
    .nav-bell:hover { background: rgba(255,255,255,0.15); }
    .bell-badge { position: absolute; top: -3px; right: -3px; min-width: 18px; height: 18px; background: #e53935; border-radius: 50%; border: 2px solid #2255a4; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: #fff; pointer-events: none; font-family: 'DM Sans', sans-serif; animation: cartPop 0.4s cubic-bezier(0.175,0.885,0.32,1.275); }
    .notif-dropdown { display: none; position: absolute; top: 50px; right: 0; width: 360px; background: #fff; border-radius: 20px; box-shadow: 0 12px 48px rgba(26,58,107,0.18); border: 1.5px solid #e0eaf5; z-index: 9999; overflow: hidden; }
    .notif-dropdown.open { display: block; animation: floatUp 0.2s ease; }
    .notif-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px 12px; border-bottom: 1.5px solid #f0f5fc; background: #fff; }
    .notif-header-title { font-size: 15px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; }
    .notif-mark-all { font-size: 12px; color: #2255a4; background: none; border: none; cursor: pointer; font-family: 'Playfair Display', serif; font-weight: 600; padding: 0; transition: color 0.2s; }
    .notif-mark-all:hover { color: #1a3a6b; }
    .notif-list { max-height: 420px; overflow-y: auto; scrollbar-width: thin; scrollbar-color: #c8d8ee transparent; }
    .notif-empty { padding: 36px 18px; text-align: center; color: #b0c4d8; font-size: 14px; }
    .notif-item { display: flex; align-items: flex-start; gap: 12px; padding: 14px 18px; border-bottom: 1px solid #f5f8fc; transition: background 0.15s; cursor: pointer; position: relative; }
    .notif-item:last-child { border-bottom: none; }
    .notif-item:hover { background: #f8fbff; }
    .notif-item.unread { background: #fffaf5; border-left: 3px solid #e07a1a; }
    .notif-item.unread:hover { background: #fff4e8; }
    .notif-icon { width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
    .notif-icon.expiry          { background: #fff4e6; }
    .notif-icon.expiry-red      { background: #fde8e8; }
    .notif-icon.expiry-orange   { background: #fff0e0; }
    .notif-icon.expiry-yellow   { background: #fffbe6; }
    .notif-icon.order           { background: #e8f7ee; }
    .notif-icon.pickup          { background: #e8f0ff; }
    .notif-icon.default         { background: #f2f4f8; }
    .notif-item.urgency-red     { border-left: 3px solid #c0392b; background: #fff8f8; }
    .notif-item.urgency-orange  { border-left: 3px solid #e07a1a; background: #fffaf5; }
    .notif-item.urgency-yellow  { border-left: 3px solid #d4ac0d; background: #fffef0; }
    .notif-body { flex: 1; min-width: 0; }
    .notif-msg { font-size: 13px; font-weight: 600; color: #1a3a6b; font-family: 'Playfair Display', serif; margin-bottom: 4px; line-height: 1.4; }
    .notif-item.unread .notif-msg { font-weight: 700; }
    .notif-time { font-size: 11px; color: #b0c4d8; }
    .notif-unread-dot { width: 8px; height: 8px; background: #e07a1a; border-radius: 50%; flex-shrink: 0; margin-top: 6px; }
    .notif-footer { padding: 12px 18px; border-top: 1.5px solid #f0f5fc; text-align: center; }
    .notif-footer a { font-size: 13px; color: #2255a4; text-decoration: none; font-weight: 600; font-family: 'Playfair Display', serif; }
    .notif-footer a:hover { color: #1a3a6b; }

    /* ── HERO ── */
    .hero {
      position: relative;
      min-height: calc(90vh - 50px);
      display: flex;
      align-items: center;
      overflow: hidden;
      background: #fafdff;
    }

    .hero-bg {
      position: absolute;
      inset: 0;
      background-image: url('../../images/landing-banner.png');
      background-size: cover;
      background-position: top right;
      opacity: 1;
    }

    .hero-overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(90deg, rgba(250,253,255,0.95) 35%, rgba(250,253,255,0.5) 60%, transparent 100%);
      pointer-events: none;
    }

    .hero-content {
      position: relative;
      z-index: 2;
      padding: 50px;
      max-width: 1000px;
    }

    .hero-subtitle {
      font-size: 22px;
      color: #3a5a8a;
      font-weight: 400;
      margin-bottom: 12px;
      letter-spacing: 0.01em;
      line-height: 1.5;
    }

    .hero-title {
      font-family: 'Playfair Display', serif;
      font-size: 64px;
      margin-bottom: 30px;
      line-height: 1.3;
      letter-spacing: -1px;
      background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .btn-shop {
      background: #1a3a6b;
      color: #fff;
      border: none;
      border-radius: 50px;
      padding: 18px 48px;
      font-size: 17px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      letter-spacing: 0.02em;
      box-shadow: 0 8px 24px rgba(26, 58, 107, 0.3);
      transition: transform 0.2s, box-shadow 0.2s, background 0.2s;
    }

    .btn-shop:hover {
      background: #2255a4;
      transform: translateY(-2px);
      box-shadow: 0 12px 32px rgba(26, 58, 107, 0.4);
    }

    /* ── SHARED SECTION ── */
    .section {
      padding: 48px 48px 32px;
      background: #fafdff;
    }

    .section-title {
      font-size: 32px;
      font-family: 'Playfair Display', serif;
      margin-bottom: 28px;
      background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      display: inline-block;
    }

    .section-title .gradient {
      background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    /* ── SCROLL ROW ── */
    .scroll-wrapper {
      position: relative;
    }

    .scroll-row {
      display: flex;
      gap: 20px;
      overflow-x: auto;
      overflow-y: visible;
      scroll-behavior: smooth;
      /* padding gives room for box-shadow and translateY hover */
      padding: 10px 6px 18px;
      margin: -10px -6px -18px;
      cursor: grab;
      -ms-overflow-style: none;
      scrollbar-width: none;
      align-items: stretch;
    }

    .scroll-row::-webkit-scrollbar { display: none; }
    .scroll-row.dragging { cursor: grabbing; user-select: none; }

    .scroll-row > * {
      flex: 0 0 auto;
    }

    .scroll-arrows {
      display: flex;
      justify-content: center;
      gap: 14px;
      margin-top: 24px;
    }

    .arrow-btn {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      border: 2px solid #1a3a6b;
      background: #fff;
      color: #1a3a6b;
      font-size: 13px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.2s, color 0.2s;
    }

    .arrow-btn:hover { background: #1a3a6b; color: #fff; }

    /* ── PRODUCT CARDS ── */
    .product-card {
      min-width: 260px;
      max-width: 260px;
      background: #f2f4f8;
      border-radius: 24px;
      border: 1.5px solid #c8d8ee;
      padding: 18px 18px 20px;
      display: flex;
      flex-direction: column;
      gap: 0;
      box-shadow: 0 2px 14px rgba(26, 58, 107, 0.07);
      flex-shrink: 0;
      transition: box-shadow 0.2s, transform 0.2s;
    }

    .product-card:hover {
      box-shadow: 0 8px 28px rgba(26, 58, 107, 0.13);
      transform: translateY(-3px);
    }

    .product-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
    }

    .provider-logo-box {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .provider-logo-img {
      width: 32px;
      height: 32px;
      background: #c8d8ee;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .provider-logo-name {
      font-size: 15px;
      font-weight: 700;
      color: #1a3a6b;
      font-family: 'Playfair Display', serif;
    }

    .heart-btn {
      background: none;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0;
      transition: transform 0.2s;
    }

    .heart-btn:hover { transform: scale(1.15); }

    .heart-btn svg {
      width: 28px;
      height: 28px;
      overflow: visible;
    }

    .heart-btn .heart-path {
      fill: none;
      stroke: #8b1a1a;
      stroke-width: 2;
      transition: fill 0.2s, stroke 0.2s;
    }

    .heart-btn.liked .heart-path {
      fill: #c0392b;
      stroke: #c0392b;
    }

    .product-img-box {
      width: 100%;
      height: 200px;
      background: #d8e6f5;
      border-radius: 14px;
      margin-bottom: 16px;
    }

    .category-tag {
      display: inline-block;
      background: #e8f0ff;
      color: #2255a4;
      font-size: 11px;
      font-weight: 700;
      font-family: 'DM Sans', sans-serif;
      border-radius: 50px;
      padding: 3px 10px;
      margin-bottom: 6px;
      letter-spacing: 0.04em;
      text-transform: uppercase;
    }

    .product-divider {
      width: 100%;
      height: 1.5px;
      background: #c0d2e8;
      margin-bottom: 14px;
    }

    .product-bottom {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .product-name-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }

    .product-name {
      font-size: 18px;
      font-weight: 700;
      color: #1a3a6b;
      font-family: 'Playfair Display', serif;
    }

    .product-price-row {
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .product-price {
      font-size: 16px;
      font-weight: 700;
      color: #e07a1a;
    }

    .sar-icon {
      width: 22px;
      height: 22px;
      flex-shrink: 0;
      object-fit: contain;
    }

    .product-desc {
      font-size: 13px;
      color: #4a6a9a;
      line-height: 1.5;
      font-family: 'Playfair Display', serif;
    }

    .btn-view {
      background: #1a3a6b;
      color: #fff;
      border: none;
      border-radius: 50px;
      padding: 12px 0;
      font-size: 15px;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      font-weight: 700;
      width: 80%;
      text-align: center;
      margin: 8px auto 0;
      display: block;
      transition: background 0.2s;
    }

    .btn-view:hover { background: #2255a4; }

    /* ── CATEGORY CARDS ── */
    .category-card {
      min-width: 300px;
      max-width: 300px;
      background: #f2f4f8;
      border-radius: 24px;
      border: 1.5px solid #c8d8ee;
      padding: 20px;
      display: flex;
      align-items: center;
      gap: 16px;
      box-shadow: 0 2px 14px rgba(26, 58, 107, 0.07);
      flex-shrink: 0;
      transition: box-shadow 0.2s, transform 0.2s;
    }

    .category-card:hover {
      box-shadow: 0 8px 28px rgba(26, 58, 107, 0.13);
      transform: translateY(-3px);
    }

    .category-img-box {
      width: 100px;
      height: 100px;
      background: #c8d8ee;
      border-radius: 12px;
      flex-shrink: 0;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .category-img-box img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 12px;
    }

    .category-info { display: flex; flex-direction: column; gap: 8px; }

    .category-name {
      font-size: 17px;
      font-weight: 700;
      color: #1a3a6b;
      font-family: 'Playfair Display', serif;
    }

    .btn-cat-shop {
      background: #1a3a6b;
      color: #fff;
      border: none;
      border-radius: 50px;
      padding: 6px 18px;
      font-size: 12px;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      font-weight: 700;
      width: fit-content;
      transition: background 0.2s;
    }

    .btn-cat-shop:hover { background: #2255a4; }

    /* ── PROVIDER CARDS ── */
    .provider-card {
      min-width: 220px;
      max-width: 220px;
      background: #f2f4f8;
      border-radius: 24px;
      border: 1.5px solid #c8d8ee;
      padding: 28px 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      box-shadow: 0 2px 14px rgba(26, 58, 107, 0.07);
      flex-shrink: 0;
      transition: box-shadow 0.2s, transform 0.2s;
    }

    .provider-card:hover {
      box-shadow: 0 8px 28px rgba(26, 58, 107, 0.13);
      transform: translateY(-3px);
    }

    .provider-logo-big {
      width: 90px;
      height: 90px;
      background: #e8f0f8;
      border-radius: 50%;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .provider-logo-big img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
    }
    .provider-logo-big .logo-placeholder {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      font-weight: 700;
      color: #2255a4;
      font-family: 'Playfair Display', serif;
      background: linear-gradient(135deg, #dce8f8 0%, #c8d8ee 100%);
    }

    .provider-type-label {
      font-size: 14px;
      color: #4a6a9a;
      font-family: 'Playfair Display', serif;
      font-weight: 700;
    }

    /* ── WHO WE ARE ── */
    .who-section {
      padding: 80px 48px;
      display: flex;
      align-items: center;
      gap: 56px;
      position: relative;
      overflow: hidden;
      background-image: url('../../images/whoweare.png');
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
    }

    .who-section::before {
      content: '';
      position: absolute;
      inset: 0;
      
      pointer-events: none;
    }

    .who-logo-box {
      position: relative;
      z-index: 1;
      flex-shrink: 0;
    }

    .who-content {
      position: relative;
      z-index: 1;
    }

    .who-content h2 {
      font-size: 34px;
      font-family: 'Playfair Display', serif;
      margin-bottom: 18px;
      background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      display: inline-block;
    }

    .who-content h2 .gradient {
      background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .who-content p {
      font-size: 15px;
      color: #1a3a6b;
      line-height: 1.9;
      font-family: 'Playfair Display', serif;
    }

    /* ── FOOTER ── */
    footer {
      background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%);
      padding: 28px 48px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 14px;
    }

    .footer-top {
      display: flex;
      align-items: center;
      gap: 18px;
    }

    .social-icon {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      border: 1.5px solid rgba(255,255,255,0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 16px;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      font-family: 'Playfair Display', serif;
      transition: background 0.2s;
    }

    .social-icon:hover { background: rgba(255,255,255,0.15); }

    .footer-divider {
      width: 1px;
      height: 22px;
      background: rgba(255,255,255,0.3);
    }

    .footer-brand {
      display: flex;
      align-items: center;
      gap: 8px;
      color: #fff;
      font-size: 16px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
    }

    .footer-email {
      display: flex;
      align-items: center;
      gap: 6px;
      color: rgba(255,255,255,0.9);
      font-size: 14px;
      font-family: 'Playfair Display', serif;
    }

    .footer-bottom {
      display: flex;
      align-items: center;
      gap: 8px;
      color: rgba(255,255,255,0.7);
      font-size: 13px;
      font-family: 'Playfair Display', serif;
    }

    /* ── SCROLL MARGIN for sticky nav ── */
    #categories, #providers {
      scroll-margin-top: 80px;
    }
    .btn-signup,
.btn-login {
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}
/* ══════════════════════════════════════
   MOBILE RESPONSIVE
══════════════════════════════════════ */
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
.hamburger.open span:nth-child(1) { transform: translateY(7.5px) rotate(45deg); }
.hamburger.open span:nth-child(2) { opacity: 0; }
.hamburger.open span:nth-child(3) { transform: translateY(-7.5px) rotate(-45deg); }

.mobile-menu {
  display: none;
  position: fixed;
  inset: 0;
  top: 72px;
  background: linear-gradient(180deg, #1a3a6b 0%, #2255a4 100%);
  z-index: 99;
  flex-direction: column;
  padding: 32px 28px;
  gap: 0;
}
.mobile-menu.open { display: flex; }
.mobile-menu a {
  color: rgba(255,255,255,0.85);
  font-size: 22px;
  font-weight: 700;
  font-family: 'Playfair Display', serif;
  padding: 18px 0;
  border-bottom: 1px solid rgba(255,255,255,0.12);
  text-decoration: none;
}
.mobile-menu a:hover { color: #fff; }
.mobile-search {
  margin-top: 24px;
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
  font-size: 15px;
  outline: none;
  font-family: 'Playfair Display', serif;
}
.mobile-search input::placeholder { color: rgba(255,255,255,0.6); }

@media (max-width: 768px) {
  /* Nav */
  nav { padding: 0 18px; }
  .nav-logo { height: 72px; }
  .nav-center { display: none; }
  .nav-search-wrap { display: none; }
  .hamburger { display: flex; }

  /* Hero */
  .hero { min-height: 52vh; }
  .hero-content { padding: 28px 20px; }
  .hero-subtitle { font-size: 15px; margin-bottom: 8px; }
  .hero-title { font-size: 34px; margin-bottom: 20px; letter-spacing: -0.5px; }
  .btn-shop { padding: 13px 32px; font-size: 15px; }
  .hero-overlay {
    background: linear-gradient(180deg, rgba(250,253,255,0.92) 0%, rgba(250,253,255,0.6) 60%, transparent 100%);
  }

  /* Sections */
  .section { padding: 32px 16px 24px; }
  .section-title { font-size: 24px; margin-bottom: 18px; }

  /* Scroll rows — show 2 cards at once */
  .scroll-row { gap: 12px; padding: 8px 4px 14px; }
  .product-card { min-width: calc(50vw - 28px); max-width: calc(50vw - 28px); }
  .category-card { min-width: calc(50vw - 28px); max-width: calc(50vw - 28px); }
  .provider-card { min-width: calc(50vw - 28px); max-width: calc(50vw - 28px); }

  /* Product card internals */
  .product-img-box { height: 120px; }
  .product-name { font-size: 14px; }
  .product-price { font-size: 13px; }
  .product-desc { font-size: 11px; }
  .btn-view { font-size: 12px; padding: 9px 0; }
  .sar-icon { width: 16px; height: 16px; }

  /* Category card internals */
  .category-img-box { width: 64px; height: 64px; }
  .category-name { font-size: 13px; }

  /* Provider card internals */
  .provider-logo-big { width: 56px; height: 56px; }
  .provider-logo-big .logo-placeholder { font-size: 18px; }
  .provider-logo-name { font-size: 12px !important; }
  .provider-type-label { font-size: 11px; }

  /* Who we are */
  .who-section { flex-direction: column; padding: 36px 20px; gap: 20px; text-align: center; }
  .who-logo-box img { height: 100px !important; }
  .who-content h2 { font-size: 22px; }
  .who-content p { font-size: 13px; line-height: 1.7; }

  /* Footer */
  footer { padding: 24px 16px; }
  .footer-top { flex-wrap: wrap; justify-content: center; gap: 12px; }
  .footer-bottom { font-size: 11px; }

  /* Arrows */
  .scroll-arrows { margin-top: 14px; }
  .arrow-btn { width: 28px; height: 28px; font-size: 11px; }
}
    /* ── EMPTY STATE ── */
    .empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 16px;
      padding: 48px 24px;
      color: #8aa3c0;
      font-size: 15px;
      font-family: 'Playfair Display', serif;
      text-align: center;
    }

    /* ── ANIMATIONS ── */
    @keyframes slideIn {
      from { opacity: 0; transform: translateX(-30px); }
      to   { opacity: 1; transform: translateX(0); }
    }

    @keyframes floatUp {
      from { opacity: 0; transform: translateY(30px); }
      to   { opacity: 1; transform: translateY(0); }
    }
@media (max-width: 768px) {
  .nav-btn,
  .btn-login,
  .btn-signup {
    padding: 6px 14px;     /* smaller */
    font-size: 13px;       /* smaller text */
    border-radius: 30px;
  }
  .btn-cat-shop {
font-size: 10px; padding: 9px 0;
  }
}
@media (max-width: 768px) {
  .item-card {
    padding: 12px;
    border-radius: 18px;
  }
}
@media (max-width: 768px) {
  .item-card img {
    width: 100%;
    height: 120px;      /* 🔥 smaller image */
    object-fit: cover;
    border-radius: 12px;
  }
}
@media (max-width: 768px) {
  .item-title {
    font-size: 14px;
  }

  .item-subtitle,
  .item-meta {
    font-size: 12px;
  }

  .item-price {
    font-size: 13px;
  }
}
@media (max-width: 768px) {
  .item-card {
    gap: 6px; /* if using flex/grid */
  }

  .item-card .section {
    margin-bottom: 6px;
  }
}
@media (max-width: 768px) {
  .item-card .icon {
    transform: scale(0.85);
  }
}
  </style>
</head>
<body>

  <!-- NAVBAR -->
  <nav>
    <div class="nav-left">
      <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate Logo" />
      <div class="nav-cart-wrap">
        <a href="../customer/cart.php" class="nav-cart">
          <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;" />
        </a>
        <?php if ($isLoggedIn && $cartCount > 0): ?>
        <span class="cart-badge" id="cartBadge"><?= $cartCount ?></span>
        <?php else: ?>
        <span class="cart-badge" id="cartBadge" style="display:none;">0</span>
        <?php endif; ?>
      </div>
    </div>

    <div class="nav-center">
      <a href="#" class="active">Home Page</a>
      <a href="#categories">Categories</a>
      <a href="#providers">Providers</a>
    </div>

    <div class="nav-right">

<?php if (!$isLoggedIn): ?>
<button class="btn-signup" onclick="window.location.href='signup-customer.php'">Sign up</button>
<button class="btn-login" onclick="window.location.href='login.php'">Log in</button>
<?php endif; ?>
      <div class="nav-search-wrap" id="searchWrap">
        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
          <circle cx="11" cy="11" r="8"/>
          <path d="M21 21l-4.35-4.35"/>
        </svg>
        <input type="text" id="searchInput" placeholder="Search products or providers..." autocomplete="off"/>
        <div class="search-dropdown" id="searchDropdown"></div>
      </div>
      <?php if ($isLoggedIn): ?>
      <div class="nav-bell-wrap">
        <button class="nav-bell" onclick="toggleNotifDropdown()">
          <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        </button>
        <?php if ($unreadCount > 0): ?>
        <span class="bell-badge" id="bellBadge"><?= $unreadCount ?></span>
        <?php endif; ?>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-header">
            <span class="notif-header-title">Notifications</span>
            <?php if ($unreadCount > 0): ?>
            <button class="notif-mark-all" onclick="markAllRead()">Mark all read</button>
            <?php endif; ?>
          </div>
          <div class="notif-list">
          <?php if (empty($notifications)): ?>
          <div class="notif-empty">
            <svg width="32" height="32" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 10px;display:block;"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            You're all caught up!
          </div>
          <?php else: ?>
          <?php foreach ($notifications as $notif):
            $nid      = (string)($notif['_id'] ?? '');
            $ntype    = $notif['type'] ?? 'default';
            $nmsg = $notif['message'] ?? '';
            $nread    = (bool)($notif['isRead'] ?? false);
            $ntime    = '';
            if (!empty($notif['createdAt'])) {
                $ts = $notif['createdAt'] instanceof MongoDB\BSON\UTCDateTime
                    ? $notif['createdAt']->toDateTime()->getTimestamp()
                    : strtotime((string)$notif['createdAt']);
                $diff = time() - $ts;
                if ($diff < 60)        $ntime = 'Just now';
                elseif ($diff < 3600)  $ntime = floor($diff/60) . 'm ago';
                elseif ($diff < 86400) $ntime = floor($diff/3600) . 'h ago';
                else                   $ntime = date('d M', $ts);
            }
            // Icon per type
            $iconClass = 'default'; $iconSvg = '';
            if ($ntype === 'expiry_alert') {
                $rawM_ = $notif['message'] ?? '';
                $urg_  = str_contains($rawM_, '[red]') ? 'red' : (str_contains($rawM_, '[orange]') ? 'orange' : 'yellow');
                $urgC_ = $urg_==='red' ? '#c0392b' : ($urg_==='orange' ? '#e07a1a' : '#d4ac0d');
                $iconClass = 'expiry-' . $urg_;
                $iconSvg = '<svg width="16" height="16" fill="none" stroke="' . $urgC_ . '" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
            } elseif ($ntype === 'order_placed') {
                $iconClass = 'order';
                $iconSvg = '<svg width="16" height="16" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><polyline points="9 12 11 14 15 10"/></svg>';
            } elseif ($ntype === 'order_completed') {
                $iconClass = 'order';
                $iconSvg = '<svg width="16" height="16" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>';
            } elseif ($ntype === 'order_cancelled') {
                $iconClass = 'cancelled';
                $iconSvg = '<svg width="16" height="16" fill="none" stroke="#e53935" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
            } elseif ($ntype === 'pickup_reminder') {
                $iconClass = 'pickup';
                $iconSvg = '<svg width="16" height="16" fill="none" stroke="#2255a4" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="3"/></svg>';
            }
          ?>
          <?php $urgClass_ = $ntype==='expiry_alert' ? ' urgency-'.($urg_??'yellow') : ''; ?>
          <div class="notif-item <?= $nread ? '' : 'unread' ?><?= $urgClass_ ?>" data-id="<?= $nid ?>" onclick="markRead(this)">
            <div class="notif-icon <?= $iconClass ?>"><?= $iconSvg ?></div>
            <div class="notif-body">
              <?php $nmsgClean_ = trim(preg_replace('/\[(?:red|orange|yellow|pickup|completed|cancelled)\]\s*/', '', $nmsg)); ?>
<p class="notif-msg"><?= htmlspecialchars($nmsgClean_) ?></p>
              <span class="notif-time"><?= $ntime ?></span>
            </div>
            <?php if (!$nread): ?><div class="notif-unread-dot"></div><?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          </div>
          <div class="notif-footer">
            <a href="../customer/customer-profile.php#notifications">View all notifications</a>
          </div>
        </div>
      </div>
      <a href="../customer/customer-profile.php" class="nav-avatar">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
          <circle cx="12" cy="7" r="4"/>
        </svg>
      </a>
                  <button id="hamburger" class="hamburger" onclick="toggleMobileMenu()" aria-label="Open menu">
    <span></span>
    <span></span>
    <span></span>
  </button>
      <?php else: ?>
      <button class="nav-avatar" onclick="document.getElementById('authModal').style.display='flex'" style="border:none;cursor:pointer;">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
          <circle cx="12" cy="7" r="4"/>
        </svg>
      </button>

      <?php endif; ?>

      <!-- Auth required modal -->
      <div id="authModal" style="display:none;position:fixed;inset:0;background:rgba(12,22,45,0.5);z-index:9999;justify-content:center;align-items:center;" onclick="if(event.target===this)this.style.display='none'">
        <div style="background:#fff;border-radius:24px;padding:44px 40px;max-width:400px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.2);animation:floatUp 0.3s ease;">
          <div style="width:64px;height:64px;background:#e8f0ff;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
            <svg width="28" height="28" fill="none" stroke="#2255a4" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </div>
          <h3 style="font-size:22px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;margin-bottom:10px;">Sign in to continue</h3>
          <p style="font-size:14px;color:#7a8fa8;margin-bottom:28px;line-height:1.6;">Please log in or create an account to access your profile.</p>
          <div style="display:flex;gap:12px;justify-content:center;">
            <a href="login.php" style="flex:1;padding:13px 0;border-radius:50px;background:#1a3a6b;color:#fff;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;text-decoration:none;display:flex;align-items:center;justify-content:center;transition:background 0.2s;" onmouseover="this.style.background='#2255a4'" onmouseout="this.style.background='#1a3a6b'">Log in</a>
            <a href="signup-customer.php" style="flex:1;padding:13px 0;border-radius:50px;background:transparent;color:#1a3a6b;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;text-decoration:none;border:2px solid #1a3a6b;display:flex;align-items:center;justify-content:center;transition:background 0.2s;" onmouseover="this.style.background='#f0f5ff'" onmouseout="this.style.background='transparent'">Sign up</a>
          </div>
          <button onclick="document.getElementById('authModal').style.display='none'" style="margin-top:18px;background:none;border:none;color:#b0c4d8;font-size:13px;cursor:pointer;font-family:'Playfair Display',serif;">Maybe later</button>
        </div>
      </div>
    </div>
  </nav>
<!-- Mobile menu (slides in below nav) -->
<div class="mobile-menu" id="mobileMenu">
    <div class="mobile-search">
    <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
    </svg>
    <input type="text" id="mobileSearchInput" placeholder="Search products or providers..." autocomplete="off"/>
   
    <div class="search-dropdown" id="mobileSearchDropdown"></div>  <!-- ADD THIS -->
  </div>
  <a href="../shared/landing.php" onclick="closeMobileMenu()">Home Page</a>
  <a href="#categories" onclick="closeMobileMenu()">Categories</a>
  <a href="#providers" onclick="closeMobileMenu()">Providers</a>
  <?php if (!$isLoggedIn): ?>
  <a href="login.php" onclick="closeMobileMenu()">Log in</a>
  <a href="signup-customer.php" onclick="closeMobileMenu()">Sign up</a>
  <?php endif; ?>

</div>
  <!-- HERO -->
<section class="hero">
    <div class="hero-bg"></div>
    <div class="hero-overlay"></div>
    <div class="hero-content">
      <p class="hero-subtitle">Join the movement to reduce food waste and</p>
      <h1 class="hero-title">help Riyadh go green</h1>
      <a href="../customer/category.php" class="btn-shop" style="display: inline-block; text-decoration: none;">Shop now</a>
    </div>
  </section>

  <!-- BEST PRICES -->
  <section class="section">
    <h2 class="section-title"><span class="gradient">Best</span> Prices</h2>
    <?php if (!empty($items)): ?>
    <div class="scroll-wrapper">
      <div class="scroll-row" id="prices-row">
       <?php foreach ($items as $item): ?>
  <?php
    $itemName  = htmlspecialchars($item['itemName'] ?? 'Item');
    $itemPrice = ($item['listingType'] ?? '') === 'donate'
      ? null
      : number_format((float)($item['price'] ?? 0), 2);
    $itemDesc  = htmlspecialchars($item['description'] ?? '');
    $itemId    = (string)($item['_id'] ?? '');

    $providerId = (string)($item['providerId'] ?? '');
    $categoryId = (string)($item['categoryId'] ?? '');

    $prov = $providerMap[$providerId] ?? [];
    $cat  = $categoryMap[$categoryId] ?? [];

    $providerName = htmlspecialchars($prov['businessName'] ?? 'Provider');
    $categoryName = htmlspecialchars($cat['name'] ?? '');
  ?>
        <div class="product-card">
          <div class="product-card-top">
            <div class="provider-logo-box">
           <div class="provider-logo-img" style="overflow:hidden;border-radius:50%;display:flex;align-items:center;justify-content:center;">
  <?php if (!empty($prov['businessLogo'])): ?>
    <img src="<?= htmlspecialchars($prov['businessLogo']) ?>" style="width:100%;height:100%;object-fit:cover;" />
  <?php else: ?>
    <span style="font-size:13px;font-weight:700;color:#2255a4;"><?= mb_strtoupper(mb_substr($providerName, 0, 1)) ?></span>
  <?php endif; ?>
</div>
              <span class="provider-logo-name"><?= $providerName ?></span>
            </div>
            <button class="heart-btn <?= in_array($itemId, $favItemIds) ? 'liked' : '' ?>" data-item-id="<?= $itemId ?>"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path class="heart-path" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></button>
          </div>
          <?php if ($categoryName): ?>
          <span class="category-tag"><?= $categoryName ?></span>
          <?php endif; ?>
         <div class="product-img-box" style="overflow:hidden;">
  <?php if (!empty($item['photoUrl'])): ?>
    <img src="<?= htmlspecialchars($item['photoUrl']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:14px;" />
  <?php else: ?>
    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#8aa3c0;font-size:13px;">No image</div>
  <?php endif; ?>
</div>
          <div class="product-divider"></div>
          <div class="product-bottom">
            <div class="product-name-row">
              <span class="product-name"><?= $itemName ?></span>
              <div class="product-price-row">
                <?php if ($itemPrice === null): ?>
                  <span class="product-price" style="color:#1a6b3a;">Donation</span>
                <?php else: ?>
                  <span class="product-price"><?= $itemPrice ?></span>
                  <img class="sar-icon" src="../../images/SAR.png" alt="SAR" />
                <?php endif; ?>
              </div>
            </div>
            <p class="product-desc"><?= $itemDesc ?></p>
            <button class="btn-view" onclick="window.location.href='../customer/item-details.php?itemId=<?= $itemId ?>'">View item</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="scroll-arrows">
      <button class="arrow-btn" onclick="scrollRow('prices-row',-1)">&#9664;</button>
      <button class="arrow-btn" onclick="scrollRow('prices-row', 1)">&#9654;</button>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <svg width="48" height="48" fill="none" stroke="#b0c4d8" stroke-width="1.5" viewBox="0 0 24 24">
        <path d="M3 6h18M3 12h18M3 18h18"/>
      </svg>
      <p>No items available yet — check back soon once providers start listing!</p>
    </div>
    <?php endif; ?>
  </section>

  <!-- CATEGORIES -->
  <section class="section" id="categories">
    <h2 class="section-title"><span class="gradient">Categories</span></h2>
    <div class="scroll-wrapper">
      <div class="scroll-row" id="categories-row">
        <?php
        $catImageMap = [
            'bakery'    => '../../images/bakary.png',
            'groceries' => '../../images/grocery.png',
            'grocery'   => '../../images/grocery.png',
            'meals'     => '../../images/meals.png',
            'meal'      => '../../images/meals.png',
            'dairy'     => '../../images/diary.png',
            'sweets'    => '../../images/sweets.png',
            'sweet'     => '../../images/sweets.png',
        ];
        foreach ($categories as $cat):
            $catId   = (string)$cat['_id'];
            $catName = htmlspecialchars($cat['name'] ?? '');
            $catKey  = strtolower(trim($cat['name'] ?? ''));
            $catImg  = $catImageMap[$catKey] ?? '../../images/bakary.png';
        ?>
        <a class="category-card" href="../customer/category.php?categoryId=<?= urlencode($catId) ?>" style="text-decoration:none;">
          <div class="category-img-box">
            <img src="<?= $catImg ?>" alt="<?= $catName ?>"/>
          </div>
          <div class="category-info">
            <span class="category-name"><?= $catName ?></span>
            <button class="btn-cat-shop">Shop now</button>
          </div>
        </a>
        <?php endforeach; ?>

      </div>
    </div>
    <div class="scroll-arrows">
      <button class="arrow-btn" onclick="scrollRow('categories-row',-1)">&#9664;</button>
      <button class="arrow-btn" onclick="scrollRow('categories-row', 1)">&#9654;</button>
    </div>
  </section>

  <!-- PROVIDERS -->
  <section class="section" id="providers">
    <h2 class="section-title"><span class="gradient">Providers</span></h2>
    <?php if (!empty($providers)): ?>
    <div class="scroll-wrapper">
      <div class="scroll-row" id="providers-row">
        <?php foreach ($providers as $provider):
          $bizName  = htmlspecialchars($provider['businessName'] ?? 'Provider');
          $category = htmlspecialchars($provider['category'] ?? '');
          $provId   = (string)($provider['_id'] ?? '');
        ?>
        <a class="provider-card" href="../customer/providers-page.php?providerId=<?= urlencode($provId) ?>" style="text-decoration:none;">
          <div class="provider-logo-big">
            <?php if (!empty($provider['businessLogo'])): ?>
              <img src="<?= htmlspecialchars($provider['businessLogo']) ?>" alt="<?= $bizName ?>"/>
            <?php else: ?>
              <div class="logo-placeholder"><?= mb_strtoupper(mb_substr($bizName, 0, 1)) ?></div>
            <?php endif; ?>
          </div>
          <span class="provider-logo-name" style="font-size:15px;font-weight:700;color:#1a3a6b;text-align:center;"><?= $bizName ?></span>
          <span class="provider-type-label"><?= $category ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="scroll-arrows">
      <button class="arrow-btn" onclick="scrollRow('providers-row',-1)">&#9664;</button>
      <button class="arrow-btn" onclick="scrollRow('providers-row', 1)">&#9654;</button>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <svg width="48" height="48" fill="none" stroke="#b0c4d8" stroke-width="1.5" viewBox="0 0 24 24">
        <rect x="3" y="3" width="18" height="18" rx="3"/>
        <path d="M3 9h18"/>
      </svg>
      <p>No providers have joined yet — they'll appear here once they sign up!</p>
    </div>
    <?php endif; ?>
  </section>

  <!-- WHO WE ARE -->
  <section class="who-section">
    <div class="who-logo-box">
      <img src="../../images/Replate-logo.png" alt="Replate" style="height:200px;object-fit:contain;opacity:1;"/>
    </div>
    <div class="who-content">
      <h2><span class="gradient">Who</span> we are?</h2>
      <p>"RePlate is a sustainability platform designed to reduce food waste in Riyadh by connecting individuals and businesses who have surplus or near-expiry food with people who need it. Through simple food listing, pickup scheduling, and timely expiry alerts, RePlate turns food that would otherwise go to waste into value for the community."</p>
    </div>
  </section>

  <!-- FOOTER -->
  <footer>
    <div class="footer-top">
      <div style="display:flex;align-items:center;gap:10px;">
        <a class="social-icon" href="#">in</a>
        <a class="social-icon" href="#">&#120143;</a>
        <a class="social-icon" href="#">&#9834;</a>
      </div>
      <div class="footer-divider"></div>
      <div class="footer-brand">
    
      </div>
           <img src="../../images/Replate-white.png" alt="Replate" style="height:80px;object-fit:contain;opacity:1;" />
      <div class="footer-divider"></div>
    
      <div class="footer-email">
        <svg width="16" height="16" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2" viewBox="0 0 24 24">
          <rect x="2" y="4" width="20" height="16" rx="2"/>
          <path d="M2 7l10 7 10-7"/>
        </svg>
        <a mailto="Replate@gmail.com">Replate@gmail.com</a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© 2026</span>
      <img src="../../images/Replate-white.png" alt="Replate" style="height:50px;object-fit:contain;opacity:1;" />
      <span>All rights reserved.</span>
    </div>
  </footer>

  <script data-cfasync="false" src="/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script><script>
    // ── Heart toggle → AJAX favourite ──
    const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;

    document.querySelectorAll('.heart-btn').forEach(btn => {
      btn.addEventListener('click', async (e) => {
        e.stopPropagation();

        if (!IS_LOGGED_IN) {
          document.getElementById('authModal').style.display = 'flex';
          return;
        }

        const itemId = btn.dataset.itemId;
        if (!itemId) return;

        // Optimistic toggle
        btn.classList.toggle('liked');

        try {
          const res  = await fetch('landing.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body:    JSON.stringify({ itemId })
          });
          const data = await res.json();

          if (!data.success) {
            // Revert if failed
            btn.classList.toggle('liked');
          } else {
            // Brief pop animation
            btn.style.transform = 'scale(1.35)';
            setTimeout(() => { btn.style.transform = ''; }, 220);
          }
        } catch {
          btn.classList.toggle('liked'); // revert on network error
        }
      });
    });

    // ── Active nav link on click ──
    document.querySelectorAll('.nav-center a').forEach(link => {
      link.addEventListener('click', () => {
        document.querySelectorAll('.nav-center a').forEach(l => l.classList.remove('active'));
        link.classList.add('active');
      });
    });

    // ── Active nav on scroll ──
    const sections = [
      { id: 'categories', link: document.querySelector('.nav-center a[href="#categories"]') },
      { id: 'providers',  link: document.querySelector('.nav-center a[href="#providers"]') },
    ];
    const homeLink = document.querySelector('.nav-center a[href="#"]');
    window.addEventListener('scroll', () => {
      let inSection = false;
      sections.forEach(({ id, link }) => {
        const el = document.getElementById(id);
        if (!el) return;
        const rect = el.getBoundingClientRect();
        if (rect.top <= 100 && rect.bottom > 100) {
          document.querySelectorAll('.nav-center a').forEach(l => l.classList.remove('active'));
          link.classList.add('active');
          inSection = true;
        }
      });
      if (!inSection && window.scrollY < 200) {
        document.querySelectorAll('.nav-center a').forEach(l => l.classList.remove('active'));
        homeLink.classList.add('active');
      }
    });

    // ── Scroll arrows ──
    function scrollRow(id, dir) {
      document.getElementById(id).scrollBy({ left: dir * 280, behavior: 'smooth' });
    }

    // ── Drag scroll ──
    document.querySelectorAll('.scroll-row').forEach(row => {
      let isDown = false, startX, scrollLeft;
      row.addEventListener('mousedown', e => {
        isDown = true; row.classList.add('dragging');
        startX = e.pageX - row.offsetLeft; scrollLeft = row.scrollLeft;
      });
      row.addEventListener('mouseleave', () => { isDown = false; row.classList.remove('dragging'); });
      row.addEventListener('mouseup',    () => { isDown = false; row.classList.remove('dragging'); });
      row.addEventListener('mousemove',  e => {
        if (!isDown) return; e.preventDefault();
        row.scrollLeft = scrollLeft - (e.pageX - row.offsetLeft - startX) * 1.5;
      });
    });

    // ── Bell notification dropdown ──
    function toggleNotifDropdown() {
      document.getElementById('notifDropdown').classList.toggle('open');
    }

    // ── Mark single notification as read ──
    function markRead(el) {
      if (!el.classList.contains('unread')) return;
      const notifId = el.dataset.id;
      el.classList.remove('unread');
      const dot = el.querySelector('.notif-unread-dot');
      if (dot) dot.remove();
      updateBellBadge(-1);
      fetch('landing.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'mark_read', notifId })
      }).catch(() => {});
    }

    // ── Mark all notifications as read ──
    function markAllRead() {
      const unread = document.querySelectorAll('#notifDropdown .notif-item.unread');
      unread.forEach(el => {
        el.classList.remove('unread');
        const dot = el.querySelector('.notif-unread-dot');
        if (dot) dot.remove();
      });
      const badge = document.getElementById('bellBadge');
      if (badge) badge.style.display = 'none';
      const btn = document.querySelector('.notif-mark-all');
      if (btn) btn.style.display = 'none';
      fetch('landing.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify({ action: 'mark_all_read' })
      }).catch(() => {});
    }

    // ── Update bell badge count ──
    function updateBellBadge(delta) {
      const badge = document.getElementById('bellBadge');
      if (!badge) return;
      const current = parseInt(badge.textContent) || 0;
      const next = Math.max(0, current + delta);
      if (next === 0) { badge.style.display = 'none'; }
      else { badge.textContent = next; badge.style.display = 'flex'; }
    }

    // ── Live Search ──
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

    // ── Unified outside-click handler ──
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

      // Providers always first
      html += '<div class="search-section-label">Providers</div>';
      if (providers.length) {
        providers.forEach(p => {
          const logo = p.businessLogo
            ? `<div class="search-provider-logo"><img src="${p.businessLogo}"/></div>`
            : `<div class="search-provider-logo">${p.businessName.charAt(0).toUpperCase()}</div>`;
          html += `<a class="search-item-row" href="../customer/providers-page.php?providerId=${p.id}">
            ${logo}
            <div><p class="search-item-name">${hl(p.businessName,q)}</p><p class="search-item-sub">${p.category}</p></div>
          </a>`;
        });
      } else {
        html += `<div class="search-no-match">No providers match "<em>${q}</em>"</div>`;
      }

      html += '<div class="search-divider"></div>';

      // Products second
      html += '<div class="search-section-label">Products</div>';
      if (items.length) {
        items.forEach(item => {
          const thumb = item.photoUrl
            ? `<div class="search-thumb"><img src="${item.photoUrl}"/></div>`
            : '<div class="search-thumb">🍱</div>';
          html += `<a class="search-item-row" href="../customer/item-details.php?itemId=${item.id}">
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
// ── Mobile menu ──
function toggleMobileMenu() {
  const menu = document.getElementById('mobileMenu');
  const btn  = document.getElementById('hamburger');
  menu.classList.toggle('open');
  btn.classList.toggle('open');
  document.body.style.overflow = menu.classList.contains('open') ? 'hidden' : '';
}
function closeMobileMenu() {
  document.getElementById('mobileMenu').classList.remove('open');
  document.getElementById('hamburger').classList.remove('open');
  document.body.style.overflow = '';
}
// ── Replace renderResults ──
function buildResultsHTML({ items = [], providers = [] }, q) {
  let html = '';

  html += '<div class="search-section-label">Providers</div>';
  if (providers.length) {
    providers.forEach(p => {
      const logo = p.businessLogo
        ? `<div class="search-provider-logo"><img src="${p.businessLogo}"/></div>`
        : `<div class="search-provider-logo">${p.businessName.charAt(0).toUpperCase()}</div>`;
      html += `<a class="search-item-row" href="../customer/providers-page.php?providerId=${p.id}">
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
      html += `<a class="search-item-row" href="../customer/item-details.php?itemId=${item.id}">
        ${thumb}
        <div><p class="search-item-name">${hl(item.name,q)}</p><p class="search-item-sub">Product</p></div>
        <span class="search-price">${item.price}</span>
      </a>`;
    });
  } else {
    html += `<div class="search-no-match">No products match "<em>${q}</em>"</div>`;
  }

  return html;
}

function renderResults(data, q) {
  searchDropdown.innerHTML = buildResultsHTML(data, q);
  searchDropdown.classList.add('open');
}
// ── Mobile search mirrors desktop search ──
document.getElementById('mobileSearchInput')?.addEventListener('input', function () {
  clearTimeout(searchTimer);
  const q = this.value.trim();
  const mobileDD = document.getElementById('mobileSearchDropdown');

  if (q.length < 2) {
    mobileDD.classList.remove('open');
    return;
  }

  mobileDD.innerHTML = '<div class="search-loading">Searching...</div>';
  mobileDD.classList.add('open');

  searchTimer = setTimeout(async () => {
    try {
      const res  = await fetch(`../../back-end/search.php?q=${encodeURIComponent(q)}`);
      const data = await res.json();
      // render into mobile dropdown directly
      mobileDD.innerHTML = buildResultsHTML(data, q);
      mobileDD.classList.add('open');
    } catch {
      mobileDD.innerHTML = '<div class="search-empty">Something went wrong.</div>';
    }
  }, 280);
});
document.addEventListener('click', e => {
  if (searchWrap && !searchWrap.contains(e.target)) closeSearch();

  const mobileSearch = document.querySelector('.mobile-search');
  const mobileDD = document.getElementById('mobileSearchDropdown');
  if (mobileSearch && !mobileSearch.contains(e.target)) {
    mobileDD?.classList.remove('open');
  }

  const bellWrap = document.querySelector('.nav-bell-wrap');
  if (bellWrap && !bellWrap.contains(e.target)) {
    document.getElementById('notifDropdown')?.classList.remove('open');
  }
});
    function hl(text, q) {
      return text.replace(
        new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi'),
        '<mark style="background:#fff4e6;color:#e07a1a;border-radius:3px;padding:0 2px;">$1</mark>'
      );
    }

  </script>

</body>
</html>