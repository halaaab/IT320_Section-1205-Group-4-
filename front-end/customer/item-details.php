<?php
// ================================================================
// item-details.php — Single Item Detail Page
// ================================================================
// URL PARAMS:  ?itemId=xxx
// VARIABLES:
//   $item       → full item object
//   $provider   → provider who listed this item
//   $location   → pickup location for this item
//   $category   → category object
//   $isSaved    → bool — has the logged-in customer favourited this?
//   $inCart     → bool — is this item already in cart?
// POST ACTIONS:
//   action=add_to_cart  → adds item to cart, redirects back
//   action=toggle_fav   → saves/unsaves item, redirects back
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/PickupLocation.php';
require_once '../../back-end/models/Category.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Favourite.php';
require_once '../../back-end/models/Notification.php';

$itemId     = $_GET['itemId'] ?? '';
$item       = null;
$provider   = null;
$location   = null;
$category   = null;
$isSaved    = false;
$inCart     = false;
$customerId = $_SESSION['customerId'] ?? null;

// ── AJAX handlers (JSON requests only) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
    header('Content-Type: application/json');
    if (empty($_SESSION['customerId'])) {
        echo json_encode(['success' => false, 'error' => 'unauthenticated']); exit;
    }
    $input      = json_decode(file_get_contents('php://input'), true);
    $ajaxAction = $input['action'] ?? 'toggle_fav';
    $cid        = $_SESSION['customerId'];

    if ($ajaxAction === 'mark_read') {
        $notifId = trim($input['notifId'] ?? '');
        if ($notifId) (new Notification())->markRead($notifId);
        echo json_encode(['success' => true]); exit;
    }

    if ($ajaxAction === 'mark_all_read') {
        (new Notification())->markAllRead($cid);
        echo json_encode(['success' => true]); exit;
    }

    echo json_encode(['success' => false, 'error' => 'unknown action']); exit;
}

if ($itemId) {
    $itemModel     = new Item();
    $providerModel = new Provider();
    $locationModel = new PickupLocation();
    $categoryModel = new Category();

    $item = $itemModel->findById($itemId);
    if ($item && (empty($item['isAvailable']) || (int)($item['quantity'] ?? 0) <= 0)) {
    header('Location: ../shared/landing.php');
    exit;
}

    if ($item) {
        try {
            if (!empty($item['expiryDate'])) {
                $now = new DateTime('now', new DateTimeZone('Asia/Riyadh'));
                $expiryDate = $item['expiryDate'] instanceof MongoDB\BSON\UTCDateTime
                    ? $item['expiryDate']->toDateTime()
                    : new DateTime((string)$item['expiryDate']);
                $expiryDate->setTimezone(new DateTimeZone('Asia/Riyadh'));
                if ($expiryDate < $now) { $item = null; }
            }
        } catch (Throwable $e) {}

        if ($item) {
            $provider = $providerModel->findById((string) $item['providerId']);
            $location = $locationModel->findById((string) $item['pickupLocationId']);
            $category = $categoryModel->findById((string) $item['categoryId']);
            if ($provider) unset($provider['passwordHash']);

            if ($customerId) {
                $isSaved = (new Favourite())->isSaved($customerId, $itemId);
                $cart    = (new Cart())->getOrCreate($customerId);
                foreach (($cart['cartItems'] ?? []) as $ci) {
                    if ((string) $ci['itemId'] === $itemId) { $inCart = true; break; }
                }
            }
        }
    }
}

// ── Handle POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $customerId && $item) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_to_cart') {
        (new Cart())->addItem($customerId, [
            'itemId'             => $itemId,
            'providerId'         => (string) $item['providerId'],
            'quantity'           => (int) ($_POST['quantity'] ?? 1),
            'itemName'           => $item['itemName'],
            'price'              => $item['price'],
            'selectedPickupTime' => trim($_POST['pickupTime'] ?? ''),
        ]);
        header("Location: item-details.php?itemId=$itemId&added=1");
        exit;
    }
    if ($action === 'toggle_fav') {
        $favModel = new Favourite();
        $isSaved  ? $favModel->remove($customerId, $itemId) : $favModel->add($customerId, $itemId);
        header("Location: item-details.php?itemId=$itemId");
        exit;
    }
}

$justAdded  = isset($_GET['added']);
$isLoggedIn = !empty($customerId);

// ── Notifications for logged-in customer ──
$cartCount     = 0;
$notifications = [];
$unreadCount   = 0;
if ($isLoggedIn) {
    $cartModel = new Cart();
    $cart      = $cartModel->getOrCreate($customerId);
    $cartItems = (array)($cart['cartItems'] ?? []);
    $cartCount = array_sum(array_map(fn($ci) => (int)($ci['quantity'] ?? 1), $cartItems));

    $notifModel    = new Notification();
    $notifications = (array)$notifModel->getByCustomer($customerId);
    $unreadCount   = $notifModel->getUnreadCount($customerId);
}

function fmtExpiry($value): string {
    if (!$value) return '';
    try {
        $now = new DateTime('now', new DateTimeZone('Asia/Riyadh'));
        $dt  = $value instanceof MongoDB\BSON\UTCDateTime ? $value->toDateTime() : new DateTime((string)$value);
        $dt->setTimezone(new DateTimeZone('Asia/Riyadh'));
        $nowDate = new DateTime($now->format('Y-m-d'), new DateTimeZone('Asia/Riyadh'));
        $expDate = new DateTime($dt->format('Y-m-d'), new DateTimeZone('Asia/Riyadh'));
        $daysLeft = (int)$nowDate->diff($expDate)->format('%r%a');
        if ($daysLeft < 0) return '';
        $dayLabel = $daysLeft === 1 ? 'day' : 'days';
        return $dt->format('d M Y') . " ($daysLeft $dayLabel)";
    } catch (Throwable $e) { return ''; }
}

function fmtPickupTime($value): string {
    if (!$value) return '';
    try {
        $dt = new DateTime((string)$value, new DateTimeZone('Asia/Riyadh'));
        return $dt->format('Y-m-d - h:iA');
    } catch (Throwable $e) { return (string)$value; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RePlate – <?= htmlspecialchars($item['itemName'] ?? 'Item') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;1,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DM Sans', sans-serif; background: #f0f5fc; color: #1a2a45; min-height: 100vh; padding-bottom: 40px; }
    a { text-decoration: none; color: inherit; }

    /* ══════════════════════════════════════
       NAVBAR  (identical to landing.php)
    ══════════════════════════════════════ */
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

    .nav-left { display: flex; align-items: center; gap: 16px; }
    .nav-logo  { height: 100px; }

    .nav-cart-wrap { position: relative; display: flex; align-items: center; }
    .nav-cart {
      width: 40px; height: 40px; border-radius: 50%;
      border: 2px solid rgba(255,255,255,0.7);
      display: flex; justify-content: center; align-items: center;
      cursor: pointer; transition: background 0.2s; text-decoration: none;
    }
    .nav-cart:hover { background: rgba(255,255,255,0.15); }

    .cart-badge {
      position: absolute; top: -5px; right: -5px;
      min-width: 19px; height: 19px;
      background: #e53935; border-radius: 50%; border: 2px solid #2255a4;
      display: flex; align-items: center; justify-content: center;
      font-size: 10px; font-weight: 700; color: #fff;
      font-family: 'DM Sans', sans-serif; pointer-events: none;
      animation: cartPop 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    @keyframes cartPop {
      0%   { transform: scale(0); opacity: 0; }
      70%  { transform: scale(1.25); opacity: 1; }
      100% { transform: scale(1); opacity: 1; }
    }

    .nav-center { display: flex; align-items: center; gap: 40px; }
    .nav-center a {
      color: rgba(255,255,255,0.85); text-decoration: none;
      font-weight: 500; font-size: 15px;
      font-family: 'Playfair Display', serif; transition: color 0.2s;
    }
    .nav-center a:hover { color: #fff; }
    .nav-center a.active { color: #fff; font-weight: 600; border-bottom: 2px solid #fff; padding-bottom: 2px; }

    .nav-right { display: flex; align-items: center; gap: 12px; }

    /* Search */
    .nav-search-wrap { position: relative; }
    .nav-search-wrap svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); opacity: 0.6; pointer-events: none; }
    .nav-search-wrap input {
      background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.4);
      border-radius: 50px; padding: 9px 16px 9px 36px; color: #fff;
      font-size: 14px; outline: none; width: 240px;
      font-family: 'Playfair Display', serif; transition: width 0.3s, background 0.2s;
    }
    .nav-search-wrap input::placeholder { color: rgba(255,255,255,0.6); }
    .nav-search-wrap input:focus { width: 300px; background: rgba(255,255,255,0.25); }

    /* Search dropdown */
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

    /* Avatar */
    .nav-avatar {
      width: 38px; height: 38px; border-radius: 50%;
      border: 2px solid rgba(255,255,255,0.6);
      display: flex; align-items: center; justify-content: center;
      cursor: pointer; background: rgba(255,255,255,0.08); transition: background 0.2s;
    }
    .nav-avatar:hover { background: rgba(255,255,255,0.2); }

    /* Auth buttons */
    .btn-signup {
      background: #fff; color: #1a3a6b; border: none; border-radius: 50px;
      padding: 9px 22px; font-weight: 700; font-size: 14px;
      font-family: 'Playfair Display', serif; cursor: pointer;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1); transition: transform 0.15s, box-shadow 0.15s;
      text-decoration: none; display: inline-flex; align-items: center; justify-content: center;
    }
    .btn-signup:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(0,0,0,0.15); }
    .btn-login {
      background: transparent; color: #fff; border: 2px solid #fff; border-radius: 50px;
      padding: 8px 22px; font-weight: 700; font-size: 14px;
      font-family: 'Playfair Display', serif; cursor: pointer; transition: background 0.2s;
      text-decoration: none; display: inline-flex; align-items: center; justify-content: center;
    }
    .btn-login:hover { background: rgba(255,255,255,0.15); }

    /* Bell / Notifications */
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

    /* Auth required modal */
    @keyframes floatUp {
      from { opacity: 0; transform: translateY(30px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* ══════════════════════════════════════
       ITEM DETAIL CONTENT STYLES
    ══════════════════════════════════════ */
    .item-hero { background: linear-gradient(180deg, #d8e8f8 0%, #f0f5fc 100%); position: relative; padding: 32px 40px 0; min-height: 300px; display: flex; align-items: flex-end; justify-content: center; }
    .hero-back { position: absolute; top: 20px; left: 24px; width: 46px; height: 46px; border-radius: 50%; border: none; background: #cdd9e8; display: grid; place-items: center; color: #1b3f92; font-size: 28px; font-weight: 700; cursor: pointer; text-decoration: none; transition: background 0.2s; line-height: 1; font-family: 'Playfair Display', serif; }
    .hero-back:hover { background: #bfcee2; }
    .hero-fav { position: absolute; top: 20px; right: 24px; width: 46px; height: 46px; border-radius: 50%; border: none; background: #cdd9e8; display: grid; place-items: center; font-size: 26px; cursor: pointer; transition: background 0.2s, transform 0.2s; }
    .hero-fav:hover { background: #bfcee2; transform: scale(1.1); }
    .prov-hero-logo  { position: absolute; bottom: 22px; left: calc(50% - 230px); height: 52px; max-width: 110px; object-fit: contain; z-index: 2; }
    .prov-hero-name  { position: absolute; bottom: 32px; left: calc(50% - 230px); font-size: 14px; font-weight: 700; color: #7a8fa8; font-style: italic; z-index: 2; }
    .hero-food-img   { max-height: 270px; max-width: 480px; width: 100%; object-fit: contain; position: relative; z-index: 1; filter: drop-shadow(0 8px 20px rgba(26,58,107,0.14)); }
    .hero-placeholder { width: 100%; max-width: 480px; height: 250px; background: linear-gradient(135deg,#c8dbf5,#dce7f5); border-radius: 20px; display: grid; place-items: center; color: #7a8fa8; font-size: 16px; }

    .container { max-width: 860px; margin: 0 auto; padding: 32px 24px 40px; }

    .title-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 10px; }
    .item-title { font-family: 'Playfair Display', serif; font-size: 42px; color: #1a3a6b; font-weight: 700; line-height: 1.2; }
    .item-price-big { display: flex; align-items: center; gap: 6px; font-weight: 700; font-size: 28px; color: #e07a1a; }
    .price-free-big { color: #1a6b3a; }
    .item-ingredients { font-size: 16px; color: #7a8fa8; margin-bottom: 12px; line-height: 1.6; }
    .expiry-row { display: flex; align-items: center; gap: 8px; font-size: 15px; color: #1a2a45; margin-bottom: 22px; }
    .expiry-date { color: #e07a1a; font-weight: 700; }
    .divider { height: 1.5px; background: #dce7f5; margin: 22px 0; }
    .section-label { font-family: 'Playfair Display', serif; font-size: 22px; color: #1a3a6b; font-weight: 700; margin-bottom: 14px; }

    .qty-row { display: flex; align-items: center; background: #fff; border: 1.5px solid #dce7f5; border-radius: 50px; width: fit-content; overflow: hidden; box-shadow: 0 2px 8px rgba(26,58,107,0.07); margin-bottom: 28px; }
    .qty-btn { width: 52px; height: 52px; border: none; background: transparent; font-size: 24px; font-weight: 700; color: #1a3a6b; cursor: pointer; transition: background 0.15s; display: grid; place-items: center; }
    .qty-btn:hover { background: #e8f0ff; }
    .qty-val { min-width: 60px; text-align: center; font-size: 22px; font-weight: 700; color: #1a2a45; border-left: 1.5px solid #dce7f5; border-right: 1.5px solid #dce7f5; height: 52px; display: grid; place-items: center; }

    .pickup-times { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }
    .time-chip { padding: 11px 20px; border: 1.5px solid #dce7f5; border-radius: 14px; background: #fff; font-size: 15px; font-weight: 500; color: #1a2a45; cursor: pointer; transition: all 0.2s; user-select: none; }
    .time-chip.selected, .time-chip:hover { border-color: #1a3a6b; background: #e8f0ff; color: #1a3a6b; font-weight: 700; }

    .map-box { width: 100%; border-radius: 20px; overflow: hidden; border: 1.5px solid #dce7f5; height: 210px; margin-bottom: 8px; box-shadow: 0 4px 14px rgba(26,58,107,0.08); display: block; cursor: pointer; }
    .map-box iframe { width: 100%; height: 100%; border: none; border-radius: 20px; display: block; }
    .map-box-placeholder { width: 100%; border-radius: 20px; overflow: hidden; border: 1.5px solid #dce7f5; background: linear-gradient(135deg,#c8dbf5,#dce7f5); height: 210px; display: flex; align-items: center; justify-content: center; margin-bottom: 8px; box-shadow: 0 4px 14px rgba(26,58,107,0.08); }
    .map-pin { font-size: 40px; }
    .location-text { font-size: 13px; color: #7a8fa8; margin-top: 6px; }

    .detail-add-btn-wrap { display: flex; justify-content: center; margin-top: 24px; }
    .detail-add-btn { background: #e88922; color: #fff; border: none; border-radius: 22px; padding: 16px 26px; width: 100%; max-width: 420px; display: flex; align-items: center; justify-content: space-between; font-family: 'Playfair Display', serif; font-size: 22px; font-weight: 700; cursor: pointer; box-shadow: 0 6px 16px rgba(224,122,26,0.25); transition: 0.2s; }
    .detail-add-btn:hover { transform: translateY(-1px); }
    .btn-text { font-size: 26px; }
    .btn-price { display: flex; align-items: center; gap: 8px; font-size: 22px; font-weight: 700; }
    .riyal-icon { width: 26px; height: 26px; object-fit: contain; }
    .riyal-icon-top { width: 28px; height: 28px; object-fit: contain; margin-left: 6px; vertical-align: middle; }

    .flash { background: #e8f7ec; border: 1.5px solid #b6dfbf; color: #1a6b3a; border-radius: 14px; padding: 13px 18px; margin-bottom: 18px; font-weight: 600; }
    .unavail { background: #fff1f0; border: 1.5px solid #efb1ab; color: #b23a2c; border-radius: 14px; padding: 13px 18px; margin-bottom: 20px; font-weight: 600; }
    .not-found { text-align: center; padding: 80px 24px; }
    .not-found h2 { font-family: 'Playfair Display', serif; font-size: 30px; color: #1a3a6b; margin-bottom: 10px; }
    .back-link { display: inline-block; margin-top: 18px; background: #e07a1a; color: #fff; border-radius: 50px; padding: 12px 28px; font-weight: 700; }

    /* ══════════════════════════════════════
       FOOTER  (identical to landing.php)
    ══════════════════════════════════════ */
    footer {
      background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%);
      padding: 28px 48px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 14px;
    }
    .footer-top { display: flex; align-items: center; gap: 18px; }
    .social-icon { width: 42px; height: 42px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,0.5); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; text-decoration: none; font-family: 'Playfair Display', serif; transition: background 0.2s; }
    .social-icon:hover { background: rgba(255,255,255,0.15); }
    .footer-divider { width: 1px; height: 22px; background: rgba(255,255,255,0.3); }
    .footer-email { display: flex; align-items: center; gap: 6px; color: rgba(255,255,255,0.9); font-size: 14px; font-family: 'Playfair Display', serif; }
    .footer-bottom { display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.7); font-size: 13px; font-family: 'Playfair Display', serif; }

    /* ── HAMBURGER ── */
    .hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer;background:none;border:none;padding:6px}
    .hamburger span{display:block;width:24px;height:2.5px;background:#fff;border-radius:2px;transition:all .3s}
    .hamburger.open span:nth-child(1){transform:translateY(7.5px) rotate(45deg)}
    .hamburger.open span:nth-child(2){opacity:0}
    .hamburger.open span:nth-child(3){transform:translateY(-7.5px) rotate(-45deg)}
    .mobile-menu{display:none;position:fixed;inset:0;top:72px;background:linear-gradient(180deg,#1a3a6b 0%,#2255a4 100%);z-index:9999;flex-direction:column;padding:32px 28px;gap:0;overflow-y:auto}
    .mobile-menu.open{display:flex}
    .mobile-menu a{color:rgba(255,255,255,0.85);font-size:22px;font-weight:700;font-family:'Playfair Display',serif;padding:18px 0;border-bottom:1px solid rgba(255,255,255,0.12);text-decoration:none}
    .mobile-menu a:hover{color:#fff}
    .mobile-search{margin-bottom:16px;position:relative}
    .mobile-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);opacity:.6;pointer-events:none}
    .mobile-search input{width:100%;background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.4);border-radius:50px;padding:12px 16px 12px 40px;color:#fff;font-size:15px;outline:none;font-family:'Playfair Display',serif}
    .mobile-search input::placeholder{color:rgba(255,255,255,0.6)}
    .mobile-search-dropdown{display:none;background:#fff;border-radius:16px;margin:0 0 8px;overflow:hidden;max-height:55vh;overflow-y:auto;box-shadow:0 4px 24px rgba(26,58,107,0.18)}
    .mobile-search-dropdown.open{display:block}
    .mobile-search-dropdown a.search-item-row{color:#1a3a6b!important;font-size:14px!important;font-weight:400!important;padding:10px 16px!important;border-bottom:1px solid #f5f8fc!important;display:flex!important;align-items:center!important;gap:12px!important;background:#fff!important}
    .mobile-search-dropdown a.search-item-row:hover{background:#f0f6ff!important}
    .mobile-search-dropdown .search-item-name{font-size:14px!important;font-weight:700!important;color:#1a3a6b!important}
    .mobile-search-dropdown .search-item-sub{font-size:12px!important;color:#7a8fa8!important;font-weight:400!important}
    .mobile-search-dropdown .search-price{margin-left:auto!important;font-size:13px!important;font-weight:700!important;color:#e07a1a!important;white-space:nowrap!important}
    .mobile-search-dropdown .search-section-label{background:#fff;padding:10px 16px 6px!important;font-size:11px!important;font-weight:700!important;color:#b0c4d8!important;letter-spacing:.08em!important;text-transform:uppercase!important}
    .mobile-search-dropdown .search-divider{height:1px;background:#f0f5fc;margin:4px 0}
    .mobile-search-dropdown .search-provider-logo{width:38px!important;height:38px!important;border-radius:50%!important;background:#e0eaf5!important;flex-shrink:0!important;overflow:hidden!important;display:flex!important;align-items:center!important;justify-content:center!important;font-size:15px!important;font-weight:700!important;color:#2255a4!important}
    .mobile-search-dropdown .search-provider-logo img{width:100%;height:100%;object-fit:cover}
    .mobile-search-dropdown .search-thumb{width:38px!important;height:38px!important;border-radius:10px!important;background:#e0eaf5!important;flex-shrink:0!important;display:flex!important;align-items:center!important;justify-content:center!important;font-size:18px!important;overflow:hidden!important}
    .mobile-search-dropdown .search-thumb img{width:100%;height:100%;object-fit:cover;border-radius:10px}

    @media(max-width:768px) {
      nav { padding: 0 18px; }
      .nav-logo { height: 74px; }
      .nav-center { display: none; }
      .nav-search-wrap { display: none; }
      .hamburger { display: flex; }
      .item-hero { padding: 20px 16px 0; }
      .item-title { font-size: 28px; }
    }
  </style>
</head>
<body>

<!-- ══════════════════════════════════════
     NAVBAR  (matching landing.php)
══════════════════════════════════════ -->
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
    <a href="../shared/landing.php">Home Page</a>
    <a href="#categories">Categories</a>
    <a href="#providers">Providers</a>
  </div>

  <div class="nav-right">
    <?php if (!$isLoggedIn): ?>
      <a href="../shared/signup-customer.php" class="btn-signup">Sign up</a>
      <a href="../shared/login.php" class="btn-login">Log in</a>
    <?php endif; ?>

    <div class="nav-search-wrap" id="searchWrap">
      <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
      </svg>
      <input type="text" id="searchInput" placeholder="Search products or providers..." autocomplete="off"/>
      <div class="search-dropdown" id="searchDropdown"></div>
    </div>

    <?php if ($isLoggedIn): ?>
      <!-- Bell -->
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
                $nid   = (string)($notif['_id'] ?? '');
                $ntype = $notif['type'] ?? 'default';
                $nmsg  = htmlspecialchars($notif['message'] ?? '');
                $nread = (bool)($notif['isRead'] ?? false);
                $ntime = '';
                if (!empty($notif['createdAt'])) {
                    $ts   = $notif['createdAt'] instanceof MongoDB\BSON\UTCDateTime
                          ? $notif['createdAt']->toDateTime()->getTimestamp()
                          : strtotime((string)$notif['createdAt']);
                    $diff = time() - $ts;
                    if ($diff < 60)        $ntime = 'Just now';
                    elseif ($diff < 3600)  $ntime = floor($diff/60) . 'm ago';
                    elseif ($diff < 86400) $ntime = floor($diff/3600) . 'h ago';
                    else                   $ntime = date('d M', $ts);
                }
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

      <!-- Profile avatar -->
      <a href="../customer/customer-profile.php" class="nav-avatar">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
          <circle cx="12" cy="7" r="4"/>
        </svg>
      </a>
    <?php else: ?>
      <!-- Guest avatar → auth modal -->
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
          <a href="../shared/login.php" style="flex:1;padding:13px 0;border-radius:50px;background:#1a3a6b;color:#fff;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;text-decoration:none;display:flex;align-items:center;justify-content:center;" onmouseover="this.style.background='#2255a4'" onmouseout="this.style.background='#1a3a6b'">Log in</a>
          <a href="../shared/signup-customer.php" style="flex:1;padding:13px 0;border-radius:50px;background:transparent;color:#1a3a6b;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;text-decoration:none;border:2px solid #1a3a6b;display:flex;align-items:center;justify-content:center;" onmouseover="this.style.background='#f0f5ff'" onmouseout="this.style.background='transparent'">Sign up</a>
        </div>
        <button onclick="document.getElementById('authModal').style.display='none'" style="margin-top:18px;background:none;border:none;color:#b0c4d8;font-size:13px;cursor:pointer;font-family:'Playfair Display',serif;">Maybe later</button>
      </div>
    </div>

    <button id="hamburger" class="hamburger" onclick="toggleMobileMenu()" aria-label="Open menu">
      <span></span><span></span><span></span>
    </button>
  </div>
</nav>

<div class="mobile-menu" id="mobileMenu">
  <div class="mobile-search">
    <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
    <input type="text" id="mobileSearchInput" placeholder="Search products or providers..." autocomplete="off"/>
  </div>
  <div id="mobileSearchDropdown" class="mobile-search-dropdown"></div>
  <a href="../shared/landing.php" onclick="closeMobileMenu()">Home Page</a>
  <a href="../shared/landing.php#categories" onclick="closeMobileMenu()">Categories</a>
  <a href="../shared/landing.php#providers" onclick="closeMobileMenu()">Providers</a>
</div>

<!-- ══════════════════════════════════════
     ITEM DETAIL CONTENT
══════════════════════════════════════ -->
<?php if (!$item): ?>
  <div class="not-found">
    <h2>Item not found</h2>
    <p style="color:#7a8fa8">This item may no longer be available.</p>
    <a class="back-link" href="category.php">Back to categories</a>
  </div>
<?php else:
  $isFree      = ($item['listingType'] ?? '') === 'donate';
  $price       = (float)($item['price'] ?? 0);
  $maxQty      = max(99, (int)($item['quantity'] ?? 99));
  $pickupTimes = $item['pickupTimes'] ?? [];
  $provName    = $provider['businessName'] ?? '';
  $provLogo    = $provider['businessLogo'] ?? '';
  $expiryStr   = fmtExpiry($item['expiryDate'] ?? null);
  $lat         = $location['coordinates']['lat'] ?? null;
  $lng         = $location['coordinates']['lng'] ?? null;
  $mapLink     = ($lat && $lng) ? "https://www.google.com/maps?q={$lat},{$lng}" : null;
?>

<!-- Hero image -->
<div class="item-hero">
  <a class="hero-back" href="javascript:history.back()">‹</a>

  <?php if ($provLogo): ?>
    <img class="prov-hero-logo" src="<?= htmlspecialchars($provLogo) ?>" alt="<?= htmlspecialchars($provName) ?>">
  <?php else: ?>
    <span class="prov-hero-name"><?= htmlspecialchars($provName) ?></span>
  <?php endif; ?>

  <?php if ($isLoggedIn): ?>
    <form method="post" style="display:inline">
      <input type="hidden" name="action" value="toggle_fav">
      <button class="hero-fav <?= $isSaved ? 'liked' : '' ?>" type="submit">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width:28px;height:28px;overflow:visible"><path style="fill:<?= $isSaved ? '#c0392b' : 'none' ?>;stroke:#8b1a1a;stroke-width:2;transition:fill 0.2s,stroke 0.2s" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
      </button>
    </form>
  <?php else: ?>
    <a class="hero-fav" href="../shared/login.php" style="text-decoration:none;display:grid;place-items:center">
      <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width:28px;height:28px;overflow:visible"><path style="fill:none;stroke:#8b1a1a;stroke-width:2" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
    </a>
  <?php endif; ?>

  <?php if (!empty($item['photoUrl'])): ?>
    <img class="hero-food-img" src="<?= htmlspecialchars($item['photoUrl']) ?>" alt="<?= htmlspecialchars($item['itemName'] ?? '') ?>">
  <?php else: ?>
    <div class="hero-placeholder">No image available</div>
  <?php endif; ?>
</div>

<!-- Content -->
<main class="container">

  <?php if ($justAdded): ?>
    <div class="flash">✓ Added to cart! <a href="cart.php" style="color:#1a3a6b;font-weight:700;">View cart →</a></div>
  <?php endif; ?>

  <div class="title-row">
    <h1 class="item-title"><?= htmlspecialchars($item['itemName'] ?? 'Item') ?></h1>
    <?php if ($isFree): ?>
      <span class="item-price-big price-free-big">Donation</span>
    <?php else: ?>
      <span class="item-price-big">
        <?= number_format($price, 2) ?>
        <img src="../../images/SAR.png" class="riyal-icon-top">
      </span>
    <?php endif; ?>
  </div>

  <?php if (!empty($item['description'])): ?>
    <p class="item-ingredients"><?= htmlspecialchars($item['description']) ?></p>
  <?php endif; ?>

  <?php if ($expiryStr): ?>
    <div class="expiry-row">
      Expiry date: <span class="expiry-date"><?= htmlspecialchars($expiryStr) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($isLoggedIn && ($item['isAvailable'] ?? false)): ?>
    <form method="post" id="cartForm">
      <input type="hidden" name="action" value="add_to_cart">
      <input type="hidden" name="quantity" id="qtyInput" value="1">
      <?php if (!empty($pickupTimes)): ?>
        <input type="hidden" name="pickupTime" id="selectedTime" value="<?= htmlspecialchars($pickupTimes[0]) ?>">
      <?php endif; ?>

      <div class="divider"></div>
      <p class="section-label">Quantity</p>
      <div class="qty-row">
        <button type="button" class="qty-btn" onclick="changeQty(-1)">−</button>
        <div class="qty-val" id="qtyDisplay">1</div>
        <button type="button" class="qty-btn" onclick="changeQty(1)">+</button>
      </div>

      <?php if (!empty($pickupTimes)): ?>
        <div class="divider"></div>
        <p class="section-label">Pickup time</p>
        <div class="pickup-times" style="margin-bottom:28px">
          <?php foreach ($pickupTimes as $i => $t): ?>
            <div class="time-chip <?= $i === 0 ? 'selected' : '' ?>"
                 onclick="selectTime(this,'<?= htmlspecialchars($t) ?>')">
              <?= htmlspecialchars(fmtPickupTime($t)) ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($location): ?>
        <div class="divider"></div>
        <p class="section-label">Pickup location</p>
        <?php if ($mapLink): ?>
          <a class="map-box" href="<?= htmlspecialchars($mapLink) ?>" target="_blank" rel="noopener">
            <iframe src="https://maps.google.com/maps?q=<?= urlencode((string)$lat) ?>,<?= urlencode((string)$lng) ?>&zoom=15&output=embed" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
          </a>
        <?php else: ?>
          <div class="map-box-placeholder"><div class="map-pin">📍</div></div>
        <?php endif; ?>
        <p class="location-text"><?= htmlspecialchars(trim(($location['street'] ?? '') . ', ' . ($location['city'] ?? ''))) ?></p>
        <div class="detail-add-btn-wrap">
          <button class="detail-add-btn" type="submit" form="cartForm">
            <span class="btn-text"><?= $inCart ? 'Update cart' : 'Add to cart' ?></span>
            <?php if (!$isFree): ?>
              <span class="btn-price">
                <span id="btnTotal"><?= number_format($price, 2) ?></span>
                <img src="../../images/riyal.png" class="riyal-icon">
              </span>
            <?php else: ?>
              <span class="btn-price">Donation</span>
            <?php endif; ?>
          </button>
        </div>
      <?php endif; ?>
    </form>

  <?php elseif ($isLoggedIn && !($item['isAvailable'] ?? false)): ?>
    <div class="unavail">This item is no longer available.</div>
    <?php if (!empty($pickupTimes)): ?>
      <div class="divider"></div>
      <p class="section-label">Pickup time</p>
      <div class="pickup-times" style="margin-bottom:28px;opacity:0.5;pointer-events:none">
        <?php foreach ($pickupTimes as $t): ?><div class="time-chip"><?= htmlspecialchars($t) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <?php if (!empty($pickupTimes)): ?>
      <div class="divider"></div>
      <p class="section-label">Pickup time</p>
      <div class="pickup-times" style="margin-bottom:28px">
        <?php foreach ($pickupTimes as $t): ?><div class="time-chip"><?= htmlspecialchars($t) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($location): ?>
      <div class="divider"></div>
      <p class="section-label">Pickup location</p>
      <?php if ($mapLink): ?>
        <a class="map-box" href="<?= htmlspecialchars($mapLink) ?>" target="_blank" rel="noopener">
          <iframe src="https://maps.google.com/maps?q=<?= urlencode((string)$lat) ?>,<?= urlencode((string)$lng) ?>&zoom=15&output=embed" loading="lazy" allowfullscreen referrerpolicy="no-referrer-when-downgrade"></iframe>
        </a>
      <?php else: ?>
        <div class="map-box-placeholder"><div class="map-pin">📍</div></div>
      <?php endif; ?>
      <p class="location-text"><?= htmlspecialchars(trim(($location['street'] ?? '') . ', ' . ($location['city'] ?? ''))) ?></p>
    <?php endif; ?>
  <?php endif; ?>

</main>
<?php endif; // $item exists ?>

<!-- ══════════════════════════════════════
     FOOTER  (matching landing.php)
══════════════════════════════════════ -->
<footer>
  <div class="footer-top">
    <div style="display:flex;align-items:center;gap:10px;">
      <a class="social-icon" href="#">in</a>
      <a class="social-icon" href="#">&#120143;</a>
      <a class="social-icon" href="#">&#9834;</a>
    </div>
    <div class="footer-divider"></div>
    <img src="../../images/Replate-white.png" alt="Replate" style="height:80px;object-fit:contain;" />
    <div class="footer-divider"></div>
    <div class="footer-email">
      <svg width="16" height="16" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2" viewBox="0 0 24 24">
        <rect x="2" y="4" width="20" height="16" rx="2"/>
        <path d="M2 7l10 7 10-7"/>
      </svg>
      <a href="mailto:Replate@gmail.com" style="color:rgba(255,255,255,0.9);">Replate@gmail.com</a>
    </div>
  </div>
  <div class="footer-bottom">
    <span>©️ 2026</span>
    <img src="../../images/Replate-white.png" alt="Replate" style="height:50px;object-fit:contain;" />
    <span>All rights reserved.</span>
  </div>
</footer>

<script>
  /* ── Quantity control ── */
  const maxQty = <?= (int)$maxQty ?>;
  let qty = 1;
  const price = <?= $isFree ? 0 : $price ?>;

  function changeQty(delta) {
    qty = Math.min(maxQty, Math.max(1, qty + delta));
    const qtyDisplay = document.getElementById('qtyDisplay');
    const qtyInput   = document.getElementById('qtyInput');
    const totalEl    = document.getElementById('btnTotal');
    if (qtyDisplay) qtyDisplay.textContent = qty;
    if (qtyInput)   qtyInput.value = qty;
    if (totalEl && price > 0) totalEl.textContent = (price * qty).toFixed(2);
  }

  function selectTime(el, time) {
    document.querySelectorAll('.time-chip').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    const st = document.getElementById('selectedTime');
    if (st) st.value = time;
  }

  /* ── Bell notification dropdown ── */
  function toggleNotifDropdown() {
    document.getElementById('notifDropdown')?.classList.toggle('open');
  }

  function markRead(el) {
    if (!el.classList.contains('unread')) return;
    const notifId = el.dataset.id;
    el.classList.remove('unread');
    const dot = el.querySelector('.notif-unread-dot');
    if (dot) dot.remove();
    updateBellBadge(-1);
    fetch('item-details.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ action: 'mark_read', notifId })
    }).catch(() => {});
  }

  function markAllRead() {
    document.querySelectorAll('#notifDropdown .notif-item.unread').forEach(el => {
      el.classList.remove('unread');
      const dot = el.querySelector('.notif-unread-dot');
      if (dot) dot.remove();
    });
    const badge = document.getElementById('bellBadge');
    if (badge) badge.style.display = 'none';
    const btn = document.querySelector('.notif-mark-all');
    if (btn) btn.style.display = 'none';
    fetch('item-details.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ action: 'mark_all_read' })
    }).catch(() => {});
  }

  function updateBellBadge(delta) {
    const badge = document.getElementById('bellBadge');
    if (!badge) return;
    const next = Math.max(0, (parseInt(badge.textContent) || 0) + delta);
    if (next === 0) badge.style.display = 'none';
    else { badge.textContent = next; badge.style.display = 'flex'; }
  }

  /* ── Outside-click closes dropdowns ── */
  document.addEventListener('click', e => {
    const searchWrap = document.getElementById('searchWrap');
    if (searchWrap && !searchWrap.contains(e.target)) closeSearch();
    const bellWrap = document.querySelector('.nav-bell-wrap');
    if (bellWrap && !bellWrap.contains(e.target)) {
      document.getElementById('notifDropdown')?.classList.remove('open');
    }
  });

  /* ── Live Search ── */
  const searchInput    = document.getElementById('searchInput');
  const searchDropdown = document.getElementById('searchDropdown');
  let searchTimer = null;

  searchInput?.addEventListener('input', function () {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (q.length < 2) { closeSearch(); return; }
    searchDropdown.innerHTML = '<div class="search-loading">Searching...</div>';
    searchDropdown.classList.add('open');
    searchTimer = setTimeout(() => doSearch(q), 280);
  });
  searchInput?.addEventListener('keydown', e => { if (e.key === 'Escape') closeSearch(); });

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
        html += `<a class="search-item-row" href="../customer/providers-page.php?providerId=${p.id}">${logo}<div><p class="search-item-name">${hl(p.businessName,q)}</p><p class="search-item-sub">${p.category}</p></div></a>`;
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
        html += `<a class="search-item-row" href="../customer/item-details.php?itemId=${item.id}">${thumb}<div><p class="search-item-name">${hl(item.name,q)}</p><p class="search-item-sub">Product</p></div><span class="search-price">${item.price}</span></a>`;
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

  function toggleMobileMenu(){
    const menu = document.getElementById('mobileMenu');
    const btn  = document.getElementById('hamburger');
    menu.classList.toggle('open');
    btn.classList.toggle('open');
    document.body.style.overflow = menu.classList.contains('open') ? 'hidden' : '';
  }
  function closeMobileMenu(){
    document.getElementById('mobileMenu')?.classList.remove('open');
    document.getElementById('hamburger')?.classList.remove('open');
    document.body.style.overflow = '';
  }
  document.getElementById('mobileSearchInput')?.addEventListener('input', function(){
    const q = this.value.trim();
    const dd = document.getElementById('mobileSearchDropdown');
    if(!dd) return;
    if(q.length < 2){ dd.classList.remove('open'); dd.innerHTML = ''; return; }
    dd.innerHTML = '<div style="padding:14px;text-align:center;color:#b0c4d8;font-size:13px;">Searching...</div>';
    dd.classList.add('open');
    clearTimeout(window._mobTimer);
    window._mobTimer = setTimeout(() => {
      fetch('../../back-end/search.php?q=' + encodeURIComponent(q))
        .then(r => r.json())
        .then(data => {
          const items = data.items || [], providers = data.providers || [];
          if(!items.length && !providers.length){
            dd.innerHTML = '<div style="padding:14px;text-align:center;color:#b0c4d8;font-size:13px;">No matches found</div>';
            dd.classList.add('open'); return;
          }
          let html = '';
          if(providers.length){
            html += '<div class="search-section-label">Providers</div>';
            providers.forEach(p => {
              const logo = p.businessLogo
                ? `<div class="search-provider-logo"><img src="${p.businessLogo}"/></div>`
                : `<div class="search-provider-logo">${p.businessName.charAt(0)}</div>`;
              html += `<a class="search-item-row" href="../customer/providers-page.php?providerId=${p.id}" onclick="closeMobileMenu()">${logo}<div><p class="search-item-name">${p.businessName}</p><p class="search-item-sub">${p.category||''}</p></div></a>`;
            });
          }
          if(items.length){
            if(providers.length) html += '<div class="search-divider"></div>';
            html += '<div class="search-section-label">Products</div>';
            items.forEach(item => {
              const t = item.photoUrl
                ? `<div class="search-thumb"><img src="${item.photoUrl}"/></div>`
                : '<div class="search-thumb">&#127837;</div>';
              html += `<a class="search-item-row" href="../customer/item-details.php?itemId=${item.id}" onclick="closeMobileMenu()">${t}<div><p class="search-item-name">${item.name}</p></div><span class="search-price">${item.price||''}</span></a>`;
            });
          }
          dd.innerHTML = html; dd.classList.add('open');
        })
        .catch(() => {
          dd.innerHTML = '<div style="padding:14px;text-align:center;color:#b0c4d8;font-size:13px;">Search unavailable</div>';
          dd.classList.add('open');
        });
    }, 220);
  });
</script>
</body>
</html>