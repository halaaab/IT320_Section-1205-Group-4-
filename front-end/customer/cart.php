<?php
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Customer.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/PickupLocation.php';
require_once '../../back-end/models/Order.php';
require_once '../../back-end/models/OrderItem.php';
require_once '../../back-end/models/SupportTicket.php';
require_once '../../back-end/models/Notification.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId = $_SESSION['customerId'];
$customer = (new Customer())->findById($customerId);
$firstName = explode(' ', trim($customer['fullName'] ?? ($_SESSION['userName'] ?? 'Customer')))[0] ?: 'Customer';

function rp_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rp_oid($v){ return is_object($v) ? (string)$v : (string)$v; }
function rp_dt($dt, $fmt='j F Y  g:ia'){
    if ($dt instanceof MongoDB\BSON\UTCDateTime) return $dt->toDateTime()->format($fmt);
    if (is_numeric($dt)) return date($fmt, (int)$dt);
    if ($dt) return date($fmt, strtotime((string)$dt));
    return '';
}
function rp_money($n){ return number_format((float)$n, 2); }

function rp_footer(){ ?>
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
      <svg width="16" height="16" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
      <span>Replate@gmail.com</span>
    </div>
  </div>
  <div class="footer-bottom">
    <span>© 2026</span>
    <img src="../../images/Replate-white.png" alt="" style="height:15px;object-fit:contain;opacity:0.8;"/>
    <span>All rights reserved.</span>
  </div>
</footer>
<?php }

function rp_top_header($active='') { ?>
<nav>
  <div class="nav-left">
    <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate Logo" />
    <a href="../customer/cart.php" class="nav-cart">
      <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;" />
    </a>
  </div>
  <div class="nav-center">
    <a href="../shared/landing.php" class="<?= $active==='home'?'active':'' ?>">Home Page</a>
    <a href="category.php" class="<?= $active==='categories'?'active':'' ?>">Categories</a>
    <a href="providers-list.php" class="<?= $active==='providers'?'active':'' ?>">Providers</a>
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
      <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-header">
          <span class="notif-header-title">Notifications</span>
          <span style="font-size:12px;color:#b0c4d8;">0 alerts</span>
        </div>
        <div class="notif-empty">No notifications right now</div>
      </div>
    </div>
    <a href="customer-profile.php" class="nav-avatar">
      <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/>
      </svg>
    </a>
  </div>
</nav>
<?php }

function rp_page_styles(){ ?>
<style>
*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{background:#e8eef5;color:#1b2f74;font-family:'Playfair Display',serif}
a{text-decoration:none}

/* ── NAVBAR ── */
nav{display:flex;align-items:center;justify-content:space-between;padding:0 48px;height:72px;background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(26,58,107,0.18)}
.nav-left{display:flex;align-items:center;gap:16px}
.nav-logo{height:100px}
.nav-cart{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,0.7);display:flex;justify-content:center;align-items:center;cursor:pointer;transition:background .2s;text-decoration:none}
.nav-cart:hover{background:rgba(255,255,255,0.15)}
.nav-center{display:flex;align-items:center;gap:40px}
.nav-center a{color:rgba(255,255,255,0.85);text-decoration:none;font-weight:500;font-size:15px;transition:color .2s}
.nav-center a:hover,.nav-center a.active{color:#fff}
.nav-center a.active{font-weight:600;border-bottom:2px solid #fff;padding-bottom:2px}
.nav-right{display:flex;align-items:center;gap:12px}
.nav-search-wrap{position:relative}
.nav-search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.6;pointer-events:none}
.nav-search-wrap input{background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.4);border-radius:50px;padding:9px 16px 9px 36px;color:#fff;font-size:14px;outline:none;width:240px;font-family:'Playfair Display',serif;transition:width .3s,background .2s}
.nav-search-wrap input::placeholder{color:rgba(255,255,255,0.6)}
.nav-search-wrap input:focus{width:300px;background:rgba(255,255,255,0.25)}
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
.search-empty{padding:24px 16px;text-align:center;color:#b0c4d8;font-size:14px;font-family:'Playfair Display',serif}
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

/* ── PAGE LAYOUT ── */
.page-wrap{max-width:860px;margin:0 auto;padding:28px 20px 60px}
.page-title-row{display:flex;align-items:center;gap:20px;margin:0 0 28px}
.back-btn{width:46px;height:46px;border-radius:50%;background:#cdd9e8;color:#1b3f92;display:flex;align-items:center;justify-content:center;font-size:28px;line-height:1;flex-shrink:0;font-weight:700}
.back-btn:hover{background:#bfcee2}
.page-title{font-size:62px;line-height:.95;margin:0;color:#183482;font-weight:700}

/* ── PROVIDER BLOCK ── */
.provider-block{background:#fff;border:1.8px solid #d2dce8;border-radius:28px;padding:24px 26px;margin-bottom:24px;box-shadow:0 2px 12px rgba(26,58,107,.06)}
.provider-logo-text{font-size:36px;font-weight:700;color:#c85a3a;letter-spacing:1px;text-transform:uppercase;margin-bottom:16px;font-family:'Playfair Display',serif}

/* ── ITEM CARD ── */
.provider-card{background:#f5f8fc;border:1.6px solid #d2dce8;border-radius:20px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;margin:10px 0}
.item-left{display:flex;align-items:center;gap:14px;min-width:0;flex:1}
.item-thumb{width:80px;height:70px;border-radius:16px;background:#e8eef5;border:1.4px solid #d2dce8;object-fit:cover;display:block;flex-shrink:0}
.item-meta{min-width:0}
.item-meta h3{margin:0 0 4px;color:#183482;font-size:22px;font-weight:700}
.item-meta .price{color:#aaa8b3;font-size:17px}
.price-rial{color:#c87a30;font-size:15px;margin-right:2px}

/* ── RIGHT CONTROLS ── */
.item-right{display:flex;flex-direction:column;align-items:flex-end;gap:8px;flex-shrink:0}
.remove-btn{border:none;background:none;color:#9ab1d8;font-size:22px;cursor:pointer;padding:0;line-height:1}
.remove-btn:hover{color:#6a8ab8}
.qty-controls{display:flex;align-items:center;gap:14px;color:#eb8b24;font-size:22px;font-weight:700}
.qty-btn{border:none;background:none;color:#eb8b24;font-size:26px;cursor:pointer;font-family:inherit;padding:0 2px;line-height:1}
.qty-btn:hover{color:#c96e10}
.qty-count{font-size:22px;color:#1b2f74;min-width:18px;text-align:center}

/* ── CHECKOUT BUTTON ── */
.checkout-wrap{display:flex;justify-content:center;margin-top:32px}
.primary-cta{display:block;width:min(420px,100%);background:#f6811f;color:#fff;border:none;border-radius:24px;padding:20px 28px;font-size:32px;font-family:'Playfair Display',serif;cursor:pointer;text-align:center;text-decoration:none;font-weight:400;transition:background .2s}
.primary-cta:hover{background:#e07010}

/* ── FOOTER ── */
footer{background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);padding:28px 48px;display:flex;flex-direction:column;align-items:center;gap:14px;margin-top:40px}
.footer-top{display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:center}
.social-icon{width:42px;height:42px;border-radius:50%;border:1.5px solid rgba(255,255,255,.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;font-family:'Playfair Display',serif;transition:background .2s}
.social-icon:hover{background:rgba(255,255,255,.15)}
.footer-divider{width:1px;height:22px;background:rgba(255,255,255,.3)}
.footer-brand{display:flex;align-items:center;gap:8px;color:#fff;font-size:16px;font-weight:700;font-family:'Playfair Display',serif}
.footer-email{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.9);font-size:14px;font-family:'Playfair Display',serif}
.footer-bottom{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.7);font-size:13px;font-family:'Playfair Display',serif;flex-wrap:wrap;justify-content:center}

@media(max-width:700px){
  .page-title{font-size:42px}
  nav{padding:0 18px}
  .nav-logo{height:74px}
  .nav-search-wrap input{width:160px}
  .nav-search-wrap input:focus{width:190px}
  .nav-center{display:none}
  .primary-cta{font-size:24px}
  footer{padding:24px 18px}
}
</style>
<?php }
?>
<?php
$cartModel = new Cart();
$itemModel = new Item();
$providerModel = new Provider();
$pickupLocationModel = new PickupLocation();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $action = $_POST['action'] ?? '';
    $itemId = $_POST['itemId'] ?? '';
    if ($action==='inc' && $itemId) {
        $cart = $cartModel->getOrCreate($customerId);
        foreach (($cart['cartItems'] ?? []) as $ci) if ((string)$ci['itemId']===$itemId) { $cartModel->updateQuantity($customerId, $itemId, (int)$ci['quantity'] + 1); break; }
    }
    if ($action==='dec' && $itemId) {
        $cart = $cartModel->getOrCreate($customerId);
        foreach (($cart['cartItems'] ?? []) as $ci) if ((string)$ci['itemId']===$itemId) { $q=(int)$ci['quantity'] - 1; $q>0 ? $cartModel->updateQuantity($customerId,$itemId,$q) : $cartModel->removeItem($customerId,$itemId); break; }
    }
    if ($action==='remove' && $itemId) $cartModel->removeItem($customerId, $itemId);
    header('Location: cart.php'); exit;
}
$cart = $cartModel->getOrCreate($customerId);
$grouped = [];
$total = 0;
foreach (($cart['cartItems'] ?? []) as $ci) {
    $providerId = rp_oid($ci['providerId']);
    $provider = $providerModel->findById($providerId);
    $item = $itemModel->findById(rp_oid($ci['itemId']));
    $logoTxt = strtoupper($provider['businessName'] ?? 'Provider');
    $location = $pickupLocationModel->getDefault($providerId);
    $street = trim((string)($location['street'] ?? ''));
    $city = trim((string)($location['city'] ?? ''));
    $zip = trim((string)($location['zip'] ?? ''));
    $label = trim((string)($location['label'] ?? 'Main Branch'));
    $locationText = trim(implode(', ', array_filter([$street, $city, $zip])));
    $lat = $location['coordinates']['lat'] ?? null;
    $lng = $location['coordinates']['lng'] ?? null;
    $grouped[$providerId]['provider'] = $provider;
    $grouped[$providerId]['logoTxt'] = $logoTxt;
    $grouped[$providerId]['location'] = ['label'=>$label,'text'=>$locationText,'lat'=>$lat,'lng'=>$lng];
    $grouped[$providerId]['items'][] = ['cart'=>$ci, 'item'=>$item];
    $total += (float)$ci['price'] * (int)$ci['quantity'];
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cart – RePlate</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
  <?php rp_page_styles(); ?>
</head>
<body>
<?php rp_top_header(); ?>

<div class="page-wrap">
  <div class="page-title-row">
    <a class="back-btn" href="../shared/landing.php">‹</a>
    <h1 class="page-title">Cart</h1>
  </div>

  <?php if (!$grouped): ?>
    <div style="text-align:center;padding:60px 12px;color:#6d7da0;font-size:26px;">Your cart is empty.</div>
  <?php else: ?>

    <?php foreach ($grouped as $providerId => $g): ?>
    <div class="provider-block">
      <div class="provider-logo-text"><?= rp_h($g['logoTxt']) ?></div>

      <?php foreach ($g['items'] as $row): $ci=$row['cart']; $item=$row['item']; ?>
      <div class="provider-card">
        <div class="item-left">
          <?php if (!empty($item['photoUrl'])): ?>
            <img class="item-thumb" src="<?= rp_h($item['photoUrl']) ?>" alt="<?= rp_h($ci['itemName']) ?>">
          <?php else: ?>
            <div class="item-thumb"></div>
          <?php endif; ?>
          <div class="item-meta">
            <h3><?= rp_h($ci['itemName']) ?></h3>
            <div class="price"><span class="price-rial">﷼</span><?= rp_money($ci['price']) ?></div>
          </div>
        </div>

        <div class="item-right">
          <form method="post" style="margin:0">
            <input type="hidden" name="action" value="remove">
            <input type="hidden" name="itemId" value="<?= rp_h(rp_oid($ci['itemId'])) ?>">
            <button class="remove-btn" title="Remove">🗑</button>
          </form>
          <div class="qty-controls">
            <form method="post" style="margin:0">
              <input type="hidden" name="action" value="inc">
              <input type="hidden" name="itemId" value="<?= rp_h(rp_oid($ci['itemId'])) ?>">
              <button class="qty-btn">+</button>
            </form>
            <span class="qty-count"><?= (int)$ci['quantity'] ?></span>
            <form method="post" style="margin:0">
              <input type="hidden" name="action" value="dec">
              <input type="hidden" name="itemId" value="<?= rp_h(rp_oid($ci['itemId'])) ?>">
              <button class="qty-btn">−</button>
            </form>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <div class="checkout-wrap">
      <a class="primary-cta" href="checkout.php">Check out</a>
    </div>

  <?php endif; ?>
</div>

<?php rp_footer(); ?>

<script>
function toggleNotifDropdown(){
  const el = document.getElementById('notifDropdown');
  if(el) el.classList.toggle('open');
}
(function(){
  const input = document.getElementById('searchInput');
  const dropdown = document.getElementById('searchDropdown');
  const wrap = document.getElementById('searchWrap');
  if(!input || !dropdown || !wrap) return;
  let timer = null;
  function render(data){
    const items = Array.isArray(data.items) ? data.items : [];
    const providers = Array.isArray(data.providers) ? data.providers : [];
    if(!items.length && !providers.length){
      dropdown.innerHTML = '<div class="search-empty">No matches found</div>';
      dropdown.classList.add('open'); return;
    }
    let html = '';
    if(items.length){
      html += '<div class="search-section-label">Items</div>';
      items.forEach(item => {
        const thumb = item.photoUrl ? `<div class="search-thumb"><img src="${item.photoUrl}" alt=""></div>` : '<div class="search-thumb">🛍</div>';
        html += `<a class="search-item-row" href="item-details.php?id=${item.id}">${thumb}<div><div class="search-item-name">${item.name}</div><div class="search-item-sub">${item.listingType || ''}</div></div><div class="search-price">${item.price || ''}</div></a>`;
      });
    }
    if(providers.length){
      if(items.length) html += '<div class="search-divider"></div>';
      html += '<div class="search-section-label">Providers</div>';
      providers.forEach(p => {
        const logo = p.businessLogo ? `<div class="search-provider-logo"><img src="${p.businessLogo}" alt=""></div>` : `<div class="search-provider-logo">${(p.businessName||'P').charAt(0)}</div>`;
        html += `<a class="search-item-row" href="providers-list.php">${logo}<div><div class="search-item-name">${p.businessName || ''}</div><div class="search-item-sub">${p.category || ''}</div></div></a>`;
      });
    }
    dropdown.innerHTML = html;
    dropdown.classList.add('open');
  }
  input.addEventListener('input', function(){
    const q = this.value.trim();
    clearTimeout(timer);
    if(q.length < 2){ dropdown.classList.remove('open'); dropdown.innerHTML=''; return; }
    dropdown.innerHTML = '<div class="search-loading">Searching...</div>';
    dropdown.classList.add('open');
    timer = setTimeout(() => {
      fetch('../../back-end/search.php?q=' + encodeURIComponent(q))
        .then(r => r.json()).then(render)
        .catch(() => { dropdown.innerHTML = '<div class="search-empty">Search unavailable</div>'; dropdown.classList.add('open'); });
    }, 220);
  });
  document.addEventListener('click', function(e){
    const notif = document.getElementById('notifDropdown');
    const bellWrap = document.querySelector('.nav-bell-wrap');
    if(notif && bellWrap && !bellWrap.contains(e.target)) notif.classList.remove('open');
    if(!wrap.contains(e.target)) dropdown.classList.remove('open');
  });
})();
</script>
</body>
</html>
