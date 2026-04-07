<?php
// ================================================================
// favorites.php — Customer Saved Items
// ================================================================
session_start();

// ── AJAX: mark notifications as read (identical to customer-profile.php) ──
if (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json') && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (empty($_SESSION['customerId'])) { echo json_encode(['success'=>false]); exit; }
    require_once '../../back-end/config/database.php';
    require_once '../../back-end/models/BaseModel.php';
    require_once '../../back-end/models/Notification.php';
    $inp = json_decode(file_get_contents('php://input'), true);
    $nm  = new Notification();
    $cid = $_SESSION['customerId'];
    if (($inp['action']??'') === 'mark_read')     $nm->markRead(trim($inp['notifId']??''));
    if (($inp['action']??'') === 'mark_all_read') $nm->markAllRead($cid);
    echo json_encode(['success'=>true]); exit;
}
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Favourite.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Customer.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Notification.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId = $_SESSION['customerId'];
$favModel   = new Favourite();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
    $itemId = $_POST['itemId'] ?? '';
    if ($itemId) $favModel->remove($customerId, $itemId);
    header('Location: favorites.php');
    exit;
}

$savedRefs  = $favModel->getByCustomer($customerId);
$itemModel  = new Item();
$favourites = [];
foreach ($savedRefs as $ref) {
    $item = $itemModel->findById((string)$ref['itemId']);
    if ($item && !empty($item['isAvailable']) && (int)($item['quantity'] ?? 0) > 0) {
        $favourites[] = $item;
    }
}

$customerModel = new Customer();
$customer      = $customerModel->findById($customerId);
$firstName     = explode(' ', $customer['fullName'] ?? 'Customer')[0];

// ── Notifications (identical to customer-profile.php) ──
$notifications = [];
$unreadCount   = 0;
$cartCount     = 0;
try {
    $nm_           = new Notification();
    $notifications = (array)$nm_->getByCustomer($customerId);
    $unreadCount   = (int)$nm_->getUnreadCount($customerId);
} catch (Throwable) {}
try {
    $cm_       = new Cart();
    $ct_       = $cm_->getOrCreate($customerId);
    $cartCount = array_sum(array_map(fn($ci)=>(int)($ci['quantity']??1),(array)($ct_['cartItems']??[])));
} catch (Throwable) {}

// Attach provider info to each favourite
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
    *{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'Playfair Display',serif;background:#fff;min-height:100vh;display:flex;flex-direction:column;}

    /* ── NAVBAR (identical to customer-profile.php) ── */
    nav.navbar{display:flex;align-items:center;justify-content:space-between;padding:0 40px;height:72px;background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(26,58,107,0.18);}
    .nav-logo{height:100px;}
    .nav-left{display:flex;align-items:center;gap:16px;}
    .nav-cart-wrap{position:relative;display:flex;align-items:center;}
    .nav-cart{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,0.7);display:flex;align-items:center;justify-content:center;text-decoration:none;transition:background 0.2s;}
    .nav-cart:hover{background:rgba(255,255,255,0.15);}
    .cart-badge{position:absolute;top:-5px;right:-5px;min-width:19px;height:19px;background:#e53935;border-radius:50%;border:2px solid #2255a4;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none;}
    .nav-center{display:flex;align-items:center;gap:40px;}
    .nav-center a{color:rgba(255,255,255,0.85);text-decoration:none;font-weight:500;font-size:15px;transition:color 0.2s;}
    .nav-center a:hover{color:#fff;}
    .nav-right{display:flex;align-items:center;gap:12px;}
    .nav-search-wrap{position:relative;}
    .search-dropdown{display:none;position:absolute;top:calc(100% + 10px);right:0;width:380px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(26,58,107,0.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden;}
    .search-dropdown.open{display:block;}
    .search-section-label{font-size:11px;font-weight:700;color:#b0c4d8;letter-spacing:0.08em;text-transform:uppercase;padding:12px 16px 6px;}
    .search-item-row{display:flex;align-items:center;gap:12px;padding:10px 16px;cursor:pointer;transition:background 0.15s;text-decoration:none;}
    .search-item-row:hover{background:#f0f6ff;}
    .search-thumb{width:38px;height:38px;border-radius:10px;background:#e0eaf5;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px;overflow:hidden;}
    .search-thumb img{width:100%;height:100%;object-fit:cover;border-radius:10px;}
    .search-item-name{font-size:14px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;}
    .search-item-sub{font-size:12px;color:#7a8fa8;}
    .search-price{margin-left:auto;font-size:13px;font-weight:700;color:#e07a1a;white-space:nowrap;}
    .search-divider{height:1px;background:#f0f5fc;margin:4px 0;}
    .search-empty{padding:24px 16px;text-align:center;color:#b0c4d8;font-size:14px;font-family:'Playfair Display',serif;}
    .search-no-match{padding:8px 16px 12px;font-size:13px;color:#b0c4d8;font-style:italic;}
    .search-loading{padding:18px 16px;text-align:center;color:#b0c4d8;font-size:13px;}
    .search-provider-logo{width:38px;height:38px;border-radius:50%;background:#e0eaf5;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:#2255a4;}
    .search-provider-logo img{width:100%;height:100%;object-fit:cover;}
    .nav-search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:0.6;pointer-events:none;}
    .nav-search-wrap input{background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.4);border-radius:50px;padding:9px 16px 9px 36px;color:#fff;font-size:14px;outline:none;width:240px;font-family:'Playfair Display',serif;transition:width 0.3s,background 0.2s;}
    .nav-search-wrap input::placeholder{color:rgba(255,255,255,0.6);}
    .nav-search-wrap input:focus{width:300px;background:rgba(255,255,255,0.25);}
    .nav-avatar{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,0.6);display:flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;background:rgba(255,255,255,0.15);}
    .nav-avatar:hover{background:rgba(255,255,255,0.25);}
    /* Bell */
    .nav-bell-wrap{position:relative;}
    .nav-bell{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,0.6);display:flex;align-items:center;justify-content:center;cursor:pointer;background:none;transition:background 0.2s;}
    .nav-bell:hover{background:rgba(255,255,255,0.15);}
    .bell-badge{position:absolute;top:-3px;right:-3px;min-width:18px;height:18px;background:#e53935;border-radius:50%;border:2px solid #2255a4;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none;}
    .notif-dropdown{display:none;position:absolute;top:48px;right:0;width:320px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(26,58,107,0.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden;}
    .notif-dropdown.open{display:block;}
    .notif-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1.5px solid #f0f5fc;}
    .notif-header-title{font-size:15px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;}
    .notif-mark-all-btn{font-size:12px;color:#2255a4;background:none;border:none;cursor:pointer;font-family:'Playfair Display',serif;font-weight:600;padding:0;}
    /* Hamburger */
    .hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer;background:none;border:none;padding:6px;}
    .hamburger span{display:block;width:24px;height:2.5px;background:#fff;border-radius:2px;transition:all 0.3s;}
    .hamburger.open span:nth-child(1){transform:translateY(7.5px) rotate(45deg);}
    .hamburger.open span:nth-child(2){opacity:0;}
    .hamburger.open span:nth-child(3){transform:translateY(-7.5px) rotate(-45deg);}
    .mobile-menu{display:none;position:fixed;inset:0;top:72px;background:linear-gradient(180deg,#1a3a6b 0%,#2255a4 100%);z-index:99;flex-direction:column;padding:32px 28px;gap:0;}
    .mobile-menu.open{display:flex;}
    .mobile-menu a{color:rgba(255,255,255,0.85);font-size:22px;font-weight:700;font-family:'Playfair Display',serif;padding:18px 0;border-bottom:1px solid rgba(255,255,255,0.12);text-decoration:none;}
    .mobile-menu a:hover{color:#fff;}
    .mobile-search{margin-top:24px;position:relative;}
    .mobile-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);opacity:0.6;pointer-events:none;}
    .mobile-search input{width:100%;background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.4);border-radius:50px;padding:12px 16px 12px 40px;color:#fff;font-size:15px;outline:none;font-family:'Playfair Display',serif;}
    .mobile-search input::placeholder{color:rgba(255,255,255,0.6);}
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

    /* ── PAGE LAYOUT (identical to customer-profile.php) ── */
    .page-body{display:flex;flex:1;}

    /* ── SIDEBAR (identical to customer-profile.php) ── */
    .sidebar{width:240px;min-height:calc(100vh - 72px);background:#2255a4;display:flex;flex-direction:column;padding:36px 24px 28px;flex-shrink:0;}
    .sidebar-welcome{color:rgba(255,255,255,0.75);font-size:18px;font-weight:400;margin-bottom:4px;}
    .sidebar-name{color:rgba(255,255,255,0.55);font-size:42px;font-weight:700;line-height:1.1;margin-bottom:36px;}
    .sidebar-nav{display:flex;flex-direction:column;gap:16px;flex:1;background:transparent;}
    .sidebar-link{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,0.75);text-decoration:none;font-size:16px;font-weight:400;padding:10px 8px;border-radius:0;transition:color 0.2s;background:none !important;-webkit-tap-highlight-color:transparent;}
    .sidebar-link:hover{color:#fff;background:none !important;}
    .sidebar-link.active{color:#fff !important;font-weight:700;border-bottom:2px solid rgba(255,255,255,0.5);background:none !important;padding-bottom:6px;}
    .sidebar-link svg{flex-shrink:0;opacity:0.8;}
    .sidebar-link.active svg{opacity:1;}
    .sidebar-logout{margin-top:24px;background:#fff;color:#1a3a6b;border:none;border-radius:50px;padding:12px 0;font-size:16px;font-weight:700;font-family:'Playfair Display',serif;cursor:pointer;width:100%;transition:background 0.2s;text-align:center;}
    .sidebar-logout:hover{background:#e8f0ff;}
    .sidebar-footer{margin-top:24px;padding-top:18px;border-top:1px solid rgba(255,255,255,0.15);display:flex;flex-direction:column;gap:12px;align-items:center;}
    .sidebar-footer-social{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;}
    .sidebar-social-icon{width:30px;height:30px;border-radius:50%;border:1.5px solid rgba(255,255,255,0.45);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,0.8);font-size:12px;font-weight:700;text-decoration:none;transition:background 0.2s;flex-shrink:0;}
    .sidebar-social-icon:hover{background:rgba(255,255,255,0.15);color:#fff;}
    .sidebar-footer-email{display:flex;align-items:center;justify-content:center;gap:6px;color:rgba(255,255,255,0.7);font-size:11px;}
    .sidebar-footer-copy{color:rgba(255,255,255,0.5);font-size:11px;display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap;}

    /* ── MAIN ── */
    .main{flex:1;padding:40px 48px;background:#fafdff;overflow-y:auto;}

    /* ── FAVOURITES GRID (identical item-card style to landing page) ── */
    .fav-title{font-size:30px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;margin-bottom:24px;}
    .fav-grid{display:grid;grid-template-columns:repeat(auto-fill,260px);gap:20px;justify-content:start;}
    .fav-card{min-width:260px;max-width:260px;background:#f2f4f8;border-radius:24px;border:1.5px solid #c8d8ee;padding:18px 18px 20px;display:flex;flex-direction:column;gap:0;box-shadow:0 2px 14px rgba(26,58,107,0.07);transition:box-shadow 0.2s,transform 0.2s;}
    .fav-card:hover{box-shadow:0 8px 28px rgba(26,58,107,0.13);transform:translateY(-3px);}
    .fav-card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
    .fav-prov-box{display:flex;align-items:center;gap:8px;}
    .fav-prov-circle{width:32px;height:32px;background:#c8d8ee;border-radius:50%;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;}
    .fav-prov-circle img{width:100%;height:100%;object-fit:cover;}
    .fav-prov-name-txt{font-size:15px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;}
    .fav-heart-btn{background:none;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;transition:transform 0.2s;}
    .fav-heart-btn:hover{transform:scale(1.15);}
    .fav-heart-btn svg{width:28px;height:28px;overflow:visible;}
    .fav-heart-btn .heart-path{fill:#c0392b;stroke:#c0392b;stroke-width:2;}
    .fav-img-box{width:100%;height:130px;background:#d8e6f5;border-radius:14px;margin-bottom:16px;overflow:hidden;display:flex;align-items:center;justify-content:center;}
    .fav-img-box img{width:100%;height:100%;object-fit:cover;border-radius:14px;}
    .fav-img-ph-text{font-size:13px;color:#8aa3c0;}
    .fav-divider{width:100%;height:1.5px;background:#c0d2e8;margin-bottom:14px;}
    .fav-card-body{display:flex;flex-direction:column;gap:8px;}
    .fav-name-row{display:flex;align-items:center;justify-content:space-between;gap:8px;}
    .fav-name{font-size:18px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;}
    .fav-price-row{display:flex;align-items:center;gap:5px;}
    .fav-price{font-size:16px;font-weight:700;color:#e07a1a;}
    .fav-price-free{color:#1a6b3a;}
    .fav-sar-box{width:22px;height:22px;background:#c8d8ee;border-radius:4px;flex-shrink:0;}
    .fav-desc{font-size:13px;color:#4a6a9a;line-height:1.5;font-family:'Playfair Display',serif;}
    .fav-view-btn{background:#1a3a6b;color:#fff;border:none;border-radius:50px;padding:12px 0;font-size:15px;font-family:'Playfair Display',serif;cursor:pointer;font-weight:700;width:80%;text-align:center;margin:8px auto 0;display:block;transition:background 0.2s;text-decoration:none;}
    .fav-view-btn:hover{background:#2255a4;}
    .fav-empty{padding:60px 24px;text-align:center;color:#b0c4d8;}
    .fav-empty h3{font-size:24px;font-weight:700;color:#1a3a6b;margin-bottom:10px;}
    .fav-empty a{display:inline-block;margin-top:16px;background:#e07a1a;color:#fff;border-radius:50px;padding:12px 28px;font-weight:700;text-decoration:none;}

    /* ── Notification panel (same as customer-profile) ── */
    .notif-panel{background:#f8fbff;border-radius:20px;border:1.5px solid #e0eaf5;overflow:hidden;}
    .notif-panel-header{display:flex;align-items:center;justify-content:space-between;padding:18px 20px 14px;border-bottom:1.5px solid #e8f0fa;background:#fff;}
    .notif-panel-title{font-size:17px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;display:flex;align-items:center;gap:8px;}
    .notif-count-badge{background:#e07a1a;color:#fff;border-radius:50px;padding:2px 10px;font-size:12px;font-weight:700;}
    .notif-count-badge.zero{background:#e0eaf5;color:#8a9ab5;}
    .mark-read-btn{font-size:12px;color:#2255a4;background:none;border:none;cursor:pointer;font-family:'Playfair Display',serif;font-weight:600;padding:0;}
    .mark-read-btn:hover{color:#1a3a6b;}
    .notif-panel-body{max-height:520px;overflow-y:auto;}
    .notif-panel-body::-webkit-scrollbar{width:4px;}
    .notif-panel-body::-webkit-scrollbar-track{background:transparent;}
    .notif-panel-body::-webkit-scrollbar-thumb{background:#c8d8ee;border-radius:4px;}
    .notif-card{display:flex;align-items:flex-start;gap:12px;padding:14px 20px;border-bottom:1px solid #eef4fc;transition:background 0.15s;cursor:pointer;}
    .notif-card:last-child{border-bottom:none;}
    .notif-card:hover{background:#f0f6ff;}
    .notif-card.unread{background:#fff;border-left:3px solid #e07a1a;}
    .notif-card.unread:hover{background:#fff9f4;}
    .notif-card-icon{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px;}
    .notif-card-body{flex:1;min-width:0;}
    .notif-card-title{font-size:13px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;margin-bottom:4px;line-height:1.45;}
    .ntag{display:inline-flex;align-items:center;gap:3px;border-radius:50px;padding:2px 8px;font-size:10px;font-weight:700;margin-right:4px;margin-top:4px;}
    .ntag-expiry{background:#fff4e6;color:#e07a1a;}
    .ntag-cart{background:#e8f7ee;color:#1a6b3a;}
    .ntag-fav{background:#e8f0ff;color:#2255a4;}
    .ntag-order{background:#e8f7ee;color:#1a6b3a;}
    .ntag-cancel{background:#fde8e8;color:#e53935;}
    .ntag-pickup{background:#e8f0ff;color:#2255a4;}
    .ntag-red{background:#fde8e8;color:#c0392b;}
    .ntag-orange{background:#fff0e0;color:#c96a10;}
    .ntag-yellow{background:#fffbe6;color:#9a7d0a;}
    .notif-card-time{font-size:11px;color:#b0c4d8;margin-top:5px;}
    .notif-panel-empty{padding:40px 20px;text-align:center;color:#b0c4d8;font-size:14px;font-family:'Playfair Display',serif;}

    /* Dashboard grid (profile + notif panel) */
    .dashboard-grid{display:grid;grid-template-columns:1fr 340px;gap:28px;align-items:start;}
    .profile-col{display:flex;flex-direction:column;}

    @media(max-width:768px){nav.navbar{padding:0 18px;}.nav-logo{height:72px;}.nav-center,.nav-search-wrap{display:none;}.hamburger{display:flex;}.sidebar{display:none;}.page-body{display:block;}.main{padding:20px 16px;}.dashboard-grid{display:flex;flex-direction:column;}.notif-panel{position:static;}}
  </style>
</head>
<body>

  <!-- ── NAVBAR (identical to customer-profile.php) ── -->
  <nav class="navbar">
    <div class="nav-left">
      <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
      <div class="nav-cart-wrap">
        <a href="../customer/cart.php" class="nav-cart">
          <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;"/>
        </a>
        <?php if ($cartCount > 0): ?><span class="cart-badge"><?= $cartCount ?></span><?php endif; ?>
      </div>
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
        <?php if ($unreadCount > 0): ?>
        <span class="bell-badge" id="bellBadge"><?= $unreadCount ?></span>
        <?php else: ?><span class="bell-badge" id="bellBadge" style="display:none">0</span><?php endif; ?>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-header">
            <span class="notif-header-title">Notifications</span>
            <?php if ($unreadCount > 0): ?>
            <button class="notif-mark-all-btn" onclick="markAllRead()">Mark all read</button>
            <?php endif; ?>
          </div>
          <div style="max-height:360px;overflow-y:auto;">
          <?php if (empty($notifications)): ?>
          <div style="padding:28px 16px;text-align:center;color:#b0c4d8;font-size:13px;">
            <svg width="30" height="30" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            You're all caught up!
          </div>
          <?php else: ?>
          <?php foreach (array_slice($notifications, 0, 8) as $notif_):
            $nIsRead_=(bool)($notif_['isRead']??false);$nMsg_=$notif_['message']??'';$nId_=(string)($notif_['_id']??'');$nType_=$notif_['type']??'';$nTime_='';
            try{if(!empty($notif_['createdAt'])){$ts_=$notif_['createdAt']->toDateTime()->getTimestamp();$d_=time()-$ts_;$nTime_=$d_<60?'Just now':($d_<3600?floor($d_/60).'m ago':($d_<86400?floor($d_/3600).'h ago':date('d M',$ts_)));}}catch(Throwable $e_){}
            $nIconBg_='#f2f4f8';$nIconSvg_='';$nBl_='';
            if($nType_==='expiry_alert'){$urg_=str_contains($nMsg_,'[red]')?'red':(str_contains($nMsg_,'[orange]')?'orange':'yellow');$urgC_=$urg_==='red'?'#c0392b':($urg_==='orange'?'#e07a1a':'#d4ac0d');$nIconBg_=$urg_==='red'?'#fde8e8':($urg_==='orange'?'#fff0e0':'#fffbe6');$nIconSvg_='<svg width="14" height="14" fill="none" stroke="'.$urgC_.'" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';$nBl_='border-left:3px solid '.$urgC_.';';}
            elseif($nType_==='order_placed'){$nIconBg_='#e8f7ee';$nBl_='border-left:3px solid #1a6b3a;';$nIconSvg_='<svg width="14" height="14" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 12 11 14 15 10"/></svg>';}
            elseif($nType_==='order_completed'){$nIconBg_='#e8f7ee';$nBl_='border-left:3px solid #1a6b3a;';$nIconSvg_='<svg width="14" height="14" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>';}
            elseif($nType_==='order_cancelled'){$nIconBg_='#fde8e8';$nBl_='border-left:3px solid #e53935;';$nIconSvg_='<svg width="14" height="14" fill="none" stroke="#e53935" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';}
            $nClean_=trim(preg_replace('/\[(?:red|orange|yellow|pickup|completed|cancelled)\]\s*/','',htmlspecialchars($nMsg_)));
          ?>
          <div onclick="markRead(this)" data-id="<?= $nId_ ?>" style="display:flex;align-items:flex-start;gap:10px;padding:13px 16px;border-bottom:1px solid #f5f8fc;cursor:pointer;transition:background 0.15s;<?= $nBl_ ?><?= !$nIsRead_?'background:#fffaf5;':'' ?>">
            <div style="width:32px;height:32px;border-radius:50%;background:<?= $nIconBg_ ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;"><?= $nIconSvg_ ?></div>
            <div style="flex:1;min-width:0;">
              <p style="font-size:12.5px;font-weight:<?= $nIsRead_?'500':'700' ?>;color:#1a3a6b;font-family:'Playfair Display',serif;margin-bottom:2px;line-height:1.4;"><?= $nClean_ ?></p>
              <span style="font-size:11px;color:#b0c4d8;"><?= $nTime_ ?></span>
            </div>
            <?php if(!$nIsRead_):?><div class="unread-dot" style="width:7px;height:7px;background:#e07a1a;border-radius:50%;flex-shrink:0;margin-top:4px;"></div><?php endif;?>
          </div>
          <?php endforeach;?>
          <?php endif;?>
          </div>
        </div>
      </div>
      <a href="customer-profile.php" class="nav-avatar">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
      <button id="hamburger" class="hamburger" onclick="toggleMobileMenu()" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </nav>

  <!-- Mobile Menu -->
<div class="mobile-menu" id="mobileMenu">
  <div class="mobile-search">
    <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
    <input type="text" id="mobileSearchInput" placeholder="Search products or providers..." autocomplete="off"/>
  </div>
  <div id="mobileSearchDropdown" class="mobile-search-dropdown"></div>
  <a href="../shared/landing.php" onclick="closeMobileMenu()">Home</a>
  <a href="customer-profile.php" onclick="closeMobileMenu()">Profile</a>
  <a href="favorites.php" onclick="closeMobileMenu()">Favourites</a>
  <a href="orders.php" onclick="closeMobileMenu()">Orders</a>
  <a href="contact.php" onclick="closeMobileMenu()">Contact Us</a>
  <a href="customer-profile.php?logout=1" onclick="closeMobileMenu()">Log out</a>
</div>

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
          <svg width="13" height="13" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
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
                  <div class="fav-prov-box">
                    <div class="fav-prov-circle">
                      <?php if ($provLogo): ?>
                        <img src="<?= htmlspecialchars($provLogo) ?>" alt="<?= htmlspecialchars($provName) ?>">
                      <?php else: ?>
                        <span style="font-size:12px;font-weight:700;color:#2255a4;"><?= htmlspecialchars(mb_strtoupper(mb_substr($provName,0,1))) ?></span>
                      <?php endif; ?>
                    </div>
                    <span class="fav-prov-name-txt"><?= htmlspecialchars($provName) ?></span>
                  </div>
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="remove"/>
                    <input type="hidden" name="itemId" value="<?= htmlspecialchars($itemId) ?>"/>
                    <button class="fav-heart-btn" type="submit" title="Remove from favourites">
                      <svg viewBox="0 0 24 24"><path class="heart-path" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    </button>
                  </form>
                </div>
                <div class="fav-img-box">
                  <?php if (!empty($item['photoUrl'])): ?>
                    <img src="<?= htmlspecialchars($item['photoUrl']) ?>" alt="<?= htmlspecialchars($item['itemName'] ?? '') ?>">
                  <?php else: ?>
                    <span class="fav-img-ph-text">No image</span>
                  <?php endif; ?>
                </div>
                <div class="fav-divider"></div>
                <div class="fav-card-body">
                  <div class="fav-name-row">
                    <span class="fav-name"><?= htmlspecialchars($item['itemName'] ?? 'Item') ?></span>
                    <div class="fav-price-row">
                      <?php if ($isFree): ?>
                        <span class="fav-price fav-price-free">Free</span>
                      <?php else: ?>
                        <span class="fav-price"><?= number_format((float)($item['price'] ?? 0), 2) ?></span>
                        <div class="fav-sar-box"></div>
                      <?php endif; ?>
                    </div>
                  </div>
                  <p class="fav-desc"><?= htmlspecialchars($item['description'] ?? '') ?></p>
                  <a class="fav-view-btn" href="item-details.php?itemId=<?= urlencode($itemId) ?>">View item</a>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div><!-- /profile-col -->

        <!-- RIGHT: Notification Center (identical to customer-profile.php) -->
        <div>
          <div class="notif-panel">
            <div class="notif-panel-header">
              <div class="notif-panel-title">
                <svg width="18" height="18" fill="none" stroke="#1a3a6b" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                Notification Center
                <span class="notif-count-badge <?= $unreadCount===0 ? 'zero' : '' ?>" id="panelBadge"><?= $unreadCount ?></span>
              </div>
              <?php if ($unreadCount > 0): ?>
              <button class="mark-read-btn" onclick="markAllRead()">Mark all read</button>
              <?php endif; ?>
            </div>
            <div class="notif-panel-body" id="notifPanelBody">
              <?php if (empty($notifications)): ?>
              <div class="notif-panel-empty">
                <svg width="40" height="40" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto 12px;"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                You're all caught up!<br>
                <span style="font-size:12px;">Expiry alerts and order updates appear here</span>
              </div>
              <?php else: ?>
              <?php foreach ($notifications as $notif_p):
                $npRead_=(bool)($notif_p['isRead']??false);$npMsg_=htmlspecialchars($notif_p['message']??'');$npId_=(string)($notif_p['_id']??'');$npType_=$notif_p['type']??'';$npTime_='';
                try{if(!empty($notif_p['createdAt'])){$ts_=$notif_p['createdAt']->toDateTime()->getTimestamp();$d_=time()-$ts_;$npTime_=$d_<60?'Just now':($d_<3600?floor($d_/60).'m ago':($d_<86400?floor($d_/3600).'h ago':date('d M',$ts_)));}}catch(Throwable $e_){}
                $npIconBg_='#f2f4f8';$npIconSvg_='';$npTags_='';$npBL_=$npRead_?'':'border-left:3px solid #e07a1a;';
                if($npType_==='expiry_alert'){$rawMsg_=$notif_p['message']??'';$urg_=str_contains($rawMsg_,'[red]')?'red':(str_contains($rawMsg_,'[orange]')?'orange':'yellow');$urgC_=$urg_==='red'?'#c0392b':($urg_==='orange'?'#e07a1a':'#d4ac0d');$npIconBg_=$urg_==='red'?'#fde8e8':($urg_==='orange'?'#fff0e0':'#fffbe6');$npIconSvg_='<svg width="15" height="15" fill="none" stroke="'.$urgC_.'" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';preg_match('/expires in ([^!]+)!/',$rawMsg_,$m_);$timeTag_=!empty($m_[1])?'<span class="ntag ntag-'.$urg_.'">'.trim($m_[1]).'</span>':'';$srcTag_=str_contains($rawMsg_,'(Cart)')?'<span class="ntag ntag-cart">Cart</span>':(str_contains($rawMsg_,'(Favourites)')?'<span class="ntag ntag-fav">Favourites</span>':'');$npTags_=$timeTag_.$srcTag_;$npBL_='border-left:3px solid '.$urgC_.'.';}
                elseif($npType_==='order_placed'){$npIconBg_='#e8f7ee';$npIconSvg_='<svg width="15" height="15" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 12 11 14 15 10"/></svg>';$npTags_='<span class="ntag ntag-order">✓ Order placed</span>';$npBL_='border-left:3px solid #1a6b3a;';}
                elseif($npType_==='order_completed'){$npIconBg_='#e8f7ee';$npIconSvg_='<svg width="15" height="15" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>';$npTags_='<span class="ntag ntag-order">Picked up</span>';$npBL_='border-left:3px solid #1a6b3a;';}
                elseif($npType_==='order_cancelled'){$npIconBg_='#fde8e8';$npIconSvg_='<svg width="15" height="15" fill="none" stroke="#e53935" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';$npTags_='<span class="ntag ntag-cancel">✕ Cancelled</span>';$npBL_='border-left:3px solid #e53935;';}
                $npClean_=trim(preg_replace('/\[(?:red|orange|yellow|pickup|completed|cancelled)\]\s*/','',str_replace('.','',str_replace(';','',$npMsg_))));
              ?>
              <div class="notif-card <?= $npRead_?'':'unread' ?>" data-id="<?= $npId_ ?>" onclick="markRead(this)" style="<?= $npBL_ ?>">
                <div class="notif-card-icon" style="background:<?= $npIconBg_ ?>;width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?= $npIconSvg_ ?></div>
                <div class="notif-card-body">
                  <p class="notif-card-title" style="font-weight:<?= $npRead_?'600':'700' ?>;"><?= htmlspecialchars(strip_tags($npClean_)) ?></p>
                  <div style="margin-top:4px;"><?= $npTags_ ?></div>
                  <p class="notif-card-time"><?= $npTime_ ?></p>
                </div>
                <?php if(!$npRead_):?><div class="unread-dot" style="width:8px;height:8px;background:#e07a1a;border-radius:50%;flex-shrink:0;margin-top:5px;"></div><?php endif;?>
              </div>
              <?php endforeach;?>
              <?php endif;?>
            </div>
          </div>
        </div><!-- /notif-col -->

      </div><!-- /dashboard-grid -->
    </main>
  </div><!-- /page-body -->

  <script>
    // ── Bell dropdown ──
    function toggleNotifDropdown(){document.getElementById('notifDropdown').classList.toggle('open');}
    // ── Mark single read ──
    function markRead(el){
      if(!el.dataset.id)return;
      el.style.background='';el.style.borderLeft='';
      const dot=el.querySelector('.unread-dot');if(dot)dot.remove();
      el.classList.remove('unread');
      const p=el.querySelector('.notif-card-title');if(p)p.style.fontWeight='600';
      updateBadges(-1);
      fetch('favorites.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'mark_read',notifId:el.dataset.id})}).catch(()=>{});
    }
    // ── Mark all read ──
    function markAllRead(){
      document.querySelectorAll('#notifDropdown [data-id]').forEach(el=>{el.style.background='';el.style.borderLeft='';const d=el.querySelector('.unread-dot');if(d)d.remove();});
      document.querySelectorAll('.notif-card.unread').forEach(el=>{el.classList.remove('unread');const d=el.querySelector('.unread-dot');if(d)d.remove();const p=el.querySelector('.notif-card-title');if(p)p.style.fontWeight='600';});
      const bb=document.getElementById('bellBadge');if(bb)bb.style.display='none';
      document.querySelector('.notif-mark-all-btn')?.style.setProperty('display','none');
      document.querySelector('.mark-read-btn')?.style.setProperty('display','none');
      const pb=document.getElementById('panelBadge');if(pb){pb.textContent='0';pb.classList.add('zero');}
      fetch('favorites.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'mark_all_read'})}).catch(()=>{});
    }
    function updateBadges(delta){
      const b1=document.getElementById('bellBadge');if(b1){const n=Math.max(0,(parseInt(b1.textContent)||0)+delta);b1.textContent=n;b1.style.display=n===0?'none':'flex';}
      const b2=document.getElementById('panelBadge');if(b2){const n=Math.max(0,(parseInt(b2.textContent)||0)+delta);b2.textContent=n;b2.classList.toggle('zero',n===0);}
    }
    // ── Mobile menu ──
    function toggleMobileMenu(){const m=document.getElementById('mobileMenu'),b=document.getElementById('hamburger');m.classList.toggle('open');b.classList.toggle('open');document.body.style.overflow=m.classList.contains('open')?'hidden':'';}
    function closeMobileMenu(){document.getElementById('mobileMenu').classList.remove('open');document.getElementById('hamburger').classList.remove('open');document.body.style.overflow='';}
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
    // ── Live Search ──
    const searchInput=document.getElementById('searchInput'),searchDropdown=document.getElementById('searchDropdown'),searchWrap=document.getElementById('searchWrap');
    let searchTimer=null;
    searchInput?.addEventListener('input',function(){clearTimeout(searchTimer);const q=this.value.trim();if(q.length<2){searchDropdown?.classList.remove('open');return;}searchDropdown.innerHTML='<div class="search-loading">Searching...</div>';searchDropdown.classList.add('open');searchTimer=setTimeout(async()=>{try{const res=await fetch(`../../back-end/search.php?q=${encodeURIComponent(q)}`);const data=await res.json();let html='<div class="search-section-label">Providers</div>';if(data.providers?.length){data.providers.forEach(p=>{const logo=p.businessLogo?`<div class="search-provider-logo"><img src="${p.businessLogo}"/></div>`:`<div class="search-provider-logo">${p.businessName.charAt(0).toUpperCase()}</div>`;html+=`<a class="search-item-row" href="providers-page.php?providerId=${p.id}">${logo}<div><p class="search-item-name">${p.businessName}</p><p class="search-item-sub">${p.category}</p></div></a>`;});}else{html+=`<div class="search-no-match">No providers match "<em>${q}</em>"</div>`;}html+='<div class="search-divider"></div><div class="search-section-label">Products</div>';if(data.items?.length){data.items.forEach(item=>{const thumb=item.photoUrl?`<div class="search-thumb"><img src="${item.photoUrl}"/></div>`:'<div class="search-thumb">🍱</div>';html+=`<a class="search-item-row" href="item-details.php?itemId=${item.id}">${thumb}<div><p class="search-item-name">${item.name}</p><p class="search-item-sub">Product</p></div><span class="search-price">${item.price}</span></a>`;});}else{html+=`<div class="search-no-match">No products match "<em>${q}</em>"</div>`;}searchDropdown.innerHTML=html;searchDropdown.classList.add('open');}catch(e){searchDropdown.innerHTML='<div class="search-empty">Something went wrong.</div>';}},280);});
    searchInput?.addEventListener('keydown',e=>{if(e.key==='Escape')searchDropdown?.classList.remove('open');});
    document.addEventListener('click',e=>{if(searchWrap&&!searchWrap.contains(e.target))searchDropdown?.classList.remove('open');if(!document.querySelector('.nav-bell-wrap')?.contains(e.target))document.getElementById('notifDropdown')?.classList.remove('open');});

    // ── AJAX for mark_read/mark_all_read (favorites.php needs to handle these POST requests) ──
    // Add AJAX handler at top of PHP if not already present.
  </script>
</body>
</html>