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
require_once '../../back-end/models/Favourite.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId = $_SESSION['customerId'];
$customer = (new Customer())->findById($customerId);
$firstName = explode(' ', trim($customer['fullName'] ?? ($_SESSION['userName'] ?? 'Customer')))[0] ?: 'Customer';

// ── Expiry alerts ──
$expiryAlerts = []; $alertCount = 0;
$_now = time(); $_soon = $_now + 48*3600;
$_fc = new Cart(); $_fc2 = $_fc->getOrCreate($customerId);
$_cids = array_map(fn($ci) => (string)$ci['itemId'], (array)($_fc2['cartItems'] ?? []));
$_fv = new Favourite(); $_fvs = $_fv->getByCustomer($customerId);
$_fids = array_map(fn($f) => (string)$f['itemId'], $_fvs);
$_wids = array_unique(array_merge($_cids, $_fids));
$_im2 = new Item();
foreach ($_wids as $_wid) {
    try {
        $_wi = $_im2->findById($_wid);
        if (!$_wi || !isset($_wi['expiryDate'])) continue;
        $_exp = $_wi['expiryDate']->toDateTime()->getTimestamp();
        if ($_exp >= $_now && $_exp <= $_soon) {
            $expiryAlerts[] = ['name'=>$_wi['itemName']??'Item','hoursLeft'=>ceil(($_exp-$_now)/3600),'source'=>in_array($_wid,$_cids)?'cart':'favourites'];
        }
    } catch (Throwable) { continue; }
}
$alertCount = count($expiryAlerts);

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
    <a href="../shared/landing.php#categories" class="<?= $active==='categories'?'active':'' ?>">Categories</a>
    <a href="../shared/landing.php#providers" class="<?= $active==='providers'?'active':'' ?>">Providers</a>
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
      <?php if ($alertCount > 0): ?><span class="bell-badge"><?= $alertCount ?></span><?php endif; ?>
      <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-header">
          <span class="notif-header-title">&#x23F0; Expiring Soon</span>
          <span style="font-size:12px;color:#b0c4d8;"><?= $alertCount ?> alert<?= $alertCount!==1?'s':'' ?></span>
        </div>
        <?php if (empty($expiryAlerts)): ?>
        <div class="notif-empty">
          <svg width="32" height="32" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
          No expiry alerts right now
        </div>
        <?php else: ?>
        <?php foreach ($expiryAlerts as $alert): ?>
        <div class="notif-item">
          <div class="notif-icon"><svg width="16" height="16" fill="none" stroke="#e07a1a" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
          <div class="notif-text">
            <p class="notif-name"><?= rp_h($alert['name']) ?></p>
            <div class="notif-meta">
              <span class="notif-hours">&#x23F3; <?= $alert['hoursLeft'] ?>h left</span>
              <span class="notif-source-tag <?= $alert['source']==="cart"?"cart":"" ?>"><?= $alert['source']==="cart"?"&#x1F6D2; Cart":"&#x2665; Favourites" ?></span>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
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

/* ── NAV ── */
nav{display:flex;align-items:center;justify-content:space-between;padding:0 48px;height:72px;background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(26,58,107,.18)}
.nav-left{display:flex;align-items:center;gap:16px}
.nav-logo{height:100px}
.nav-cart{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,.7);display:flex;justify-content:center;align-items:center;cursor:pointer;text-decoration:none;transition:background .2s}
.nav-cart:hover{background:rgba(255,255,255,.15)}
.nav-center{display:flex;align-items:center;gap:40px}
.nav-center a{color:rgba(255,255,255,.85);text-decoration:none;font-weight:500;font-size:15px;transition:color .2s}
.nav-center a:hover,.nav-center a.active{color:#fff}
.nav-center a.active{font-weight:600;border-bottom:2px solid #fff;padding-bottom:2px}
.nav-right{display:flex;align-items:center;gap:12px}
.nav-search-wrap{position:relative}
.nav-search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.6;pointer-events:none}
.nav-search-wrap input{background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.4);border-radius:50px;padding:9px 16px 9px 36px;color:#fff;font-size:14px;outline:none;width:240px;font-family:'Playfair Display',serif;transition:width .3s,background .2s}
.nav-search-wrap input::placeholder{color:rgba(255,255,255,.6)}
.nav-search-wrap input:focus{width:300px;background:rgba(255,255,255,.25)}
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
.bell-badge{position:absolute;top:-3px;right:-3px;width:18px;height:18px;background:#e07a1a;border-radius:50%;border:2px solid transparent;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none}
.notif-item{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-bottom:1px solid #f5f8fc;transition:background .15s}
.notif-item:last-child{border-bottom:none}
.notif-item:hover{background:#f8fbff}
.notif-icon{width:36px;height:36px;border-radius:50%;background:#fff4e6;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
.notif-text{flex:1}
.notif-name{font-size:14px;font-weight:700;color:#1a3a6b;margin-bottom:3px}
.notif-meta{font-size:12px;color:#7a8fa8;display:flex;align-items:center;gap:6px}
.notif-source-tag{background:#e8f0ff;color:#2255a4;border-radius:50px;padding:2px 8px;font-size:11px;font-weight:700}
.notif-source-tag.cart{background:#e8f7ee;color:#1a6b3a}
.notif-hours{color:#e07a1a;font-weight:700}
.notif-dropdown{display:none;position:absolute;top:48px;right:0;width:320px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(26,58,107,.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden}
.notif-dropdown.open{display:block}
.notif-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1.5px solid #f0f5fc}
.notif-header-title{font-size:15px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif}
.notif-empty{padding:28px 18px;text-align:center;color:#b0c4d8;font-size:14px}

/* ── PAGE LAYOUT ── */
.page-wrap{max-width:860px;margin:0 auto;padding:28px 20px 60px}
.page-title-row{display:flex;align-items:center;gap:20px;margin:0 0 28px}
.back-btn{width:46px;height:46px;border-radius:50%;background:#cdd9e8;color:#1b3f92;display:flex;align-items:center;justify-content:center;font-size:28px;line-height:1;flex-shrink:0;font-weight:700;text-decoration:none}
.back-btn:hover{background:#bfcee2}
.page-title{font-size:42px;line-height:1.05;margin:0;color:#183482;font-weight:700}

/* ── DETAIL CARD ── */
.detail-card{background:#fff;border:1.8px solid #d2dce8;border-radius:28px;padding:26px;box-shadow:0 2px 12px rgba(26,58,107,.06)}

/* Provider header row */
.provider-header{display:flex;align-items:center;gap:20px;margin-bottom:28px}
.prov-logo-box{width:140px;height:110px;border-radius:20px;border:1.4px solid #d2dce8;background:#fff;display:flex;align-items:center;justify-content:center;padding:10px;flex-shrink:0}
.prov-logo-text{font-size:28px;font-weight:700;color:#c85a3a;text-transform:uppercase;text-align:center;line-height:1.1;font-family:'Playfair Display',serif}
.prov-info{display:flex;flex-direction:column;gap:10px}
.prov-name{font-size:40px;font-weight:700;color:#183482;line-height:1;font-family:'Playfair Display',serif}
.status-badge{display:inline-flex;align-items:center;padding:8px 22px;border-radius:8px;font-size:16px;font-weight:700;background:#fef3cd;color:#8b6a00;border:1px solid #f5d86e}

/* Item card */
.detail-item{background:#f5f8fc;border:1.6px solid #d2dce8;border-radius:20px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:12px}
.item-left{display:flex;align-items:flex-start;gap:14px;flex:1;min-width:0}
.item-thumb{width:110px;height:90px;border-radius:16px;background:#e8eef5;border:1.4px solid #d2dce8;object-fit:cover;flex-shrink:0;display:block}
.item-text h4{margin:0 0 4px;font-size:20px;font-weight:700;color:#183482}
.item-text p{margin:0 0 8px;font-size:14px;color:#4d6186;line-height:1.45}
.item-qty{font-size:16px;font-weight:700;color:#183482}
.item-price{color:#ea8b2c;font-size:22px;font-weight:700;flex-shrink:0}
.rial{font-size:15px;margin-right:2px}

/* Meta section */
.detail-meta{margin-top:20px;font-size:20px;color:#183482;line-height:2.1}
.detail-meta strong{font-weight:700}
.meta-amount{color:#ea8b2c;font-weight:700}

/* Modal */
.modal-backdrop{position:fixed;inset:0;background:rgba(237,242,247,.72);display:flex;align-items:center;justify-content:center;z-index:200}
.modal{background:#fff;border:1.6px solid #bcc8d8;border-radius:22px;box-shadow:0 10px 30px rgba(0,0,0,.06)}
.success-modal{width:min(470px,92vw);padding:80px 24px;text-align:center;font-size:26px;font-weight:700;color:#3e62b6}

/* ── FOOTER ── */
footer{background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);padding:28px 48px;display:flex;flex-direction:column;align-items:center;gap:14px;margin-top:40px}
.footer-top{display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:center}
.social-icon{width:42px;height:42px;border-radius:50%;border:1.5px solid rgba(255,255,255,.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s}
.social-icon:hover{background:rgba(255,255,255,.15)}
.footer-divider{width:1px;height:22px;background:rgba(255,255,255,.3)}
.footer-brand{display:flex;align-items:center;gap:8px;color:#fff;font-size:16px;font-weight:700}
.footer-email{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.9);font-size:14px}
.footer-bottom{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.7);font-size:13px;flex-wrap:wrap;justify-content:center}

@media(max-width:700px){
  .page-title{font-size:28px}
  .provider-header{flex-direction:column;align-items:flex-start}
  .prov-name{font-size:28px}
  .detail-item{flex-direction:column;align-items:flex-start}
  nav{padding:0 18px}
  .nav-center{display:none}
  footer{padding:24px 18px}
}
</style>
<?php }
?>
<?php
$orderId = $_GET['orderId'] ?? '';
$order = $orderId ? (new Order())->findById($orderId) : null;
if (!$order || rp_oid($order['customerId'] ?? '') !== $customerId) { header('Location: orders.php'); exit; }
$orderItems = (new OrderItem())->getByOrder($orderId);
$providerName = $orderItems[0]['providerName'] ?? 'Store';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Details – RePlate</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
  <?php rp_page_styles(); ?>
</head>
<body>
<?php rp_top_header(); ?>

<div class="page-wrap">
  <div class="page-title-row">
    <a class="back-btn" href="orders.php">‹</a>
    <h1 class="page-title">Order number:<?= rp_h($order['orderNumber'] ?? '') ?></h1>
  </div>

  <div class="detail-card">
    <!-- Provider header -->
    <div class="provider-header">
      <div class="prov-logo-box">
        <div class="prov-logo-text"><?= rp_h(strtoupper($providerName)) ?></div>
      </div>
      <div class="prov-info">
        <div class="prov-name"><?= rp_h(strtoupper($providerName)) ?></div>
        <div class="status-badge"><?= ucfirst(rp_h($order['orderStatus'] ?? 'pending')) ?></div>
      </div>
    </div>

    <!-- Items -->
    <?php foreach ($orderItems as $item): ?>
    <div class="detail-item">
      <div class="item-left">
        <?php if (!empty($item['photoUrl'])): ?>
          <img class="item-thumb" src="<?= rp_h($item['photoUrl']) ?>" alt="<?= rp_h($item['itemName']) ?>">
        <?php else: ?>
          <div class="item-thumb"></div>
        <?php endif; ?>
        <div class="item-text">
          <h4><?= rp_h($item['itemName']) ?></h4>
          <?php if (!empty($item['description'])): ?>
            <p><?= rp_h($item['description']) ?></p>
          <?php endif; ?>
          <div class="item-qty">Quantity:<?= (int)($item['quantity'] ?? 1) ?></div>
        </div>
      </div>
      <div class="item-price"><span class="rial">﷼</span><?= rp_money($item['price'] ?? 0) ?></div>
    </div>
    <?php endforeach; ?>

    <!-- Meta -->
    <div class="detail-meta">
      <div><strong>Total Amount :</strong> <span class="meta-amount">﷼<?= rp_money($order['totalAmount'] ?? 0) ?></span></div>
      <div><strong>Payment method :</strong> Cash 💵</div>
      <div><strong>Pickup time :</strong> <?= rp_h($orderItems[0]['selectedPickupTime'] ?? rp_dt($order['placedAt'])) ?></div>
      <div><strong>Pickup location :</strong> <?= rp_h($orderItems[0]['pickupLocation'] ?? '') ?></div>
    </div>
  </div>
</div>

<?php if (isset($_GET['new'])): ?>
<div class="modal-backdrop" onclick="this.style.display='none'">
  <div class="modal success-modal">Order placed successfully!</div>
</div>
<?php endif; ?>

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
  if(!input||!dropdown||!wrap) return;
  let timer = null;
  function render(data){
    const items = Array.isArray(data.items)?data.items:[];
    const providers = Array.isArray(data.providers)?data.providers:[];
    if(!items.length&&!providers.length){ dropdown.innerHTML='<div class="search-empty">No matches found</div>'; dropdown.classList.add('open'); return; }
    let html='';
    if(items.length){ html+='<div class="search-section-label">Items</div>'; items.forEach(item=>{ const thumb=item.photoUrl?`<div class="search-thumb"><img src="${item.photoUrl}" alt=""></div>`:'<div class="search-thumb">🛍</div>'; html+=`<a class="search-item-row" href="item-details.php?id=${item.id}">${thumb}<div><div class="search-item-name">${item.name}</div><div class="search-item-sub">${item.listingType||''}</div></div><div class="search-price">${item.price||''}</div></a>`; }); }
    if(providers.length){ if(items.length) html+='<div class="search-divider"></div>'; html+='<div class="search-section-label">Providers</div>'; providers.forEach(p=>{ const logo=p.businessLogo?`<div class="search-provider-logo"><img src="${p.businessLogo}" alt=""></div>`:`<div class="search-provider-logo">${(p.businessName||'P').charAt(0)}</div>`; html+=`<a class="search-item-row" href="../shared/landing.php#providers">${logo}<div><div class="search-item-name">${p.businessName||''}</div><div class="search-item-sub">${p.category||''}</div></div></a>`; }); }
    dropdown.innerHTML=html; dropdown.classList.add('open');
  }
  input.addEventListener('input',function(){ const q=this.value.trim(); clearTimeout(timer); if(q.length<2){ dropdown.classList.remove('open'); dropdown.innerHTML=''; return; } dropdown.innerHTML='<div class="search-loading">Searching...</div>'; dropdown.classList.add('open'); timer=setTimeout(()=>{ fetch('../../back-end/search.php?q='+encodeURIComponent(q)).then(r=>r.json()).then(render).catch(()=>{ dropdown.innerHTML='<div class="search-empty">Search unavailable</div>'; dropdown.classList.add('open'); }); },220); });
  document.addEventListener('click',function(e){ const notif=document.getElementById('notifDropdown'); const bellWrap=document.querySelector('.nav-bell-wrap'); if(notif&&bellWrap&&!bellWrap.contains(e.target)) notif.classList.remove('open'); if(!wrap.contains(e.target)) dropdown.classList.remove('open'); });
})();
</script>
</body>
</html>
