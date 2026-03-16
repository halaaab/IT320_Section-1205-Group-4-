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

$items = [];
if ($categoryId) {
    $items = $itemModel->getByCategory($categoryId);
    if ($type !== 'all') {
        $items = array_values(array_filter($items, fn($i) => $i['listingType'] === $type));
    }
}

// ── EXAMPLE: Filter tabs in your HTML ──
// <a href="?categoryId=[categoryId]&type=all"    class="[type===all ? active : '']">All</a>
// <a href="?categoryId=[categoryId]&type=sell"   class="[type===sell ? active : '']">Buy</a>
// <a href="?categoryId=[categoryId]&type=donate" class="[type===donate ? active : '']">Free</a>
//
// ── EXAMPLE: Item cards loop ──
// foreach ($items as $item):
//   <a href="item-details.php?itemId=[item._id]">
//     <h3>[item.itemName]</h3>
//     <p>[item.listingType===donate ? Free : item.price.' SAR']</p>
//   </a>
// endforeach

// ── Added: provider model for item cards + session info ──
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Favourite.php';

$isLoggedIn   = !empty($_SESSION['customerId']);
$customerId   = $_SESSION['customerId'] ?? null;

$savedIds = [];
if ($isLoggedIn) {
    $favs     = (new Favourite())->getByCustomer($customerId);
    $savedIds = array_map(fn($f) => (string)$f['itemId'], $favs);
}
$providerModel = new Provider();
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
    nav.navbar{display:flex;align-items:center;justify-content:space-between;padding:0 40px;height:72px;background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(26,58,107,0.18)}
    .nav-logo{height:100px}
    .nav-left{display:flex;align-items:center;gap:16px}
    .nav-cart{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,0.7);display:flex;align-items:center;justify-content:center;text-decoration:none;transition:background 0.2s}
    .nav-cart:hover{background:rgba(255,255,255,0.15)}
    .nav-center{display:flex;align-items:center;gap:40px}
    .nav-center a{color:rgba(255,255,255,0.85);text-decoration:none;font-weight:500;font-size:15px;font-family:'Playfair Display',serif;transition:color 0.2s}
    .nav-center a:hover,.nav-center a.active{color:#fff}
    .nav-right{display:flex;align-items:center;gap:12px}
    .btn-signup{background:#fff;color:#1a3a6b;border:none;border-radius:50px;padding:8px 22px;font-weight:700;font-size:14px;font-family:'Playfair Display',serif;cursor:pointer}
    .btn-login{background:transparent;color:#fff;border:2px solid #fff;border-radius:50px;padding:6px 22px;font-weight:700;font-size:14px;font-family:'Playfair Display',serif;cursor:pointer}
    .nav-search-wrap{position:relative}
    .nav-search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:0.6;pointer-events:none}
    .nav-search-wrap input{background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.4);border-radius:50px;padding:9px 16px 9px 36px;color:#fff;font-size:14px;outline:none;width:220px;font-family:'Playfair Display',serif}
    .nav-search-wrap input::placeholder{color:rgba(255,255,255,0.6)}
    .nav-avatar{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,0.6);display:flex;align-items:center;justify-content:center;text-decoration:none;background:rgba(255,255,255,0.15)}
    .nav-avatar:hover{background:rgba(255,255,255,0.25)}

    /* ── BACK BUTTON ── */
    .back-btn{position:absolute;top:50%;left:24px;transform:translateY(-50%);width:42px;height:42px;border-radius:50%;background:rgba(255,255,255,0.2);border:2px solid rgba(255,255,255,0.6);display:flex;align-items:center;justify-content:center;color:#fff;font-size:22px;font-weight:700;cursor:pointer;text-decoration:none;transition:background 0.2s;z-index:2}
    .back-btn:hover{background:rgba(255,255,255,0.35)}
    .hero-banner{background:linear-gradient(90deg,#1a3a6b 0%,#2a5db5 50%,#6aaee8 100%);min-height:170px;display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
    .hero-banner::before{content:'';position:absolute;inset:0;background:url('../../images/category-banner.png') center/cover no-repeat;opacity:0.15}
    .hero-banner h1{font-family:'Playfair Display',serif;font-size:64px;color:#fff;position:relative;z-index:1;letter-spacing:-1px;text-shadow:0 2px 16px rgba(26,58,107,0.2)}

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
    .items-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:48px}
    @media(max-width:1100px){.items-grid{grid-template-columns:repeat(2,1fr)}}
    @media(max-width:600px){.items-grid{grid-template-columns:1fr}}

    /* ── ITEM CARD ── */
    .item-card{background:#fff;border:1.5px solid #dce7f5;border-radius:20px;overflow:hidden;box-shadow:0 4px 14px rgba(26,58,107,0.07);transition:transform 0.2s,box-shadow 0.2s;display:flex;flex-direction:column}
    .item-card:hover{transform:translateY(-4px);box-shadow:0 12px 30px rgba(26,58,107,0.13)}
    .card-top{position:relative;padding:14px 14px 0}
    .prov-logo-sm{position:absolute;top:14px;left:14px;height:32px;max-width:90px;object-fit:contain}
    .prov-name-sm{position:absolute;top:14px;left:14px;font-size:12px;font-weight:700;color:#7a8fa8;font-style:italic}
    .fav-btn{position:absolute;top:10px;right:10px;width:34px;height:34px;border-radius:50%;border:none;background:transparent;cursor:pointer;display:grid;place-items:center;font-size:22px;color:#e04040;transition:transform 0.2s;z-index:2}
    .fav-btn:hover{transform:scale(1.2)}
    .item-img{width:100%;height:175px;object-fit:contain;margin-top:8px;padding:8px;border-radius:14px;background:#f8fbff}
    .item-img-ph{width:100%;height:175px;background:linear-gradient(135deg,#e8f0ff,#dce7f5);border-radius:14px;margin-top:8px;display:grid;place-items:center;color:#7a8fa8;font-size:13px}
    .card-body{padding:12px 14px 16px;flex:1;display:flex;flex-direction:column}
    .name-row{display:flex;align-items:baseline;justify-content:space-between;gap:6px;margin-bottom:4px}
    .item-name{font-weight:700;font-size:15px;color:#1a2a45}
    .item-price{font-weight:700;font-size:15px;color:#e07a1a;white-space:nowrap}
    .price-free{color:#1a6b3a}
    .item-desc{font-size:13px;color:#7a8fa8;line-height:1.5;margin-bottom:12px;flex:1;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
    .view-btn{display:inline-block;background:#1a3a6b;color:#fff;border-radius:50px;padding:8px 20px;font-weight:700;font-size:13px;text-align:center;transition:background 0.2s;align-self:flex-start}
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

<!-- ── NAVBAR ── -->
<nav class="navbar">
  <div class="nav-left">
    <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
    <a href="../customer/cart.php" class="nav-cart">
      <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;"/>
    </a>
  </div>
  <div class="nav-center">
    <a href="../shared/landing.php">Home Page</a>
    <a href="category.php" class="active">Categories</a>
    <a href="providers-list.php">Providers</a>
  </div>
  <div class="nav-right">
    <?php if ($isLoggedIn): ?>
      <a href="customer-profile.php" class="nav-avatar">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
    <?php else: ?>
      <a href="../shared/signup-customer.php"><button class="btn-signup">Sign up</button></a>
      <a href="../shared/login.php"><button class="btn-login">Log in</button></a>
      <div class="nav-search-wrap">
        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" placeholder="Search.....">
      </div>
      <a href="customer-profile.php" class="nav-avatar">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
    <?php endif; ?>
  </div>
</nav>

<!-- ── HERO BANNER ── -->
<div class="hero-banner">
  <a class="back-btn" href="javascript:history.back()" title="Go back">&#8249;</a>
  <h1><?= htmlspecialchars($category['name'] ?? 'Categories') ?></h1>
</div>

<!-- ── PAGE LAYOUT ── -->
<div class="page-wrap">

  <!-- SIDEBAR: all categories -->
  <?php
  // Same image map used in landing.php — keyed by lowercase category name
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
          <p>Try selecting a category or a different filter.</p>
        </div>
      <?php else: ?>
        <?php foreach ($items as $item):
          $itemId   = (string)$item['_id'];
          $isFree   = ($item['listingType'] ?? '') === 'donate';
          $isSaved  = in_array($itemId, $savedIds, true);
          $prov     = !empty($item['providerId']) ? $providerModel->findById((string)$item['providerId']) : null;
          $provName = $prov['businessName'] ?? '';
          $provLogo = $prov['businessLogo'] ?? '';
        ?>
        <div class="item-card">
          <div class="card-top">
            <?php if ($provLogo): ?>
              <img class="prov-logo-sm" src="<?= htmlspecialchars($provLogo) ?>" alt="<?= htmlspecialchars($provName) ?>">
            <?php else: ?>
              <span class="prov-name-sm"><?= htmlspecialchars($provName) ?></span>
            <?php endif; ?>

            <?php if ($isLoggedIn): ?>
              <form method="post" action="item-details.php?itemId=<?= urlencode($itemId) ?>" style="display:inline">
                <input type="hidden" name="action" value="toggle_fav">
                <button class="fav-btn" type="submit"><?= $isSaved ? '❤️' : '🤍' ?></button>
              </form>
            <?php else: ?>
              <a class="fav-btn" href="../shared/login.php">🤍</a>
            <?php endif; ?>

            <?php if (!empty($item['photoUrl'])): ?>
              <img class="item-img" src="<?= htmlspecialchars($item['photoUrl']) ?>" alt="<?= htmlspecialchars($item['itemName'] ?? '') ?>">
            <?php else: ?>
              <div class="item-img-ph">No image</div>
            <?php endif; ?>
          </div>

          <div class="card-body">
            <div class="name-row">
              <span class="item-name"><?= htmlspecialchars($item['itemName'] ?? 'Item') ?></span>
              <?php if ($isFree): ?>
                <span class="item-price price-free">Free</span>
              <?php else: ?>
                <span class="item-price"><?= number_format((float)($item['price'] ?? 0), 2) ?> ﷼</span>
              <?php endif; ?>
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

</body>
</html>
