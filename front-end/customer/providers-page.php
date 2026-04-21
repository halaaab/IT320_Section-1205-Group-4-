<?php
// ================================================================
// providers-page.php — Single Provider Profile + Their Items
// ================================================================
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/PickupLocation.php';
require_once '../../back-end/models/Category.php';

$providerId = $_GET['providerId'] ?? '';
$provider   = null;
$items      = [];
$location   = null;

if ($providerId) {
    $providerModel = new Provider();
    $provider      = $providerModel->findById($providerId);
    if ($provider) {
        unset($provider['passwordHash']);
        $items    = (new Item())->getByProvider($providerId);
        $items    = array_values(array_filter($items, fn($i) => $i['isAvailable']));
        $location = (new PickupLocation())->getDefault($providerId);
    }
}

require_once '../../back-end/models/Favourite.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Notification.php';

$isLoggedIn = !empty($_SESSION['customerId']);
$customerId = $_SESSION['customerId'] ?? null;
$type       = $_GET['type'] ?? 'all';

$catMap = [];
try {
    $allCats = (new Category())->getAll();
    foreach ($allCats as $c) { $catMap[(string)$c['_id']] = $c['name'] ?? ''; }
} catch (Throwable) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_fav' && $isLoggedIn) {
    $toggleItemId = trim($_POST['itemId'] ?? '');
    if ($toggleItemId) {
        $favModel = new Favourite();
        if ($favModel->isSaved($customerId, $toggleItemId)) { $favModel->remove($customerId, $toggleItemId); }
        else { $favModel->add($customerId, $toggleItemId); }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']); exit;
}

$savedIds = [];
if ($isLoggedIn) {
    $favs     = (new Favourite())->getByCustomer($customerId);
    $savedIds = array_map(fn($f) => (string)$f['itemId'], $favs);
}

$filteredItems = $items;
if ($type !== 'all') {
    $filteredItems = array_values(array_filter($items, fn($i) => ($i['listingType'] ?? '') === $type));
}

// ── Notifications + cart count (identical to landing page) ──
$notifications = [];
$unreadCount   = 0;
$cartCount     = 0;
if ($isLoggedIn) {
    try { $nm_=new Notification();$notifications=(array)$nm_->getByCustomer($customerId);$unreadCount=(int)$nm_->getUnreadCount($customerId); } catch(Throwable){}
    try { $cm_=new Cart();$ct_=$cm_->getOrCreate($customerId);$cartCount=array_sum(array_map(fn($ci)=>(int)($ci['quantity']??1),(array)($ct_['cartItems']??[]))); } catch(Throwable){}
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RePlate – <?= htmlspecialchars($provider['businessName'] ?? 'Provider') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'DM Sans',sans-serif;background:#f0f5fc;color:#1a2a45;min-height:100vh;display:flex;flex-direction:column;}
    a{text-decoration:none;color:inherit}

    /* ── NAVBAR (landing page identical) ── */
    nav{display:flex;align-items:center;justify-content:space-between;padding:0 48px;height:72px;background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(26,58,107,0.18);}
    .nav-left{display:flex;align-items:center;gap:16px;}
    .nav-logo{height:100px;}
    .nav-cart-wrap{position:relative;display:flex;align-items:center;}
    .nav-cart{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,0.7);display:flex;justify-content:center;align-items:center;cursor:pointer;transition:background 0.2s;text-decoration:none;}
    .nav-cart:hover{background:rgba(255,255,255,0.15);}
    .cart-badge{position:absolute;top:-5px;right:-5px;min-width:19px;height:19px;background:#e53935;border-radius:50%;border:2px solid #2255a4;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none;}
    .nav-center{display:flex;align-items:center;gap:40px;}
    .nav-center a{color:rgba(255,255,255,0.85);text-decoration:none;font-weight:500;font-size:15px;transition:color 0.2s;}
    .nav-center a:hover{color:#fff;}
    .nav-center a.active{color:#fff;font-weight:600;border-bottom:2px solid #fff;padding-bottom:2px;}
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
    .search-loading{padding:18px 16px;text-align:center;color:#b0c4d8;font-size:13px;}
    .search-no-match{padding:8px 16px 12px;font-size:13px;color:#b0c4d8;font-style:italic;}
    .search-provider-logo{width:38px;height:38px;border-radius:50%;background:#e0eaf5;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:#2255a4;}
    .search-provider-logo img{width:100%;height:100%;object-fit:cover;}
    .nav-search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:0.6;pointer-events:none;}
    .nav-search-wrap input{background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.4);border-radius:50px;padding:9px 16px 9px 36px;color:#fff;font-size:14px;outline:none;width:240px;font-family:'Playfair Display',serif;transition:width 0.3s,background 0.2s;}
    .nav-search-wrap input::placeholder{color:rgba(255,255,255,0.6);}
    .nav-search-wrap input:focus{width:300px;background:rgba(255,255,255,0.25);}
    .nav-avatar{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,0.6);display:flex;align-items:center;justify-content:center;cursor:pointer;text-decoration:none;background:rgba(255,255,255,0.15);}
    .nav-avatar:hover{background:rgba(255,255,255,0.25);}
    .btn-signup{background:#fff;color:#1a3a6b;border:none;border-radius:50px;padding:9px 22px;font-weight:700;font-size:14px;font-family:'Playfair Display',serif;cursor:pointer;box-shadow:0 2px 8px rgba(0,0,0,0.1);transition:transform 0.15s,box-shadow 0.15s;text-decoration:none;display:inline-flex;align-items:center;}
    .btn-signup:hover{transform:translateY(-1px);box-shadow:0 4px 16px rgba(0,0,0,0.15);}
    .btn-login{background:transparent;color:#fff;border:2px solid #fff;border-radius:50px;padding:8px 22px;font-weight:700;font-size:14px;font-family:'Playfair Display',serif;cursor:pointer;transition:background 0.2s;text-decoration:none;display:inline-flex;align-items:center;}
    .btn-login:hover{background:rgba(255,255,255,0.15);}
    .nav-bell-wrap{position:relative;}
    .nav-bell{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,0.6);display:flex;align-items:center;justify-content:center;cursor:pointer;background:none;transition:background 0.2s;}
    .nav-bell:hover{background:rgba(255,255,255,0.15);}
    .bell-badge{position:absolute;top:-3px;right:-3px;min-width:18px;height:18px;background:#e53935;border-radius:50%;border:2px solid #2255a4;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none;}
    .notif-dropdown{display:none;position:absolute;top:50px;right:0;width:360px;background:#fff;border-radius:20px;box-shadow:0 12px 48px rgba(26,58,107,0.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden;}
    .notif-dropdown.open{display:block;}
    .notif-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1.5px solid #f0f5fc;background:#fff;}
    .notif-header-title{font-size:15px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;}
    .notif-mark-all{font-size:12px;color:#2255a4;background:none;border:none;cursor:pointer;font-family:'Playfair Display',serif;font-weight:600;padding:0;}
    .notif-list{max-height:380px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#c8d8ee transparent;}
    .notif-empty-msg{padding:28px 16px;text-align:center;color:#b0c4d8;font-size:13px;}
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

    /* ── HERO BANNER ── */
    .hero-banner{background:linear-gradient(90deg,#1a3a6b 0%,#2a5db5 50%,#6aaee8 100%);height:120px;display:flex;align-items:center;padding:0 32px;gap:20px;position:relative;overflow:hidden;}
    .hero-banner::before{content:'';position:absolute;inset:0;background:url('../../images/provider-banner.png') center/cover no-repeat;opacity:0.15;z-index:0;pointer-events:none;}

    /* ── BACK BUTTON ── */
    .back-btn{width:46px;height:46px;border-radius:50%;background:#cdd9e8;color:#1b3f92;display:flex;align-items:center;justify-content:center;font-size:28px;line-height:1;flex-shrink:0;font-weight:700;text-decoration:none;transition:background 0.2s;position:relative;z-index:1;font-family:'Playfair Display',serif;border:none;cursor:pointer;}
    .back-btn:hover{background:#bfcee2;}

    /* ── CONTENT WRAPPER (fills space so footer stays at bottom) ── */
    .page-content{flex:1;}

    /* ── CONTAINER ── */
    .container{max-width:1140px;margin:0 auto;padding:0 24px;}

    /* ── PROVIDER CARD ── */
    .provider-card{background:#fff;border:1.5px solid #dce7f5;border-radius:20px;padding:28px 32px;margin-top:-40px;position:relative;z-index:2;box-shadow:0 8px 28px rgba(26,58,107,0.1);display:flex;align-items:flex-start;gap:28px;margin-bottom:8px;}
    .prov-logo{width:100px;height:100px;border-radius:18px;object-fit:contain;border:1.5px solid #dce7f5;padding:10px;background:#fff;flex-shrink:0;}
    .prov-logo-ph{width:100px;height:100px;border-radius:18px;background:#e8f0ff;display:grid;place-items:center;color:#1a3a6b;font-size:32px;font-weight:700;flex-shrink:0;border:1.5px solid #dce7f5;}
    .prov-name{font-family:'Playfair Display',serif;font-size:38px;color:#1a3a6b;font-weight:700;margin-bottom:4px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;}
    .prov-cat-badge{display:inline-block;background:#e8f0ff;color:#2255a4;border-radius:50px;padding:4px 14px;font-size:13px;font-weight:700;font-family:'DM Sans',sans-serif;}
    .prov-divider{height:1.5px;background:#dce7f5;margin:16px 0;}
    .prov-desc{font-size:16px;line-height:1.75;color:#4a5a75;max-width:720px;}

    /* ── FILTER TABS ── */
    .filter-bar{display:flex;gap:12px;margin:28px 0 22px;}
    .filter-tab{padding:9px 28px;border-radius:50px;border:2px solid #e07a1a;background:transparent;color:#e07a1a;font-weight:700;font-size:15px;font-family:'DM Sans',sans-serif;cursor:pointer;transition:all 0.2s;text-decoration:none;display:inline-block;}
    .filter-tab.active,.filter-tab:hover{background:#e07a1a;color:#fff;}



    /* ── ITEM CARD ── */
    .item-card{background:#f2f4f8;border-radius:24px;border:1.5px solid #c8d8ee;padding:18px 18px 20px;display:flex;flex-direction:column;gap:0;box-shadow:0 2px 14px rgba(26,58,107,0.07);transition:box-shadow 0.2s,transform 0.2s;}
    .item-card:hover{box-shadow:0 8px 28px rgba(26,58,107,0.13);transform:translateY(-3px);}
    .card-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
    .prov-logo-box{display:flex;align-items:center;gap:8px;}
    .prov-logo-circle{width:32px;height:32px;background:#c8d8ee;border-radius:50%;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;}
    .prov-logo-circle img{width:100%;height:100%;object-fit:cover;}
    .prov-logo-name{font-size:15px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;}
    .fav-btn{background:none;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;padding:0;transition:transform 0.2s;}
    .fav-btn:hover{transform:scale(1.15);}
    .fav-btn svg{width:28px;height:28px;overflow:visible;}
    .fav-btn .heart-path{fill:none;stroke:#8b1a1a;stroke-width:2;transition:fill 0.2s,stroke 0.2s;}
    .fav-btn.liked .heart-path{fill:#c0392b;stroke:#c0392b;}
    .cat-tag{display:inline-block;background:#e8f0ff;color:#2255a4;font-size:11px;font-weight:700;font-family:'DM Sans',sans-serif;border-radius:50px;padding:3px 10px;margin-bottom:6px;letter-spacing:0.04em;text-transform:uppercase;}
    .item-img-box{width:100%;height:130px;background:#d8e6f5;border-radius:14px;margin-bottom:16px;overflow:hidden;display:flex;align-items:center;justify-content:center;}
    .item-img-box img{width:100%;height:100%;object-fit:cover;border-radius:14px;}
    .item-img-ph-text{font-size:13px;color:#8aa3c0;}
    .card-divider{width:100%;height:1.5px;background:#c0d2e8;margin-bottom:14px;}
    .card-body{display:flex;flex-direction:column;gap:8px;}
    .name-row{display:flex;align-items:center;justify-content:space-between;gap:8px;}
    .item-name{font-size:18px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;}
    .price-row{display:flex;align-items:center;gap:5px;}
    .item-price{font-size:16px;font-weight:700;color:#e07a1a;}
    .price-free{color:#1a6b3a;}
    .sar-box{width:22px;height:22px;background:#c8d8ee;border-radius:4px;flex-shrink:0;}
    .item-desc{font-size:13px;color:#4a6a9a;line-height:1.5;font-family:'Playfair Display',serif;}
    .view-btn{background:#1a3a6b;color:#fff;border:none;border-radius:50px;padding:12px 0;font-size:15px;font-family:'Playfair Display',serif;cursor:pointer;font-weight:700;width:80%;text-align:center;margin:8px auto 0;display:block;transition:background 0.2s;text-decoration:none;}
    .view-btn:hover{background:#2255a4;}

    /* ── EMPTY / NOT FOUND ── */
    .empty-state{grid-column:1/-1;text-align:center;padding:60px 20px;color:#7a8fa8;}
    .empty-state h3{font-family:'Playfair Display',serif;font-size:26px;color:#1a3a6b;margin-bottom:10px;}
    .not-found{text-align:center;padding:80px 24px;flex:1;}
    .not-found h2{font-family:'Playfair Display',serif;font-size:30px;color:#1a3a6b;margin-bottom:10px;}
    .back-link{display:inline-block;margin-top:18px;background:#e07a1a;color:#fff;border-radius:50px;padding:12px 28px;font-weight:700;}

    /* ── FOOTER (identical to landing page, always at bottom) ── */
    footer{background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);padding:28px 48px;display:flex;flex-direction:column;align-items:center;gap:14px;margin-top:auto;}
   .footer-top{display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:center;}
    .social-icon{width:42px;height:42px;border-radius:50%;border:1.5px solid rgba(255,255,255,0.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;font-family:'Playfair Display',serif;transition:background 0.2s;}
    .social-icon:hover{background:rgba(255,255,255,0.15);}
    .footer-divider{width:1px;height:22px;background:rgba(255,255,255,0.3);}
    .footer-email{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,0.9);font-size:14px;font-family:'Playfair Display',serif;}
    .footer-bottom{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,0.7);font-size:13px;font-family:'Playfair Display',serif;}

@media(max-width:768px) {
      /* ── NAVBAR ── */
      nav { padding: 0 16px; }
      .nav-logo { height: 64px; }
      .nav-center { display: none; }
      .nav-search-wrap { display: none; }
      .hamburger { display: flex; }

      /* ── FOOTER ── */
      footer { padding: 24px 16px; }
      .footer-bottom { font-size: 11px; }

      /* ── PROVIDER INFO CARD ── */
      .provider-card { 
        flex-direction: column; 
        align-items: center; 
        text-align: center; 
        padding: 24px 20px; 
        gap: 16px; 
        margin-top: -20px; 
      }
      .prov-name { justify-content: center; font-size: 26px; }
      .prov-desc { font-size: 14px; }

      /* ── ITEMS GRID (Strictly 2 Columns) ── */
      .items-grid { 
        grid-template-columns: repeat(2, 1fr) !important; 
        gap: 12px !important; 
      }
      
      /* ── ITEM CARDS SCALING FOR 2x2 ── */
      .item-card { 
        min-width: 0 !important; 
        max-width: 100% !important; 
        width: 100% !important; 
        padding: 12px 12px 14px; 
      }
      .item-img-box { height: 110px; margin-bottom: 12px; }
      .item-name { font-size: 14px; }
      .item-price { font-size: 13px; }
      .item-desc { font-size: 12px; }
      .view-btn { padding: 10px 0; font-size: 13px; }
      .prov-logo-name { font-size: 12px; }
      .prov-logo-circle { width: 26px; height: 26px; }
      .fav-btn svg { width: 22px; height: 22px; }
      .price-row img { width: 16px !important; height: 16px !important; }
    }
    /* ── ITEMS GRID ── */
    .items-grid{display:grid;grid-template-columns:repeat(4, minmax(0, 1fr));gap:20px;margin-bottom:48px;}
    @media(max-width:1100px){.items-grid{grid-template-columns:repeat(3, minmax(0, 1fr))}}
    
    /* This absolutely forces 2 columns on tablets AND small phones */
    @media(max-width:800px){.items-grid{grid-template-columns:repeat(2, minmax(0, 1fr)) !important;}}
    @media(max-width:500px){.items-grid{grid-template-columns:repeat(2, minmax(0, 1fr)) !important; gap:12px !important;}}
    
  </style>
</head>
<body>

<!-- NAVBAR (identical to landing page) -->
<nav>
  <div class="nav-left">
    <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate Logo" />
    <div class="nav-cart-wrap">
      <a href="../customer/cart.php" class="nav-cart">
        <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;" />
      </a>
      <?php if ($isLoggedIn && $cartCount > 0): ?>
      <span class="cart-badge"><?= $cartCount ?></span>
      <?php endif; ?>
    </div>
  </div>
  <div class="nav-center">
    <a href="../shared/landing.php">Home Page</a>
    <a href="category.php">Categories</a>
    <a href="providers-list.php" class="active">Providers</a>
  </div>
  <div class="nav-right">
    <?php if (!$isLoggedIn): ?>
    <a class="btn-signup" href="../shared/signup-customer.php">Sign up</a>
    <a class="btn-login" href="../shared/login.php">Log in</a>
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
      <?php else: ?><span class="bell-badge" id="bellBadge" style="display:none">0</span>
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
        <div class="notif-empty-msg">
          <svg width="30" height="30" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
          You're all caught up!
        </div>
        <?php else: ?>
        <?php foreach (array_slice($notifications, 0, 8) as $notif_):
          $nIsRead_=$notif_['isRead']??false;$nMsg_=$notif_['message']??'';$nId_=(string)($notif_['_id']??'');$nType_=$notif_['type']??'';$nTime_='';
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
    <a href="../customer/customer-profile.php" class="nav-avatar">
      <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
      </svg>
    </a>
    <?php endif;?>
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
  <a href="../shared/landing.php" onclick="closeMobileMenu()">Home Page</a>
  <a href="category.php" onclick="closeMobileMenu()">Categories</a>
  <a href="providers-list.php" onclick="closeMobileMenu()">Providers</a>
  <?php if (!$isLoggedIn): ?>
  <a href="../shared/login.php" onclick="closeMobileMenu()">Log in</a>
  <a href="../shared/signup-customer.php" onclick="closeMobileMenu()">Sign up</a>
  <?php endif; ?>
</div>

<!-- Auth Modal -->
<div id="authModal" style="display:none;position:fixed;inset:0;background:rgba(12,22,45,0.5);z-index:9999;justify-content:center;align-items:center;" onclick="if(event.target===this)this.style.display='none'">
  <div style="background:#fff;border-radius:24px;padding:44px 40px;max-width:400px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
    <h3 style="font-size:22px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;margin-bottom:10px;">Sign in to continue</h3>
    <p style="font-size:14px;color:#7a8fa8;margin-bottom:28px;line-height:1.6;">Please log in or create an account.</p>
    <div style="display:flex;gap:12px;justify-content:center;">
      <a href="../shared/login.php" style="flex:1;padding:13px 0;border-radius:50px;background:#1a3a6b;color:#fff;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;text-decoration:none;display:flex;align-items:center;justify-content:center;">Log in</a>
      <a href="../shared/signup-customer.php" style="flex:1;padding:13px 0;border-radius:50px;background:transparent;color:#1a3a6b;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;text-decoration:none;border:2px solid #1a3a6b;display:flex;align-items:center;justify-content:center;">Sign up</a>
    </div>
    <button onclick="document.getElementById('authModal').style.display='none'" style="margin-top:18px;background:none;border:none;color:#b0c4d8;font-size:13px;cursor:pointer;font-family:'Playfair Display',serif;">Maybe later</button>
  </div>
</div>

<?php if (!$provider): ?>
  <!-- page-content wraps not-found so footer stays at bottom -->
  <div class="page-content">
    <div class="not-found">
      <h2>Provider not found</h2>
      <p style="color:#7a8fa8">This page is no longer available.</p>
      <a class="back-link" href="providers-list.php">View all providers</a>
    </div>
  </div>
<?php else: ?>

<!-- HERO BANNER -->
<div class="hero-banner">
  <a class="back-btn" href="javascript:history.back()">‹</a>
</div>

<!-- page-content wraps everything between hero and footer -->
<div class="page-content">
  <div class="container">

    <!-- PROVIDER INFO CARD -->
    <div class="provider-card">
      <?php if (!empty($provider['businessLogo'])): ?>
        <img class="prov-logo" src="<?= htmlspecialchars($provider['businessLogo']) ?>" alt="<?= htmlspecialchars($provider['businessName'] ?? '') ?>">
      <?php else: ?>
        <div class="prov-logo-ph"><?= htmlspecialchars(substr($provider['businessName'] ?? 'P', 0, 2)) ?></div>
      <?php endif; ?>
      <div style="flex:1">
        <div class="prov-name">
          <?= htmlspecialchars($provider['businessName'] ?? '') ?>
          <?php if (!empty($provider['category'])): ?>
            <span class="prov-cat-badge"><?= htmlspecialchars($provider['category']) ?></span>
          <?php endif; ?>
        </div>
        <div class="prov-divider"></div>
        <p class="prov-desc"><?= htmlspecialchars($provider['businessDescription'] ?? 'No description available.') ?></p>
      </div>
    </div>

    <!-- FILTER TABS -->
    <div class="filter-bar">
      <a class="filter-tab <?= $type==='all'    ? 'active':'' ?>" href="?providerId=<?= urlencode($providerId) ?>&type=all">All</a>
      <a class="filter-tab <?= $type==='donate' ? 'active':'' ?>" href="?providerId=<?= urlencode($providerId) ?>&type=donate">Donation</a>
      <a class="filter-tab <?= $type==='sell'   ? 'active':'' ?>" href="?providerId=<?= urlencode($providerId) ?>&type=sell">Buying</a>
    </div>

    <!-- ITEMS GRID -->
    <div class="items-grid">
      <?php if (empty($filteredItems)): ?>
        <div class="empty-state">
          <h3>No items available</h3>
          <p>This provider has no items matching this filter.</p>
        </div>
      <?php else: ?>
        <?php foreach ($filteredItems as $item):
          $itemId   = (string)$item['_id'];
          $isFree   = ($item['listingType'] ?? '') === 'donate';
          $isSaved  = in_array($itemId, $savedIds, true);
          $provLogo = $provider['businessLogo'] ?? '';
          $provName = $provider['businessName'] ?? '';
          $itemCatId= (string)($item['categoryId'] ?? '');
          $catName  = $catMap[$itemCatId] ?? '';
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
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="toggle_fav">
                <input type="hidden" name="itemId" value="<?= htmlspecialchars($itemId) ?>">
                <button class="fav-btn <?= $isSaved ? 'liked' : '' ?>" type="submit">
                  <svg viewBox="0 0 24 24"><path class="heart-path" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </button>
              </form>
            <?php else: ?>
              <button class="fav-btn" onclick="document.getElementById('authModal').style.display='flex'" type="button">
                <svg viewBox="0 0 24 24"><path class="heart-path" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
              </button>
            <?php endif; ?>
          </div>
          <?php if ($catName): ?><span class="cat-tag"><?= htmlspecialchars($catName) ?></span><?php endif; ?>
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
                  <span class="item-price price-free">Donation</span>
                <?php else: ?>
                  <span class="item-price"><?= number_format((float)($item['price'] ?? 0), 2) ?></span>
                  <img src="../../images/SAR.png" alt="SAR" style="width: 22px; height: 22px; flex-shrink: 0; object-fit: contain;">
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

  </div><!-- /container -->
</div><!-- /page-content -->
<?php endif; ?>

<!-- FOOTER (identical to landing page, always at bottom) -->
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
  function toggleNotifDropdown(){document.getElementById('notifDropdown')?.classList.toggle('open');}
  function markRead(el){if(!el.dataset.id)return;el.style.background='';el.style.borderLeft='';const d=el.querySelector('.unread-dot');if(d)d.remove();updateBellBadge(-1);fetch('providers-page.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'mark_read',notifId:el.dataset.id})}).catch(()=>{});}
  function markAllRead(){document.querySelectorAll('#notifDropdown [data-id]').forEach(el=>{el.style.background='';el.style.borderLeft='';const d=el.querySelector('.unread-dot');if(d)d.remove();});const b=document.getElementById('bellBadge');if(b)b.style.display='none';document.querySelector('.notif-mark-all')?.remove();fetch('providers-page.php',{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'mark_all_read'})}).catch(()=>{});}
  function updateBellBadge(delta){const b=document.getElementById('bellBadge');if(!b)return;const n=Math.max(0,(parseInt(b.textContent)||0)+delta);b.textContent=n;b.style.display=n===0?'none':'flex';}
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

  const searchInput=document.getElementById('searchInput'),searchDropdown=document.getElementById('searchDropdown'),searchWrap=document.getElementById('searchWrap');
  let searchTimer=null;
  searchInput?.addEventListener('input',function(){clearTimeout(searchTimer);const q=this.value.trim();if(q.length<2){searchDropdown?.classList.remove('open');return;}searchDropdown.innerHTML='<div class="search-loading">Searching...</div>';searchDropdown.classList.add('open');searchTimer=setTimeout(async()=>{try{const res=await fetch(`../../back-end/search.php?q=${encodeURIComponent(q)}`);const data=await res.json();let html='<div class="search-section-label">Providers</div>';if(data.providers?.length){data.providers.forEach(p=>{const logo=p.businessLogo?`<div class="search-provider-logo"><img src="${p.businessLogo}"/></div>`:`<div class="search-provider-logo">${p.businessName.charAt(0).toUpperCase()}</div>`;html+=`<a class="search-item-row" href="providers-page.php?providerId=${p.id}">${logo}<div><p class="search-item-name">${p.businessName}</p><p class="search-item-sub">${p.category}</p></div></a>`;});}else{html+=`<div class="search-no-match">No providers match "<em>${q}</em>"</div>`;}html+='<div class="search-divider"></div><div class="search-section-label">Products</div>';if(data.items?.length){data.items.forEach(item=>{const thumb=item.photoUrl?`<div class="search-thumb"><img src="${item.photoUrl}"/></div>`:'<div class="search-thumb">🍱</div>';html+=`<a class="search-item-row" href="item-details.php?itemId=${item.id}">${thumb}<div><p class="search-item-name">${item.name}</p><p class="search-item-sub">Product</p></div><span class="search-price">${item.price}</span></a>`;});}else{html+=`<div class="search-no-match">No products match "<em>${q}</em>"</div>`;}searchDropdown.innerHTML=html;searchDropdown.classList.add('open');}catch(e){searchDropdown.innerHTML='<div class="search-empty">Something went wrong.</div>';}},280);});
  searchInput?.addEventListener('keydown',e=>{if(e.key==='Escape')searchDropdown?.classList.remove('open');});
  document.addEventListener('click',e=>{if(searchWrap&&!searchWrap.contains(e.target))searchDropdown?.classList.remove('open');if(!document.querySelector('.nav-bell-wrap')?.contains(e.target))document.getElementById('notifDropdown')?.classList.remove('open');});
</script>
</body>
</html>