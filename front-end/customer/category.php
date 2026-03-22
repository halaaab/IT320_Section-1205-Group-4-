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
    // Specific category
    $items = $itemModel->getByCategory($categoryId);
    if (empty($items)) {
        try {
            $items = $itemModel->findAll(['categoryId' => new MongoDB\BSON\ObjectId($categoryId)]);
        } catch (Throwable) {}
    }
} else {
    // "All" — get every item in the DB with no filter
    try {
        $items = $itemModel->findAll([]);
    } catch (Throwable) {}
}

// Apply listing type filter (All / Donation / Buying)
if ($type !== 'all') {
    $items = array_values(array_filter($items, fn($i) => ($i['listingType'] ?? '') === $type));
}

// ── Added: provider model for item cards + session info ──
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Favourite.php';
require_once '../../back-end/models/Cart.php';

$providerModel = new Provider();

$isLoggedIn   = !empty($_SESSION['customerId']);
$customerId   = $_SESSION['customerId'] ?? null;

// ── Handle favourite toggle directly on this page ──
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
    // Redirect back to same page preserving all GET params
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$savedIds = [];
if ($isLoggedIn) {
    $favs     = (new Favourite())->getByCustomer($customerId);
    $savedIds = array_map(fn($f) => (string)$f['itemId'], $favs);
}
// ── Added: expiry alerts for bell icon (same as landing page) ──
$expiryAlerts = [];
$alertCount   = 0;
if ($isLoggedIn) {
    try {
        $now  = time();
        $soon = $now + 48 * 3600;
        $cartModel2  = new Cart();
        $cart2       = $cartModel2->getOrCreate($customerId);
        $cartItemIds2 = array_map(fn($ci) => (string)$ci['itemId'], (array)($cart2['cartItems'] ?? []));
        $favModel2   = new Favourite();
        $favs2       = $favModel2->getByCustomer($customerId);
        $favItemIds2 = array_map(fn($f) => (string)$f['itemId'], $favs2);
        $watchedIds2 = array_unique(array_merge($cartItemIds2, $favItemIds2));
        $itemModel3  = new Item();
        foreach ($watchedIds2 as $wid) {
            try {
                $witem = $itemModel3->findById($wid);
                if (!$witem || !isset($witem['expiryDate'])) continue;
                $expiry = $witem['expiryDate']->toDateTime()->getTimestamp();
                if ($expiry >= $now && $expiry <= $soon) {
                    $hoursLeft = ceil(($expiry - $now) / 3600);
                    $expiryAlerts[] = ['name' => $witem['itemName'] ?? 'Item', 'hoursLeft' => $hoursLeft, 'source' => in_array($wid, $cartItemIds2) ? 'cart' : 'favourites'];
                }
            } catch (Throwable) { continue; }
        }
        $alertCount = count($expiryAlerts);
    } catch (Throwable) {}
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

    /* ── NAVBAR ── */
    nav { display:flex; align-items:center; justify-content:space-between; padding:0 48px; height:72px; background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%); position:sticky; top:0; z-index:100; box-shadow:0 2px 16px rgba(26,58,107,0.18); }
    .nav-left { display:flex; align-items:center; gap:16px; }
    .nav-logo { height:100px; }
    .nav-cart { width:40px; height:40px; border-radius:50%; border:2px solid rgba(255,255,255,0.7); display:flex; justify-content:center; align-items:center; cursor:pointer; transition:background 0.2s; text-decoration:none; }
    .nav-cart:hover { background:rgba(255,255,255,0.15); }
    .nav-avatar svg { stroke:#fff; }
    .nav-center { display:flex; align-items:center; gap:40px; }
    .nav-center a { color:rgba(255,255,255,0.85); text-decoration:none; font-weight:500; font-size:15px; transition:color 0.2s; }
    .nav-center a:hover { color:#fff; }
    .nav-center a.active { color:#fff; font-weight:600; border-bottom:2px solid #fff; padding-bottom:2px; }
    .nav-right { display:flex; align-items:center; gap:12px; }
    .nav-search-wrap { position:relative; }
    .search-dropdown { display:none; position:absolute; top:calc(100% + 10px); right:0; width:380px; background:#fff; border-radius:16px; box-shadow:0 8px 40px rgba(26,58,107,0.18); border:1.5px solid #e0eaf5; z-index:9999; overflow:hidden; }
    .search-dropdown.open { display:block; }
    .search-section-label { font-size:11px; font-weight:700; color:#b0c4d8; letter-spacing:0.08em; text-transform:uppercase; padding:12px 16px 6px; }
    .search-item-row { display:flex; align-items:center; gap:12px; padding:10px 16px; cursor:pointer; transition:background 0.15s; text-decoration:none; }
    .search-item-row:hover { background:#f0f6ff; }
    .search-thumb { width:38px; height:38px; border-radius:10px; background:#e0eaf5; flex-shrink:0; object-fit:cover; display:flex; align-items:center; justify-content:center; font-size:18px; }
    .search-thumb img { width:100%; height:100%; object-fit:cover; border-radius:10px; }
    .search-item-name { font-size:14px; font-weight:700; color:#1a3a6b; font-family:'Playfair Display',serif; }
    .search-item-sub { font-size:12px; color:#7a8fa8; }
    .search-price { margin-left:auto; font-size:13px; font-weight:700; color:#e07a1a; white-space:nowrap; }
    .search-divider { height:1px; background:#f0f5fc; margin:4px 0; }
    .search-empty { padding:24px 16px; text-align:center; color:#b0c4d8; font-size:14px; font-family:'Playfair Display',serif; }
    .search-loading { padding:18px 16px; text-align:center; color:#b0c4d8; font-size:13px; }
    .search-no-match { padding:8px 16px 12px; font-size:13px; color:#b0c4d8; font-style:italic; }
    .search-provider-logo { width:38px; height:38px; border-radius:50%; background:#e0eaf5; flex-shrink:0; overflow:hidden; display:flex; align-items:center; justify-content:center; font-size:15px; font-weight:700; color:#2255a4; }
    .search-provider-logo img { width:100%; height:100%; object-fit:cover; }
    .nav-search-wrap svg { position:absolute; left:12px; top:50%; transform:translateY(-50%); opacity:0.6; pointer-events:none; }
    .nav-search-wrap input { background:rgba(255,255,255,0.15); border:1.5px solid rgba(255,255,255,0.4); border-radius:50px; padding:9px 16px 9px 36px; color:#fff; font-size:14px; outline:none; width:240px; font-family:'Playfair Display',serif; transition:width 0.3s,background 0.2s; }
    .nav-search-wrap input::placeholder { color:rgba(255,255,255,0.6); }
    .nav-search-wrap input:focus { width:300px; background:rgba(255,255,255,0.25); }
    .nav-avatar { width:38px; height:38px; border-radius:50%; border:2px solid rgba(255,255,255,0.6); display:flex; align-items:center; justify-content:center; cursor:pointer; }
    .btn-signup { background:#fff; color:#1a3a6b; border:none; border-radius:50px; padding:9px 22px; font-weight:700; font-size:14px; font-family:'Playfair Display',serif; cursor:pointer; box-shadow:0 2px 8px rgba(0,0,0,0.1); transition:transform 0.15s,box-shadow 0.15s; }
    .btn-signup:hover { transform:translateY(-1px); box-shadow:0 4px 16px rgba(0,0,0,0.15); }
    .btn-login { background:transparent; color:#fff; border:2px solid #fff; border-radius:50px; padding:8px 22px; font-weight:700; font-size:14px; font-family:'Playfair Display',serif; cursor:pointer; transition:background 0.2s; }
    .btn-login:hover { background:rgba(255,255,255,0.15); }
    .nav-bell-wrap { position:relative; }
    .nav-bell { width:38px; height:38px; border-radius:50%; border:2px solid rgba(255,255,255,0.6); display:flex; align-items:center; justify-content:center; cursor:pointer; background:none; transition:background 0.2s; }
    .nav-bell:hover { background:rgba(255,255,255,0.15); }
    .bell-badge { position:absolute; top:-3px; right:-3px; width:18px; height:18px; background:#e07a1a; border-radius:50%; border:2px solid transparent; display:flex; align-items:center; justify-content:center; font-size:10px; font-weight:700; color:#fff; pointer-events:none; }
    .notif-dropdown { display:none; position:absolute; top:48px; right:0; width:320px; background:#fff; border-radius:16px; box-shadow:0 8px 40px rgba(26,58,107,0.18); border:1.5px solid #e0eaf5; z-index:9999; overflow:hidden; }
    .notif-dropdown.open { display:block; }
    .notif-header { display:flex; align-items:center; justify-content:space-between; padding:16px 18px 12px; border-bottom:1.5px solid #f0f5fc; }
    .notif-header-title { font-size:15px; font-weight:700; color:#1a3a6b; font-family:'Playfair Display',serif; }
    .notif-empty { padding:28px 18px; text-align:center; color:#b0c4d8; font-size:14px; }
    .notif-item { display:flex; align-items:flex-start; gap:12px; padding:14px 18px; border-bottom:1px solid #f5f8fc; transition:background 0.15s; }
    .notif-item:last-child { border-bottom:none; }
    .notif-item:hover { background:#f8fbff; }
    .notif-icon { width:36px; height:36px; border-radius:50%; background:#fff4e6; display:flex; align-items:center; justify-content:center; flex-shrink:0; margin-top:2px; }
    .notif-meta { font-size:12px; color:#7a8fa8; display:flex; align-items:center; gap:6px; margin-top:3px; }
    .notif-source-tag { background:#e8f0ff; color:#2255a4; border-radius:50px; padding:2px 8px; font-size:11px; font-weight:700; }
    .notif-source-tag.cart { background:#e8f7ee; color:#1a6b3a; }
    .notif-hours { color:#e07a1a; font-weight:700; }

    /* ── BACK BUTTON — matches cart.php exactly ── */
    .back-btn {
      width: 46px;
      height: 46px;
      border-radius: 50%;
      background: #cdd9e8;
      color: #1b3f92;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      line-height: 1;
      flex-shrink: 0;
      font-weight: 700;
      text-decoration: none;
      transition: background 0.2s;
      border: none;
      cursor: pointer;
      font-family: 'Playfair Display', serif;
    }
    .back-btn:hover { background: #bfcee2; }

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

    /* ── ITEM CARD — identical to landing page product-card ── */
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

    /* ── FOOTER ── */
    footer{background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);padding:28px 48px;display:flex;flex-direction:column;align-items:center;gap:14px}
    .footer-top{display:flex;align-items:center;gap:18px}
    .social-icon{width:42px;height:42px;border-radius:50%;border:1.5px solid rgba(255,255,255,0.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;font-family:'Playfair Display',serif;transition:background 0.2s}
    .social-icon:hover{background:rgba(255,255,255,0.15)}
    .footer-divider{width:1px;height:22px;background:rgba(255,255,255,0.3)}
    .footer-brand{display:flex;align-items:center;gap:8px;color:#fff;font-size:16px;font-weight:700;font-family:'Playfair Display',serif}
    .footer-email{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,0.9);font-size:14px;font-family:'Playfair Display',serif}
    .footer-bottom{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,0.7);font-size:13px;font-family:'Playfair Display',serif}
  </style>
</head>
<body>

<!-- NAVBAR -->
<nav>
  <div class="nav-left">
    <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate Logo" />
    <a href="../customer/cart.php" class="nav-cart">
      <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;" />
    </a>
  </div>
  <div class="nav-center">
    <a href="../shared/landing.php">Home Page</a>
    <a href="category.php" class="active">Categories</a>
    <a href="providers-list.php">Providers</a>
  </div>
  <div class="nav-right">
    <div class="nav-search-wrap" id="searchWrap">
      <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="11" cy="11" r="8"/>
        <path d="M21 21l-4.35-4.35"/>
      </svg>
      <input type="text" id="searchInput" placeholder="Search products or providers..." autocomplete="off"/>
      <div class="search-dropdown" id="searchDropdown"></div>
    </div>
    <div class="nav-bell-wrap">
      <button class="nav-bell" onclick="toggleNotifDropdown()">
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
          <div>
            <p style="font-size:14px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;margin-bottom:3px;"><?= htmlspecialchars($alert['name']) ?></p>
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
    <?php if ($isLoggedIn): ?>
    <a href="../customer/customer-profile.php" class="nav-avatar">
      <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
    </a>
    <?php else: ?>
    <button class="nav-avatar" onclick="document.getElementById('authModal').style.display='flex'" style="border:none;cursor:pointer;background:rgba(255,255,255,0.15);">
      <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
    </button>
    <?php endif; ?>
    <!-- Auth modal -->
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
  </div>
</nav>

<!-- ── PAGE LAYOUT ── -->
<div class="page-wrap">
  <div style="display:flex;align-items:center;gap:20px;margin-bottom:24px;grid-column:1/-1">
    <a class="back-btn" href="javascript:history.back()">‹</a>
    <h2 style="font-family:'Playfair Display',serif;font-size:32px;color:#183482;margin:0;font-weight:700"><?= htmlspecialchars($category['name'] ?? 'All Categories') ?></h2>
  </div>

  <!-- SIDEBAR: all categories -->
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
          } else if ($itemCatId) {
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
              <form method="post" style="display:inline">
                <input type="hidden" name="action" value="toggle_fav">
                <input type="hidden" name="itemId" value="<?= htmlspecialchars($itemId) ?>">
                <button class="fav-btn <?= $isSaved ? 'liked' : '' ?>" type="submit">
                  <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path class="heart-path" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </button>
              </form>
            <?php else: ?>
              <a class="fav-btn" href="../shared/login.php">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path class="heart-path" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
              </a>
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

<!-- ── FOOTER ── -->
<footer>
  <div class="footer-top">
    <div style="display:flex;align-items:center;gap:10px;">
      <a class="social-icon" href="#">in</a>
      <a class="social-icon" href="#">&#120143;</a>
      <a class="social-icon" href="#">&#9834;</a>
    </div>
    <div class="footer-divider"></div>
    <div class="footer-brand"></div>
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
  function toggleNotifDropdown() {
    document.getElementById('notifDropdown').classList.toggle('open');
  }
  const searchInput = document.getElementById('searchInput');
  const searchDropdown = document.getElementById('searchDropdown');
  const searchWrap = document.getElementById('searchWrap');
  let searchTimer = null;
  searchInput?.addEventListener('input', function() {
    clearTimeout(searchTimer);
    const q = this.value.trim();
    if (q.length < 2) { searchDropdown?.classList.remove('open'); return; }
    searchDropdown.innerHTML = '<div class="search-loading">Searching...</div>';
    searchDropdown.classList.add('open');
    searchTimer = setTimeout(async () => {
      try {
        const res = await fetch(`../../back-end/search.php?q=${encodeURIComponent(q)}`);
        const data = await res.json();
        let html = '<div class="search-section-label">Providers</div>';
        if (data.providers?.length) {
          data.providers.forEach(p => {
            const logo = p.businessLogo ? `<div class="search-provider-logo"><img src="${p.businessLogo}"/></div>` : `<div class="search-provider-logo">${p.businessName.charAt(0).toUpperCase()}</div>`;
            html += `<a class="search-item-row" href="providers-page.php?providerId=${p.id}">${logo}<div><p class="search-item-name">${p.businessName}</p><p class="search-item-sub">${p.category}</p></div></a>`;
          });
        } else { html += `<div class="search-no-match">No providers match "<em>${q}</em>"</div>`; }
        html += '<div class="search-divider"></div><div class="search-section-label">Products</div>';
        if (data.items?.length) {
          data.items.forEach(item => {
            const thumb = item.photoUrl ? `<div class="search-thumb"><img src="${item.photoUrl}"/></div>` : '<div class="search-thumb">🍱</div>';
            html += `<a class="search-item-row" href="item-details.php?itemId=${item.id}">${thumb}<div><p class="search-item-name">${item.name}</p><p class="search-item-sub">Product</p></div><span class="search-price">${item.price}</span></a>`;
          });
        } else { html += `<div class="search-no-match">No products match "<em>${q}</em>"</div>`; }
        searchDropdown.innerHTML = html;
        searchDropdown.classList.add('open');
      } catch(e) { searchDropdown.innerHTML = '<div class="search-empty">Something went wrong.</div>'; }
    }, 280);
  });
  searchInput?.addEventListener('keydown', e => { if (e.key === 'Escape') searchDropdown?.classList.remove('open'); });
  document.addEventListener('click', e => {
    if (searchWrap && !searchWrap.contains(e.target)) searchDropdown?.classList.remove('open');
    if (!document.querySelector('.nav-bell-wrap')?.contains(e.target)) document.getElementById('notifDropdown')?.classList.remove('open');
  });
</script>
</body>
</html>