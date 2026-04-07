<?php
// ================================================================
// category.php — Items by Category
// ================================================================
// URL PARAMS:  ?categoryId=xxx  &type=all|donate|sell
// VARIABLES:
//   $category   → current category object { _id, name, icon }
//   $items      → array of items in this category
//   $type       → current filter: 'all' | 'donate' | 'sell'
//   $categories → all categories (for sidebar/tabs)
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Category.php';
require_once '../../back-end/models/Item.php';

$categoryModel = new Category();
$itemModel     = new Item();

$categoryId = $_GET['categoryId'] ?? '';
$type       = $_GET['type']       ?? 'all';

$category   = $categoryId ? $categoryModel->findById($categoryId) : null;
$categories = $categoryModel->getAll();

// Build a category lookup map: id => name (for per-item category tag)
$catMap = [];
foreach ($categories as $c) {
    $catMap[(string)$c['_id']] = $c['name'] ?? '';
}

$items = [];
if ($categoryId) {
    $items = $itemModel->getByCategory($categoryId);
    if (empty($items)) {
        try {
            $items = $itemModel->findAll(['categoryId' => new MongoDB\BSON\ObjectId($categoryId)]);
        } catch (Throwable) {}
    }
} else {
    try {
        $items = $itemModel->findAll([]);
    } catch (Throwable) {}
}
// ── Filter out unavailable and expired items ──
$now = time();
$items = array_values(array_filter($items, function($i) use ($now) {
    if (empty($i['isAvailable'])) return false;
    if ((int)($i['quantity'] ?? 0) <= 0) return false;
    if (!empty($i['expiryDate'])) {
        try {
            $exp = $i['expiryDate'] instanceof MongoDB\BSON\UTCDateTime
                ? $i['expiryDate']->toDateTime()->getTimestamp()
                : strtotime((string)$i['expiryDate']);
            if ($exp < $now) return false;
        } catch (Throwable) {}
    }
    return true;
}));
// Apply listing type filter
if ($type !== 'all') {
    $items = array_values(array_filter($items, fn($i) => ($i['listingType'] ?? '') === $type));
}

require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Favourite.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Notification.php';

$providerModel = new Provider();

$isLoggedIn = !empty($_SESSION['customerId']);
$customerId = $_SESSION['customerId'] ?? null;

// ── Handle favourite toggle (POST form fallback) ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_fav' && $isLoggedIn) {
    $toggleItemId = trim($_POST['itemId'] ?? '');
    if ($toggleItemId) {
        $favModel = new Favourite();
        if ($favModel->isSaved($customerId, $toggleItemId)) {
            $favModel->remove($customerId, $toggleItemId);
        } else {
            $favModel->add($customerId, $toggleItemId);
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// ── AJAX handlers ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
    header('Content-Type: application/json');
    if (empty($_SESSION['customerId'])) {
        echo json_encode(['success' => false, 'error' => 'unauthenticated']); exit;
    }
    $input      = json_decode(file_get_contents('php://input'), true);
    $ajaxAction = $input['action'] ?? 'toggle_fav';
    $cid        = $_SESSION['customerId'];

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

$savedIds      = [];
$cartCount     = 0;
$notifications = [];
$unreadCount   = 0;

if ($isLoggedIn) {
    // Cart count
    $cartModel = new Cart();
    $cart      = $cartModel->getOrCreate($customerId);
    $cartItems = (array)($cart['cartItems'] ?? []);
    $cartCount = array_sum(array_map(fn($ci) => (int)($ci['quantity'] ?? 1), $cartItems));
    $cartItemIds = array_map(fn($ci) => (string)$ci['itemId'], $cartItems);

    // Favourites
    $favs     = (new Favourite())->getByCustomer($customerId);
    $savedIds = array_map(fn($f) => (string)$f['itemId'], $favs);
    $favItemIds = $savedIds;

    // Write expiry_alert notifications (same logic as landing.php)
    $notifModel = new Notification();
    $watchedIds = array_unique(array_merge($cartItemIds, $favItemIds));
    $itemModel2 = new Item();
    foreach ($watchedIds as $wid) {
        try {
            $witem = $itemModel2->findById($wid);
            if (!$witem || !isset($witem['expiryDate'])) continue;
            $expiry = $witem['expiryDate']->toDateTime()->getTimestamp();
            if ($expiry < $now) continue;
            $daysLeft = ($expiry - $now) / 86400;
            if ($daysLeft < 3)     { $urgency = 'red'; }
            elseif ($daysLeft < 7) { $urgency = 'orange'; }
            else                   { $urgency = 'yellow'; }
            $hoursLeft = (int)ceil($expiry - $now) / 3600;
            $timeStr   = $daysLeft < 2 ? ceil($hoursLeft) . 'h' : ceil($daysLeft) . ' days';
            $inCart = in_array($wid, $cartItemIds, true);
            $inFav  = in_array($wid, $favItemIds, true);
            if ($inCart && $inFav)  { $lbl = 'Cart & Favourites'; }
            elseif ($inCart)        { $lbl = 'Cart'; }
            else                    { $lbl = 'Favourites'; }
            $recentAlert = $notifModel->findAll([
                'customerId'    => Notification::toObjectId($customerId),
                'type'          => 'expiry_alert',
                'relatedItemId' => Notification::toObjectId($wid),
                'createdAt'     => ['$gte' => new MongoDB\BSON\UTCDateTime(($now - 86400) * 1000)],
            ], ['sort' => ['createdAt' => -1], 'limit' => 1]);
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

    // Read all notifications
    $notifications = (array)$notifModel->getByCustomer($customerId);
    $unreadCount   = $notifModel->getUnreadCount($customerId);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RePlate – <?= htmlspecialchars($category['name'] ?? 'Category') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'DM Sans',sans-serif;background:#f0f5fc;color:#1a2a45;min-height:100vh}
    a{text-decoration:none;color:inherit}

    /* ══════════════════════════════════════
       NAVBAR — identical to landing.php
    ══════════════════════════════════════ */
    nav{display:flex;align-items:center;justify-content:space-between;padding:0 48px;height:72px;background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(26,58,107,0.18)}
    .nav-left{display:flex;align-items:center;gap:16px}
    .nav-logo{height:100px}
    .nav-cart-wrap{position:relative;display:flex;align-items:center}
    .nav-cart{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,0.7);display:flex;justify-content:center;align-items:center;cursor:pointer;transition:background 0.2s;text-decoration:none}
    .nav-cart:hover{background:rgba(255,255,255,0.15)}
    .cart-badge{position:absolute;top:-5px;right:-5px;min-width:19px;height:19px;background:#e53935;border-radius:50%;border:2px solid #2255a4;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;font-family:'DM Sans',sans-serif;pointer-events:none;animation:cartPop 0.4s cubic-bezier(0.175,0.885,0.32,1.275)}
    @keyframes cartPop{0%{transform:scale(0);opacity:0}70%{transform:scale(1.25);opacity:1}100%{transform:scale(1);opacity:1}}
    .nav-center{display:flex;align-items:center;gap:40px}
    .nav-center a{color:rgba(255,255,255,0.85);text-decoration:none;font-weight:500;font-size:15px;transition:color 0.2s}
    .nav-center a:hover{color:#fff}
    .nav-center a.active{color:#fff;font-weight:600;border-bottom:2px solid #fff;padding-bottom:2px}
    .nav-right{display:flex;align-items:center;gap:12px}
    .nav-search-wrap{position:relative}
    .search-dropdown{display:none;position:absolute;top:calc(100% + 10px);right:0;width:380px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(26,58,107,0.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden}
    .search-dropdown.open{display:block}
    .search-section-label{font-size:11px;font-weight:700;color:#b0c4d8;letter-spacing:0.08em;text-transform:uppercase;padding:12px 16px 6px}
    .search-item-row{display:flex;align-items:center;gap:12px;padding:10px 16px;cursor:pointer;transition:background 0.15s;text-decoration:none}
    .search-item-row:hover{background:#f0f6ff}
    .search-thumb{width:38px;height:38px;border-radius:10px;background:#e0eaf5;flex-shrink:0;object-fit:cover;display:flex;align-items:center;justify-content:center;font-size:18px}
    .search-thumb img{width:100%;height:100%;object-fit:cover;border-radius:10px}
    .search-item-name{font-size:14px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif}
    .search-item-sub{font-size:12px;color:#7a8fa8}
    .search-price{margin-left:auto;font-size:13px;font-weight:700;color:#e07a1a;white-space:nowrap}
    .search-divider{height:1px;background:#f0f5fc;margin:4px 0}
    .search-empty{padding:24px 16px;text-align:center;color:#b0c4d8;font-size:14px;font-family:'Playfair Display',serif}
    .search-loading{padding:18px 16px;text-align:center;color:#b0c4d8;font-size:13px}
    .search-no-match{padding:8px 16px 12px;font-size:13px;color:#b0c4d8;font-style:italic}
    .search-provider-logo{width:38px;height:38px;border-radius:50%;background:#e0eaf5;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:#2255a4}
    .search-provider-logo img{width:100%;height:100%;object-fit:cover}
    .nav-search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:0.6;pointer-events:none}
    .nav-search-wrap input{background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.4);border-radius:50px;padding:9px 16px 9px 36px;color:#fff;font-size:14px;outline:none;width:240px;font-family:'Playfair Display',serif;transition:width 0.3s,background 0.2s}
    .nav-search-wrap input::placeholder{color:rgba(255,255,255,0.6)}
    .nav-search-wrap input:focus{width:300px;background:rgba(255,255,255,0.25)}
    .nav-avatar{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,0.6);display:flex;align-items:center;justify-content:center;cursor:pointer}
    .btn-signup{background:#fff;color:#1a3a6b;border:none;border-radius:50px;padding:9px 22px;font-weight:700;font-size:14px;font-family:'Playfair Display',serif;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,0.1);transition:transform 0.15s,box-shadow 0.15s;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
    .btn-signup:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(0,0,0,0.15)}
    .btn-login{background:transparent;color:#fff;border:2px solid #fff;border-radius:50px;padding:8px 22px;font-weight:700;font-size:14px;font-family:'Playfair Display',serif;cursor:pointer;transition:background 0.2s;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}
    .btn-login:hover{background:rgba(255,255,255,0.15)}

    /* Bell & Notifications — identical to landing.php */
    .nav-bell-wrap{position:relative}
    .nav-bell{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,0.6);display:flex;align-items:center;justify-content:center;cursor:pointer;background:none;transition:background 0.2s}
    .nav-bell:hover{background:rgba(255,255,255,0.15)}
    .bell-badge{position:absolute;top:-3px;right:-3px;min-width:18px;height:18px;background:#e53935;border-radius:50%;border:2px solid #2255a4;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none;font-family:'DM Sans',sans-serif;animation:cartPop 0.4s cubic-bezier(0.175,0.885,0.32,1.275)}
    .notif-dropdown{display:none;position:absolute;top:50px;right:0;width:360px;background:#fff;border-radius:20px;box-shadow:0 12px 48px rgba(26,58,107,0.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden}
    .notif-dropdown.open{display:block;animation:floatUp 0.2s ease}
    @keyframes floatUp{from{opacity:0;transform:translateY(30px)}to{opacity:1;transform:translateY(0)}}
    .notif-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1.5px solid #f0f5fc;background:#fff}
    .notif-header-title{font-size:15px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif}
    .notif-mark-all{font-size:12px;color:#2255a4;background:none;border:none;cursor:pointer;font-family:'Playfair Display',serif;font-weight:600;padding:0;transition:color 0.2s}
    .notif-mark-all:hover{color:#1a3a6b}
    .notif-list{max-height:420px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#c8d8ee transparent}
    .notif-empty{padding:36px 18px;text-align:center;color:#b0c4d8;font-size:14px}
    .notif-item{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-bottom:1px solid #f5f8fc;transition:background 0.15s;cursor:pointer;position:relative}
    .notif-item:last-child{border-bottom:none}
    .notif-item:hover{background:#f8fbff}
    .notif-item.unread{background:#fffaf5;border-left:3px solid #e07a1a}
    .notif-item.unread:hover{background:#fff4e8}
    .notif-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
    .notif-icon.expiry{background:#fff4e6}
    .notif-icon.expiry-red{background:#fde8e8}
    .notif-icon.expiry-orange{background:#fff0e0}
    .notif-icon.expiry-yellow{background:#fffbe6}
    .notif-icon.order{background:#e8f7ee}
    .notif-icon.pickup{background:#e8f0ff}
    .notif-icon.default{background:#f2f4f8}
    .notif-item.urgency-red{border-left:3px solid #c0392b;background:#fff8f8}
    .notif-item.urgency-orange{border-left:3px solid #e07a1a;background:#fffaf5}
    .notif-item.urgency-yellow{border-left:3px solid #d4ac0d;background:#fffef0}
    .notif-body{flex:1;min-width:0}
    .notif-msg{font-size:13px;font-weight:600;color:#1a3a6b;font-family:'Playfair Display',serif;margin-bottom:4px;line-height:1.4}
    .notif-item.unread .notif-msg{font-weight:700}
    .notif-time{font-size:11px;color:#b0c4d8}
    .notif-unread-dot{width:8px;height:8px;background:#e07a1a;border-radius:50%;flex-shrink:0;margin-top:6px}
    .notif-footer{padding:12px 18px;border-top:1.5px solid #f0f5fc;text-align:center}
    .notif-footer a{font-size:13px;color:#2255a4;text-decoration:none;font-weight:600;font-family:'Playfair Display',serif}
    .notif-footer a:hover{color:#1a3a6b}

    /* Hamburger — identical to landing.php */
    .hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer;background:none;border:none;padding:6px}
    .hamburger span{display:block;width:24px;height:2.5px;background:#fff;border-radius:2px;transition:all 0.3s}
    .hamburger.open span:nth-child(1){transform:translateY(7.5px) rotate(45deg)}
    .hamburger.open span:nth-child(2){opacity:0}
    .hamburger.open span:nth-child(3){transform:translateY(-7.5px) rotate(-45deg)}
    .mobile-menu{display:none;position:fixed;inset:0;top:72px;background:linear-gradient(180deg,#1a3a6b 0%,#2255a4 100%);z-index:99;flex-direction:column;padding:32px 28px;gap:0}
    .mobile-menu.open{display:flex}
    .mobile-menu a{color:rgba(255,255,255,0.85);font-size:22px;font-weight:700;font-family:'Playfair Display',serif;padding:18px 0;border-bottom:1px solid rgba(255,255,255,0.12);text-decoration:none}
    .mobile-menu a:hover{color:#fff}
    .mobile-search{margin-top:24px;position:relative}
    .mobile-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);opacity:0.6;pointer-events:none}
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

    /* ── BACK BUTTON ── */
    .back-btn{width:46px;height:46px;border-radius:50%;background:#cdd9e8;color:#1b3f92;display:flex;align-items:center;justify-content:center;font-size:28px;line-height:1;flex-shrink:0;font-weight:700;text-decoration:none;transition:background 0.2s;border:none;cursor:pointer;font-family:'Playfair Display',serif}
    .back-btn:hover{background:#bfcee2}

    /* ── LAYOUT ── */
    .page-wrap{max-width:1200px;margin:32px auto;padding:0 24px;display:grid;grid-template-columns:220px 1fr;gap:28px}
    @media(max-width:860px){.page-wrap{grid-template-columns:1fr}}

    /* ── SIDEBAR ── */
    .cat-sidebar{background:#fff;border:1.5px solid #dce7f5;border-radius:18px;padding:20px 16px;height:fit-content;position:sticky;top:88px;box-shadow:0 4px 14px rgba(26,58,107,0.07)}
    .cat-sidebar h3{font-family:'Playfair Display',serif;font-size:20px;color:#1a3a6b;margin-bottom:14px;padding-bottom:12px;border-bottom:2px solid #e8f0ff;letter-spacing:-0.3px}
    .cat-link{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:14px;color:#3a5070;font-weight:500;font-size:14px;transition:all 0.18s;margin-bottom:6px;border:1.5px solid #eef2f8;text-decoration:none;background:#fafbff}
    .cat-link:hover{background:#eef4ff;color:#1a3a6b;border-color:#c8d8f0;transform:translateX(2px)}
    .cat-link.active{background:#1a3a6b;color:#fff;font-weight:700;border-color:#1a3a6b}
    .cat-img-box{width:42px;height:42px;border-radius:10px;background:#e8f0ff;overflow:hidden;display:flex;align-items:center;justify-content:center;flex-shrink:0}
    .cat-img-box img{width:100%;height:100%;object-fit:cover}
    .cat-link.active .cat-img-box{background:rgba(255,255,255,0.2)}
    .cat-name{font-size:14px;font-weight:600}

    /* ── FILTER TABS ── */
    .filter-bar{display:flex;gap:12px;margin-bottom:22px}
    .filter-tab{padding:9px 28px;border-radius:50px;border:2px solid #e07a1a;background:transparent;color:#e07a1a;font-weight:700;font-size:15px;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all 0.2s;text-decoration:none;display:inline-block}
    .filter-tab.active,.filter-tab:hover{background:#e07a1a;color:#fff}

    /* ── ITEMS GRID ── */
    .items-grid{display:grid;grid-template-columns:repeat(auto-fill,260px);gap:20px;margin-bottom:48px;justify-content:start}
    @media(max-width:600px){.items-grid{grid-template-columns:1fr}}

    /* ── ITEM CARD ── */
    .item-card{min-width:260px;max-width:260px;background:#f2f4f8;border-radius:24px;border:1.5px solid #c8d8ee;padding:18px 18px 20px;display:flex;flex-direction:column;gap:0;box-shadow:0 2px 14px rgba(26,58,107,0.07);transition:box-shadow 0.2s,transform 0.2s}
    .item-card:hover{box-shadow:0 8px 28px rgba(26,58,107,0.13);transform:translateY(-3px)}
    .card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
    .prov-logo-box{display:flex;align-items:center;gap:8px}
    .prov-logo-circle{width:32px;height:32px;background:#c8d8ee;border-radius:50%;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center}
    .prov-logo-circle img{width:100%;height:100%;object-fit:cover}
    .prov-logo-name{font-size:15px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif}
    .fav-btn{background:none;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;transition:transform 0.2s}
    .fav-btn:hover{transform:scale(1.15)}
    .fav-btn svg{width:28px;height:28px;overflow:visible}
    .fav-btn .heart-path{fill:none;stroke:#8b1a1a;stroke-width:2;transition:fill 0.2s,stroke 0.2s}
    .fav-btn.liked .heart-path{fill:#c0392b;stroke:#c0392b}
    .cat-tag{display:inline-block;background:#e8f0ff;color:#2255a4;font-size:11px;font-weight:700;font-family:'DM Sans',sans-serif;border-radius:50px;padding:3px 10px;margin-bottom:6px;letter-spacing:0.04em;text-transform:uppercase}
    .item-img-box{width:100%;height:130px;background:#d8e6f5;border-radius:14px;margin-bottom:16px;overflow:hidden;display:flex;align-items:center;justify-content:center}
    .item-img-box img{width:100%;height:100%;object-fit:cover;border-radius:14px}
    .item-img-ph-text{font-size:13px;color:#8aa3c0}
    .card-divider{width:100%;height:1.5px;background:#c0d2e8;margin-bottom:14px}
    .card-body{display:flex;flex-direction:column;gap:8px}
    .name-row{display:flex;align-items:center;justify-content:space-between;gap:8px}
    .item-name{font-size:18px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif}
    .price-row{display:flex;align-items:center;gap:5px}
    .item-price{font-size:16px;font-weight:700;color:#e07a1a}
    .price-free{color:#1a6b3a}
    .sar-box{width:22px;height:22px;background:#c8d8ee;border-radius:4px;flex-shrink:0}
    .item-desc{font-size:13px;color:#4a6a9a;line-height:1.5;font-family:'Playfair Display',serif}
    .view-btn{background:#1a3a6b;color:#fff;border:none;border-radius:50px;padding:12px 0;font-size:15px;font-family:'Playfair Display',serif;cursor:pointer;font-weight:700;width:80%;text-align:center;margin:8px auto 0;display:block;transition:background 0.2s;text-decoration:none}
    .view-btn:hover{background:#2255a4}

    /* ── EMPTY ── */
    .empty-state{grid-column:1/-1;text-align:center;padding:60px 20px;color:#7a8fa8}
    .empty-state h3{font-family:'Playfair Display',serif;font-size:26px;color:#1a3a6b;margin-bottom:10px}

    /* ══════════════════════════════════════
       FOOTER — identical to landing.php
    ══════════════════════════════════════ */
    footer{background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);padding:28px 48px;display:flex;flex-direction:column;align-items:center;gap:14px}
    .footer-top{display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:center}
    .social-icon{width:42px;height:42px;border-radius:50%;border:1.5px solid rgba(255,255,255,0.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;font-family:'Playfair Display',serif;transition:background 0.2s}
    .social-icon:hover{background:rgba(255,255,255,0.15)}
    .footer-divider{width:1px;height:22px;background:rgba(255,255,255,0.3)}
    .footer-brand{display:flex;align-items:center;gap:8px;color:#fff;font-size:16px;font-weight:700;font-family:'Playfair Display',serif}
    .footer-email{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,0.9);font-size:14px;font-family:'Playfair Display',serif}
    .footer-bottom{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,0.7);font-size:13px;font-family:'Playfair Display',serif}

    /* ── MOBILE ── */
    @media(max-width:768px){
      nav{padding:0 18px}
      .nav-logo{height:72px}
      .nav-center{display:none}
      .nav-search-wrap{display:none}
      .hamburger{display:flex}
      footer{padding:24px 16px}
      .footer-bottom{font-size:11px}
      .btn-signup,.btn-login{padding:6px 14px;font-size:13px;border-radius:30px}

      /* ── Category sidebar → horizontal swipe row ── */
      .page-wrap{grid-template-columns:1fr!important;gap:16px;margin:16px auto;padding:0 14px;width:100%;max-width:100%}
      .cat-sidebar{
        position:sticky !important;
        top:72px;
        z-index:50;
        padding:10px 0;
        background:#e8eef5;
        border:none;
        box-shadow:0 2px 8px rgba(26,58,107,0.08);
        border-radius:0;
        overflow:hidden;
        width:100%;
      }
      .cat-sidebar h3{font-size:15px;margin-bottom:10px;padding:0 14px 8px;border-bottom:none}
      .cat-links-row{
        display:flex;
        flex-direction:row;
        gap:8px;
        overflow-x:auto;
        overflow-y:visible;
        padding:4px 14px 8px;
        -ms-overflow-style:none;
        scrollbar-width:none;
        cursor:grab;
        scroll-behavior:smooth;
        -webkit-overflow-scrolling:touch;
        width:100%;
        box-sizing:border-box;
      }
      .cat-links-row::-webkit-scrollbar{display:none}
      .cat-links-row.dragging{cursor:grabbing;user-select:none}
      .cat-link{
        display:inline-flex !important;
        flex-shrink:0 !important;
        margin-bottom:0 !important;
        padding:8px 14px !important;
        border-radius:50px !important;
        gap:8px !important;
        font-size:13px !important;
        white-space:nowrap !important;
      }
      .cat-link:hover{transform:none !important}
      .cat-img-box{width:26px;height:26px;border-radius:50%;flex-shrink:0}
      .cat-img-box img{width:100%;height:100%;object-fit:cover;border-radius:50%}
      .items-grid{grid-template-columns:repeat(auto-fill,minmax(150px,1fr))!important;gap:12px!important}
      .item-card{min-width:0!important;max-width:100%!important;width:100%!important}
    }
  </style>
</head>
<body>

<!-- ══════════════════════════════════════
     NAVBAR — identical to landing.php
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
    <a href="category.php" class="active">Categories</a>
    <a href="providers-list.php">Providers</a>
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
            $nmsg  = $notif['message'] ?? '';
            $nread = (bool)($notif['isRead'] ?? false);
            $ntime = '';
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
            $iconClass = 'default'; $iconSvg = '';
            if ($ntype === 'expiry_alert') {
                $rawM_ = $notif['message'] ?? '';
                $urg_  = str_contains($rawM_, '[red]') ? 'red' : (str_contains($rawM_, '[orange]') ? 'orange' : 'yellow');
                $urgC_ = $urg_==='red' ? '#c0392b' : ($urg_==='orange' ? '#e07a1a' : '#d4ac0d');
                $iconClass = 'expiry-' . $urg_;
                $iconSvg = '<svg width="16" height="16" fill="none" stroke="'.$urgC_.'" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
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
            $urgClass_ = $ntype==='expiry_alert' ? ' urgency-'.($urg_??'yellow') : '';
          ?>
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
      <span></span><span></span><span></span>
    </button>
    <?php else: ?>
    <button class="nav-avatar" onclick="document.getElementById('authModal').style.display='flex'" style="border:none;cursor:pointer;background:rgba(255,255,255,0.15);">
      <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
    </button>
    <button id="hamburger" class="hamburger" onclick="toggleMobileMenu()" aria-label="Open menu">
      <span></span><span></span><span></span>
    </button>
    <?php endif; ?>

    <!-- Auth modal -->
    <div id="authModal" style="display:none;position:fixed;inset:0;background:rgba(12,22,45,0.5);z-index:9999;justify-content:center;align-items:center;" onclick="if(event.target===this)this.style.display='none'">
      <div style="background:#fff;border-radius:24px;padding:44px 40px;max-width:400px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.2);animation:floatUp 0.3s ease;">
        <div style="width:64px;height:64px;background:#e8f0ff;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
          <svg width="28" height="28" fill="none" stroke="#2255a4" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <h3 style="font-size:22px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;margin-bottom:10px;">Sign in to continue</h3>
        <p style="font-size:14px;color:#7a8fa8;margin-bottom:28px;line-height:1.6;">Please log in or create an account.</p>
        <div style="display:flex;gap:12px;justify-content:center;">
          <a href="../shared/login.php" style="flex:1;padding:13px 0;border-radius:50px;background:#1a3a6b;color:#fff;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;text-decoration:none;display:flex;align-items:center;justify-content:center;">Log in</a>
          <a href="../shared/signup-customer.php" style="flex:1;padding:13px 0;border-radius:50px;background:transparent;color:#1a3a6b;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;text-decoration:none;border:2px solid #1a3a6b;display:flex;align-items:center;justify-content:center;">Sign up</a>
        </div>
        <button onclick="document.getElementById('authModal').style.display='none'" style="margin-top:18px;background:none;border:none;color:#b0c4d8;font-size:13px;cursor:pointer;font-family:'Playfair Display',serif;">Maybe later</button>
      </div>
    </div>
  </div>
</nav>

<!-- Mobile menu -->
<div class="mobile-menu" id="mobileMenu">
  <div class="mobile-search">
    <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/>
    </svg>
    <input type="text" id="mobileSearchInput" placeholder="Search products or providers..." autocomplete="off"/>
  </div>
  <div id="mobileSearchDropdown" class="mobile-search-dropdown"></div>
  <a href="../shared/landing.php" onclick="closeMobileMenu()">Home Page</a>
  <a href="category.php" onclick="closeMobileMenu()">Categories</a>
  <a href="providers-list.php" onclick="closeMobileMenu()">Providers</a>
  <?php if (!$isLoggedIn): ?>
  <a href="../shared/login.php" onclick="closeMobileMenu()">Log in</a>
  <a href="../shared/signup-customer.php" onclick="closeMobileMenu()">Sign up</a>
  <?php endif; ?>
</div>

<!-- ── PAGE LAYOUT ── -->
<div class="page-wrap">
  <div style="display:flex;align-items:center;gap:20px;margin-bottom:24px;grid-column:1/-1">
    <a class="back-btn" href="javascript:history.back()">‹</a>
    <h2 style="font-family:'Playfair Display',serif;font-size:32px;color:#183482;margin:0;font-weight:700"><?= htmlspecialchars($category['name'] ?? 'All Categories') ?></h2>
  </div>

  <!-- SIDEBAR -->
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
  function getCatImage(string $name, array $map): string {
      $key = strtolower(trim($name));
      return $map[$key] ?? '../../images/bakary.png';
  }
  ?>
  <aside class="cat-sidebar">
    <h3>Categories</h3>
    <div class="cat-links-row">
      <a class="cat-link <?= !$categoryId ? 'active' : '' ?>" href="category.php">
        <div class="cat-img-box"><img src="../../images/All.png" alt="All"></div>
        <span class="cat-name">All</span>
      </a>
      <?php foreach ($categories as $cat):
        $cName = $cat['name'] ?? '';
        $cImg  = getCatImage($cName, $catImageMap);
      ?>
        <a class="cat-link <?= (string)$cat['_id'] === $categoryId ? 'active' : '' ?>"
           href="category.php?categoryId=<?= urlencode((string)$cat['_id']) ?>&type=<?= htmlspecialchars($type) ?>">
          <div class="cat-img-box"><img src="<?= htmlspecialchars($cImg) ?>" alt="<?= htmlspecialchars($cName) ?>"></div>
          <span class="cat-name"><?= htmlspecialchars($cName) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </aside>

  <!-- MAIN -->
  <main>
    <!-- Filter tabs -->
    <div class="filter-bar">
      <a class="filter-tab <?= $type==='all'    ? 'active':'' ?>" href="?<?= $categoryId ? 'categoryId='.urlencode($categoryId).'&' : '' ?>type=all">All</a>
      <a class="filter-tab <?= $type==='donate' ? 'active':'' ?>" href="?<?= $categoryId ? 'categoryId='.urlencode($categoryId).'&' : '' ?>type=donate">Donation</a>
      <a class="filter-tab <?= $type==='sell'   ? 'active':'' ?>" href="?<?= $categoryId ? 'categoryId='.urlencode($categoryId).'&' : '' ?>type=sell">Buying</a>
    </div>

    <!-- Items grid -->
    <div class="items-grid">
      <?php if (empty($items)): ?>
        <div class="empty-state">
          <h3>No items found</h3>
          <p><?= $categoryId ? 'No items available in this category.' : 'No items available yet.' ?><?= $type !== 'all' ? ' Try switching to "All".' : '' ?></p>
        </div>
      <?php else: ?>
        <?php foreach ($items as $item):
          $itemId   = (string)$item['_id'];
          $isFree   = ($item['listingType'] ?? '') === 'donate';
          $isSaved  = in_array($itemId, $savedIds, true);
          $prov     = !empty($item['providerId']) ? $providerModel->findById((string)$item['providerId']) : null;
          $provName = $prov['businessName'] ?? '';
          $provLogo = $prov['businessLogo'] ?? '';
          $itemCatId = (string)($item['categoryId'] ?? '');
          if (isset($catMap[$itemCatId])) {
              $catName = $catMap[$itemCatId];
          } elseif ($itemCatId) {
              try {
                  $fetchedCat = $categoryModel->findById($itemCatId);
                  $catName = $fetchedCat['name'] ?? '';
                  $catMap[$itemCatId] = $catName;
              } catch (Throwable) { $catName = ''; }
          } else {
              $catName = '';
          }
        ?>
        <div class="item-card">
          <div class="card-top">
            <div class="prov-logo-box">
              <div class="prov-logo-circle">
                <?php if ($provLogo): ?>
                  <img src="<?= htmlspecialchars($provLogo) ?>" alt="<?= htmlspecialchars($provName) ?>">
                <?php else: ?>
                  <span style="font-size:12px;font-weight:700;color:#2255a4;"><?= htmlspecialchars(mb_strtoupper(mb_substr($provName,0,1))) ?></span>
                <?php endif; ?>
              </div>
              <span class="prov-logo-name"><?= htmlspecialchars($provName) ?></span>
            </div>
            <?php if ($isLoggedIn): ?>
              <button class="fav-btn <?= $isSaved ? 'liked' : '' ?>" data-item-id="<?= htmlspecialchars($itemId) ?>" type="button">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path class="heart-path" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
              </button>
            <?php else: ?>
              <button class="fav-btn" type="button" onclick="document.getElementById('authModal').style.display='flex'">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path class="heart-path" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
              </button>
            <?php endif; ?>
          </div>

          <?php if ($catName): ?>
            <span class="cat-tag"><?= htmlspecialchars($catName) ?></span>
          <?php endif; ?>

          <div class="item-img-box">
            <?php if (!empty($item['photoUrl'])): ?>
              <img src="<?= htmlspecialchars($item['photoUrl']) ?>" alt="<?= htmlspecialchars($item['itemName'] ?? '') ?>">
            <?php else: ?>
              <span class="item-img-ph-text">No image</span>
            <?php endif; ?>
          </div>

          <div class="card-divider"></div>

          <div class="card-body">
            <div class="name-row">
              <span class="item-name"><?= htmlspecialchars($item['itemName'] ?? 'Item') ?></span>
              <div class="price-row">
                <?php if ($isFree): ?>
                  <span class="item-price price-free">Free</span>
                <?php else: ?>
                  <span class="item-price"><?= number_format((float)($item['price'] ?? 0), 2) ?></span>
                  <div class="sar-box"></div>
                <?php endif; ?>
              </div>
            </div>
            <p class="item-desc"><?= htmlspecialchars($item['description'] ?? '') ?></p>
            <a class="view-btn" href="item-details.php?itemId=<?= urlencode($itemId) ?>">View item</a>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </main>

</div><!-- /page-wrap -->

<!-- ══════════════════════════════════════
     FOOTER — identical to landing.php
══════════════════════════════════════ -->
<footer>
  <div class="footer-top">
    <div style="display:flex;align-items:center;gap:10px;">
      <a class="social-icon" href="#">in</a>
      <a class="social-icon" href="#">&#120143;</a>
      <a class="social-icon" href="#">&#9834;</a>
    </div>
    <div class="footer-divider"></div>
    <div class="footer-brand"></div>
    <img src="../../images/Replate-white.png" alt="Replate" style="height:80px;object-fit:contain;opacity:1;" />
    <div class="footer-divider"></div>
    <div class="footer-email">
      <svg width="16" height="16" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2" viewBox="0 0 24 24">
        <rect x="2" y="4" width="20" height="16" rx="2"/>
        <path d="M2 7l10 7 10-7"/>
      </svg>
      Replate@gmail.com
    </div>
  </div>
  <div class="footer-bottom">
    <span>© 2026</span>
    <img src="../../images/Replate-white.png" alt="Replate" style="height:50px;object-fit:contain;opacity:1;" />
    <span>All rights reserved.</span>
  </div>
</footer>

<script>
  const IS_LOGGED_IN = <?= $isLoggedIn ? 'true' : 'false' ?>;

  // ── AJAX favourite toggle ──
  document.querySelectorAll('.fav-btn[data-item-id]').forEach(btn => {
    btn.addEventListener('click', async (e) => {
      e.stopPropagation();
      if (!IS_LOGGED_IN) {
        document.getElementById('authModal').style.display = 'flex';
        return;
      }
      const itemId = btn.dataset.itemId;
      if (!itemId) return;
      btn.classList.toggle('liked');
      try {
        const res  = await fetch(window.location.href, {
          method:  'POST',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body:    JSON.stringify({ action: 'toggle_fav', itemId })
        });
        const data = await res.json();
        if (!data.success) {
          btn.classList.toggle('liked');
        } else {
          btn.style.transform = 'scale(1.35)';
          setTimeout(() => { btn.style.transform = ''; }, 220);
        }
      } catch {
        btn.classList.toggle('liked');
      }
    });
  });

  // ── Bell notification dropdown ──
  function toggleNotifDropdown() {
    document.getElementById('notifDropdown')?.classList.toggle('open');
  }

  // ── Mark single notification as read ──
  function markRead(el) {
    if (!el.classList.contains('unread')) return;
    const notifId = el.dataset.id;
    el.classList.remove('unread');
    const dot = el.querySelector('.notif-unread-dot');
    if (dot) dot.remove();
    updateBellBadge(-1);
    fetch(window.location.href, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ action: 'mark_read', notifId })
    }).catch(() => {});
  }

  // ── Mark all as read ──
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
    fetch(window.location.href, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify({ action: 'mark_all_read' })
    }).catch(() => {});
  }

  function updateBellBadge(delta) {
    const badge = document.getElementById('bellBadge');
    if (!badge) return;
    const next = Math.max(0, (parseInt(badge.textContent) || 0) + delta);
    if (next === 0) { badge.style.display = 'none'; }
    else { badge.textContent = next; badge.style.display = 'flex'; }
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

  // ── Live search ──
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

  document.addEventListener('click', e => {
    if (searchWrap && !searchWrap.contains(e.target)) closeSearch();
    const bellWrap = document.querySelector('.nav-bell-wrap');
    if (bellWrap && !bellWrap.contains(e.target)) document.getElementById('notifDropdown')?.classList.remove('open');
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
    let html = '<div class="search-section-label">Providers</div>';
    if (providers.length) {
      providers.forEach(p => {
        const logo = p.businessLogo
          ? `<div class="search-provider-logo"><img src="${p.businessLogo}"/></div>`
          : `<div class="search-provider-logo">${p.businessName.charAt(0).toUpperCase()}</div>`;
        html += `<a class="search-item-row" href="providers-page.php?providerId=${p.id}">${logo}<div><p class="search-item-name">${hl(p.businessName,q)}</p><p class="search-item-sub">${p.category}</p></div></a>`;
      });
    } else {
      html += `<div class="search-no-match">No providers match "<em>${q}</em>"</div>`;
    }
    html += '<div class="search-divider"></div><div class="search-section-label">Products</div>';
    if (items.length) {
      items.forEach(item => {
        const thumb = item.photoUrl ? `<div class="search-thumb"><img src="${item.photoUrl}"/></div>` : '<div class="search-thumb">🍱</div>';
        html += `<a class="search-item-row" href="item-details.php?itemId=${item.id}">${thumb}<div><p class="search-item-name">${hl(item.name,q)}</p><p class="search-item-sub">Product</p></div><span class="search-price">${item.price}</span></a>`;
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

  

  // ── Category row drag-to-scroll ──
  const catRow = document.querySelector('.cat-links-row');
  if (catRow) {
    let isDown = false, startX, scrollLeft;
    catRow.addEventListener('mousedown', e => {
      isDown = true; catRow.classList.add('dragging');
      startX = e.pageX - catRow.offsetLeft;
      scrollLeft = catRow.scrollLeft;
    });
    catRow.addEventListener('mouseleave', () => { isDown = false; catRow.classList.remove('dragging'); });
    catRow.addEventListener('mouseup',    () => { isDown = false; catRow.classList.remove('dragging'); });
    catRow.addEventListener('mousemove',  e => {
      if (!isDown) return; e.preventDefault();
      catRow.scrollLeft = scrollLeft - (e.pageX - catRow.offsetLeft - startX) * 1.4;
    });
    // Scroll active item into view on load
    const active = catRow.querySelector('.cat-link.active');
    if (active) active.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
  }
</script>
</body>
</html>