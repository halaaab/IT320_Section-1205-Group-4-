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

function rp_top_header($active='') { global $alertCount, $expiryAlerts; ?>
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
.search-thumb{width:38px;height:38px;border-radius:10px;background:#e0eaf5;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:18px}
.search-thumb img{width:100%;height:100%;object-fit:cover;border-radius:10px}
.search-item-name{font-size:14px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif}
.search-item-sub{font-size:12px;color:#7a8fa8}
.search-price{margin-left:auto;font-size:13px;font-weight:700;color:#e07a1a;white-space:nowrap}
.search-divider{height:1px;background:#f0f5fc;margin:4px 0}
.search-empty{padding:24px 16px;text-align:center;color:#b0c4d8;font-size:14px}
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
.notif-header-title{font-size:15px;font-weight:700;color:#1a3a6b}
.notif-empty{padding:28px 18px;text-align:center;color:#b0c4d8;font-size:14px}

/* ── PAGE LAYOUT ── */
.page-wrap{max-width:860px;margin:0 auto;padding:28px 20px 60px}
.page-title-row{display:flex;align-items:center;gap:20px;margin:0 0 28px}
.back-btn{width:46px;height:46px;border-radius:50%;background:#cdd9e8;color:#1b3f92;display:flex;align-items:center;justify-content:center;font-size:28px;line-height:1;flex-shrink:0;font-weight:700;text-decoration:none}
.back-btn:hover{background:#bfcee2}
.page-title{font-size:62px;line-height:.95;margin:0;color:#183482;font-weight:700}

/* ── ORDERS SHELL ── */
.orders-shell{background:#fff;border:1.8px solid #d2dce8;border-radius:28px;padding:24px;box-shadow:0 2px 12px rgba(26,58,107,.06)}

/* ── SEGMENTED TABS ── */
.segmented{display:flex;gap:0;justify-content:center;margin-bottom:24px;gap:20px}
.seg-btn{min-width:220px;padding:14px 26px;border-radius:22px;border:1.8px solid #ea8b2c;background:#fff;color:#183482;font-size:24px;font-family:'Playfair Display',serif;cursor:pointer;text-decoration:none;text-align:center;display:inline-block;transition:background .2s,color .2s}
.seg-btn.active{background:#f6811f;color:#fff;border-color:#f6811f}
.seg-btn:not(.active):hover{background:#fff8f2}

/* ── ORDER ROW ── */
.order-row{background:#f5f8fc;border:1.6px solid #d2dce8;border-radius:22px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;margin:14px 0;text-decoration:none;color:inherit;transition:box-shadow .2s}
.order-row:hover{box-shadow:0 4px 18px rgba(26,58,107,.1)}
.order-left{display:flex;align-items:center;gap:16px}
.logo-box{width:130px;height:100px;border-radius:20px;border:1.4px solid #d2dce8;background:#fff;display:flex;align-items:center;justify-content:center;padding:8px;text-align:center;font-size:26px;color:#c85a3a;font-weight:700;font-family:'Playfair Display',serif;flex-shrink:0;line-height:1.1;text-transform:uppercase}
.order-info h3{margin:0 0 6px;font-size:20px;color:#183482;font-weight:700}
.info-line{display:flex;align-items:center;gap:8px;color:#4166ad;font-size:15px;margin:5px 0}
.info-line svg{flex-shrink:0}
.order-right{display:flex;flex-direction:column;align-items:flex-end;gap:12px;flex-shrink:0}
.order-total{color:#ea8b2c;font-size:22px;font-weight:700}
.rial-sm{font-size:14px;margin-right:2px}
.cancel-btn{display:inline-flex;align-items:center;justify-content:center;background:#f7a15d;color:#fff;border:none;border-radius:14px;padding:10px 28px;font-size:18px;font-family:'Playfair Display',serif;cursor:pointer;transition:background .2s;text-decoration:none}
.cancel-btn:hover{background:#e08a45}

/* ── MODAL ── */
.modal-backdrop{position:fixed;inset:0;background:rgba(237,242,247,.75);display:flex;align-items:center;justify-content:center;z-index:200}
.modal{background:#fff;border:1.6px solid #d2dce8;border-radius:22px;box-shadow:0 10px 30px rgba(0,0,0,.08)}
.confirm-modal{width:min(440px,92vw);overflow:hidden}
.confirm-body{padding:36px 28px;text-align:center;font-size:24px;font-weight:700;color:#3e62b6;line-height:1.4}
.confirm-actions{display:grid;grid-template-columns:1fr 1fr;border-top:1.4px solid #d2dce8}
.confirm-actions form,.confirm-actions a{display:flex;align-items:center;justify-content:center;height:80px;font-size:28px;font-family:'Playfair Display',serif;text-decoration:none;font-weight:700}
.confirm-actions form:first-child,.confirm-actions a:last-child{border-right:0}
.confirm-actions form{border-right:1.4px solid #d2dce8}
.yes-btn{color:#2eb35c;border:none;background:none;font:inherit;cursor:pointer;font-size:28px;font-weight:700;font-family:'Playfair Display',serif;width:100%;height:100%}
.no-btn{color:#d65252}

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
  .page-title{font-size:42px}
  .seg-btn{min-width:140px;font-size:18px}
  .logo-box{width:90px;height:70px;font-size:18px}
  .order-right{flex-direction:row;align-items:center}
  nav{padding:0 18px}
  .nav-center{display:none}
  footer{padding:24px 18px}
}
</style>
<?php }
?>
<?php
$orderModel = new Order(); $orderItemModel = new OrderItem();
if (($_POST['action'] ?? '')==='cancel' && !empty($_POST['orderId'])) {
    $orderModel->cancel($_POST['orderId']);
    header('Location: orders.php?tab=currently'); exit;
}
$tab = $_GET['tab'] ?? 'currently';
$allOrders = $orderModel->getByCustomer($customerId);
$orders=[];
foreach ($allOrders as $o) {
    $isCurrent = ($o['orderStatus'] ?? '') === 'pending';
    if (($tab==='currently' && !$isCurrent) || ($tab==='previously' && $isCurrent)) continue;
    $items = $orderItemModel->getByOrder(rp_oid($o['_id']));
    $first = $items[0] ?? [];
    $orders[] = ['order'=>$o, 'first'=>$first];
}
$showCancel = isset($_GET['confirm']) ? $_GET['confirm'] : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Orders – RePlate</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
  <?php rp_page_styles(); ?>
</head>
<body>
<?php rp_top_header(); ?>

<div class="page-wrap">
  <div class="page-title-row">
    <a class="back-btn" href="../shared/landing.php">‹</a>
    <h1 class="page-title">Orders</h1>
  </div>

  <div class="orders-shell">
    <!-- Tabs -->
    <div class="segmented">
      <a class="seg-btn <?= $tab==='currently'?'active':'' ?>" href="orders.php?tab=currently">Currently</a>
      <a class="seg-btn <?= $tab==='previously'?'active':'' ?>" href="orders.php?tab=previously">Previously</a>
    </div>

    <?php if (!$orders): ?>
      <div style="text-align:center;padding:32px 12px;color:#6d7da0;font-size:22px;">No <?= rp_h($tab) ?> orders yet.</div>
    <?php endif; ?>

    <?php foreach ($orders as $row): $o=$row['order']; $first=$row['first']; $oid=rp_oid($o['_id']); ?>
    <a class="order-row" href="order-details.php?orderId=<?= rp_h($oid) ?>">
      <div class="order-left">
        <div class="logo-box"><?= rp_h(strtoupper($first['providerName'] ?? 'Store')) ?></div>
        <div class="order-info">
          <h3><?= rp_h($first['providerName'] ?? 'Store') ?></h3>
          <div class="info-line">
            <svg width="16" height="16" fill="none" stroke="#4166ad" stroke-width="1.8" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <span><?= rp_h(rp_dt($o['placedAt'])) ?></span>
          </div>
          <div class="info-line">
            <svg width="16" height="16" fill="none" stroke="#4166ad" stroke-width="1.8" viewBox="0 0 24 24"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="9" y1="7" x2="15" y2="7"/><line x1="9" y1="11" x2="15" y2="11"/><line x1="9" y1="15" x2="13" y2="15"/></svg>
            <span>Order number: <?= rp_h($o['orderNumber'] ?? '') ?></span>
          </div>
        </div>
      </div>
      <div class="order-right">
        <div class="order-total"><span class="rial-sm">﷼</span><?= rp_money($o['totalAmount'] ?? 0) ?></div>
        <?php if (($o['orderStatus'] ?? '')==='pending'): ?>
          <span class="cancel-btn" onclick="event.preventDefault();event.stopPropagation();window.location='orders.php?tab=currently&confirm=<?= rp_h($oid) ?>';">Cancel</span>
        <?php endif; ?>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($showCancel): ?>
<div class="modal-backdrop">
  <div class="modal confirm-modal">
    <div class="confirm-body">Are you sure you want to<br>cancel your order</div>
    <div class="confirm-actions">
      <form method="post">
        <input type="hidden" name="action" value="cancel">
        <input type="hidden" name="orderId" value="<?= rp_h($showCancel) ?>">
        <button class="yes-btn">Yes</button>
      </form>
      <a class="no-btn" href="orders.php?tab=currently">No</a>
    </div>
  </div>
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
