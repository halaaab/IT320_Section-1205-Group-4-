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

// ── Cart count ──
$_cartForCount = (new Cart())->getOrCreate($customerId);
$cartCount = array_sum(array_map(fn($ci) => (int)($ci['quantity'] ?? 1), (array)($_cartForCount['cartItems'] ?? [])));

// ── Full notifications ──
$_notifModel  = new Notification();
$notifications = (array)$_notifModel->getByCustomer($customerId);
$unreadCount   = $_notifModel->getUnreadCount($customerId);

// ── Expiry alerts ──
$expiryAlerts = [];
$now  = time(); $soon = $now + 48 * 3600;
$_favModel   = new Favourite(); $_favs = $_favModel->getByCustomer($customerId);
$_favItemIds = array_map(fn($f) => (string)$f['itemId'], $_favs);

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
    <div style="display:flex;align-items:center;gap:10px;">
      <a class="social-icon" href="#">in</a>
      <a class="social-icon" href="#">&#120143;</a>
      <a class="social-icon" href="#">&#9834;</a>
    </div>
    <div class="footer-divider"></div>
    <img src="../../images/Replate-white.png" alt="Replate" style="height:80px;object-fit:contain;" />
    <div class="footer-divider"></div>
    <div class="footer-email">
      <svg width="16" height="16" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2" viewBox="0 0 24 24"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/></svg>
      <span>Replate@gmail.com</span>
    </div>
  </div>
  <div class="footer-bottom">
    <span>© 2026</span>
    <img src="../../images/Replate-white.png" alt="" style="height:50px;object-fit:contain;"/>
    <span>All rights reserved.</span>
  </div>
</footer>
<?php }


function rp_top_header($active='') {
    global $cartCount, $notifications, $unreadCount; ?>
<nav>
  <div class="nav-left">
    <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate Logo" />
    <div class="nav-cart-wrap">
      <a href="../customer/cart.php" class="nav-cart">
        <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;" />
      </a>
      <?php if ($cartCount > 0): ?>
      <span class="cart-badge"><?= $cartCount ?></span>
      <?php endif; ?>
    </div>
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
        <?php foreach ($notifications as $_notif):
          $_nid   = (string)($_notif['_id'] ?? '');
          $_ntype = $_notif['type'] ?? 'default';
          $_nmsg  = $_notif['message'] ?? '';
          $_nread = (bool)($_notif['isRead'] ?? false);
          $_ntime = '';
          if (!empty($_notif['createdAt'])) {
              $_ts = $_notif['createdAt'] instanceof MongoDB\BSON\UTCDateTime
                  ? $_notif['createdAt']->toDateTime()->getTimestamp()
                  : strtotime((string)$_notif['createdAt']);
              $_diff = time() - $_ts;
              if ($_diff < 60)        $_ntime = 'Just now';
              elseif ($_diff < 3600)  $_ntime = floor($_diff/60) . 'm ago';
              elseif ($_diff < 86400) $_ntime = floor($_diff/3600) . 'h ago';
              else                    $_ntime = date('d M', $_ts);
          }
          $_iconClass = 'default'; $_iconSvg = '';
          if ($_ntype === 'expiry_alert') {
              $_urg = str_contains($_notif['message'] ?? '', '[red]') ? 'red' : (str_contains($_notif['message'] ?? '', '[orange]') ? 'orange' : 'yellow');
              $_urgC = $_urg==='red' ? '#c0392b' : ($_urg==='orange' ? '#e07a1a' : '#d4ac0d');
              $_iconClass = 'expiry-'.$_urg;
              $_iconSvg = '<svg width="16" height="16" fill="none" stroke="'.$_urgC.'" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
          } elseif ($_ntype === 'order_placed') {
              $_iconClass = 'order';
              $_iconSvg = '<svg width="16" height="16" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><polyline points="9 12 11 14 15 10"/></svg>';
          } elseif ($_ntype === 'order_completed') {
              $_iconClass = 'order';
              $_iconSvg = '<svg width="16" height="16" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>';
          } elseif ($_ntype === 'pickup_reminder') {
              $_iconClass = 'pickup';
              $_iconSvg = '<svg width="16" height="16" fill="none" stroke="#2255a4" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="3"/></svg>';
          }
          $_urgClass = $_ntype==='expiry_alert' ? ' urgency-'.($_urg??'yellow') : '';
          $_nmsgClean = trim(preg_replace('/\[(?:red|orange|yellow|pickup|completed|cancelled)\]\s*/', '', htmlspecialchars($_nmsg)));
        ?>
        <div class="notif-item <?= $_nread ? '' : 'unread' ?><?= $_urgClass ?>" data-id="<?= $_nid ?>" onclick="markRead(this)">
          <div class="notif-icon <?= $_iconClass ?>"><?= $_iconSvg ?></div>
          <div class="notif-body">
            <p class="notif-msg"><?= $_nmsgClean ?></p>
            <span class="notif-time"><?= $_ntime ?></span>
          </div>
          <?php if (!$_nread): ?><div class="notif-unread-dot"></div><?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        </div>
        <div class="notif-footer"><a href="customer-profile.php">View all notifications</a></div>
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
nav{display:flex;align-items:center;justify-content:space-between;padding:0 48px;height:72px;background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);position:sticky;top:0;z-index:10000;box-shadow:0 2px 16px rgba(26,58,107,0.18)}
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
.nav-cart-wrap{position:relative;display:flex;align-items:center}
.cart-badge{position:absolute;top:-5px;right:-5px;min-width:19px;height:19px;background:#e53935;border-radius:50%;border:2px solid #2255a4;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none}
.bell-badge{position:absolute;top:-3px;right:-3px;width:18px;height:18px;background:#e53935;border-radius:50%;border:2px solid transparent;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none}

/* ── PAGE LAYOUT ── */
.page-wrap{max-width:860px;margin:0 auto;padding:28px 20px 60px}
.page-title-row{display:flex;align-items:center;gap:20px;margin:0 0 28px}
.back-btn{width:46px;height:46px;border-radius:50%;background:#cdd9e8;color:#1b3f92;display:flex;align-items:center;justify-content:center;font-size:28px;line-height:1;flex-shrink:0;font-weight:700}
.back-btn:hover{background:#bfcee2}
.page-title{font-size:62px;line-height:.95;margin:0;color:#183482;font-weight:700}

/* ── PROVIDER BLOCK ── */
.provider-block{background:#fff;border:1.8px solid #d2dce8;border-radius:28px;padding:24px 26px;margin-bottom:24px;box-shadow:0 2px 12px rgba(26,58,107,.06)}
.provider-header-row{display:flex;align-items:center;gap:14px;margin-bottom:16px}
.provider-logo-text{font-size:36px;font-weight:700;color:#c85a3a;letter-spacing:1px;font-family:'Playfair Display',serif}

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
.cart-total-row{display:flex;justify-content:flex-end;align-items:center;gap:12px;font-size:22px;font-weight:700;color:#183482;margin:18px 0 0;padding-right:8px}
.cart-total-row span{color:#ea8b2c;display:flex;align-items:center;gap:4px}
.riyal-img{height:16px;object-fit:contain;vertical-align:middle;margin-right:2px}
.donation-tag{color:#2eb35c;font-weight:700;font-size:16px}

/* ── FOOTER ── */
footer{background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);padding:28px 48px;display:flex;flex-direction:column;align-items:center;gap:14px;margin-top:40px}
.footer-top{display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:center}
.social-icon{width:42px;height:42px;border-radius:50%;border:1.5px solid rgba(255,255,255,.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;font-family:'Playfair Display',serif;transition:background .2s}
.social-icon:hover{background:rgba(255,255,255,.15)}
.footer-divider{width:1px;height:22px;background:rgba(255,255,255,.3)}
.footer-brand{display:flex;align-items:center;gap:8px;color:#fff;font-size:16px;font-weight:700;font-family:'Playfair Display',serif}
.footer-email{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.9);font-size:14px;font-family:'Playfair Display',serif}
.footer-bottom{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.7);font-size:13px;font-family:'Playfair Display',serif;flex-wrap:wrap;justify-content:center}


.notif-dropdown{display:none;position:absolute;top:50px;right:0;width:360px;background:#fff;border-radius:20px;box-shadow:0 12px 48px rgba(26,58,107,0.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden}
.notif-dropdown.open{display:block;animation:floatUp .2s ease}
.notif-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1.5px solid #f0f5fc;background:#fff}
.notif-header-title{font-size:15px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif}
.notif-mark-all{font-size:12px;color:#2255a4;background:none;border:none;cursor:pointer;font-family:'Playfair Display',serif;font-weight:600;padding:0}
.notif-mark-all:hover{color:#1a3a6b}
.notif-list{max-height:420px;overflow-y:auto;scrollbar-width:thin;scrollbar-color:#c8d8ee transparent}
.notif-empty{padding:36px 18px;text-align:center;color:#b0c4d8;font-size:14px}
.notif-item{display:flex;align-items:flex-start;gap:12px;padding:14px 18px;border-bottom:1px solid #f5f8fc;transition:background .15s;cursor:pointer;position:relative}
.notif-item:last-child{border-bottom:none}
.notif-item:hover{background:#f8fbff}
.notif-item.unread{background:#fffaf5;border-left:3px solid #e07a1a}
.notif-item.unread:hover{background:#fff4e8}
.notif-icon{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:2px}
.notif-icon.expiry-red{background:#fde8e8}
.notif-icon.expiry-orange{background:#fff0e0}
.notif-icon.expiry-yellow{background:#fffbe6}
.notif-icon.order{background:#e8f7ee}
.notif-icon.pickup{background:#e8f0ff}
.notif-icon.cancelled{background:#fde8e8}
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
@keyframes cartPop{0%{transform:scale(0);opacity:0}70%{transform:scale(1.25);opacity:1}100%{transform:scale(1);opacity:1}}
@keyframes floatUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.cart-badge{animation:cartPop .4s cubic-bezier(0.175,0.885,0.32,1.275)}
.bell-badge{animation:cartPop .4s cubic-bezier(0.175,0.885,0.32,1.275)}


    .leaflet-pane,.leaflet-tile,.leaflet-marker-icon,.leaflet-marker-shadow,.leaflet-tile-pane,.leaflet-overlay-pane,.leaflet-shadow-pane,.leaflet-marker-pane,.leaflet-popup-pane,.leaflet-map-pane svg,.leaflet-map-pane canvas{z-index:1!important}
    .leaflet-control{z-index:2!important}
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
    $storedPickupTime = $_POST['selectedPickupTime'] ?? '';
    if ($action==='inc' && $itemId) {
        $cart = $cartModel->getOrCreate($customerId);
        foreach (($cart['cartItems'] ?? []) as $ci) if ((string)$ci['itemId']===$itemId) {
            $newQty = (int)$ci['quantity'] + 1;
            $cartModel->updateQuantity($customerId, $itemId, $newQty);
            // Re-save selectedPickupTime if model supports it
            if (!empty($ci['selectedPickupTime'])) {
                try { $cartModel->updateItemField($customerId, $itemId, 'selectedPickupTime', $ci['selectedPickupTime']); } catch(Throwable) {}
            }
            break;
        }
    }
    if ($action==='dec' && $itemId) {
        $cart = $cartModel->getOrCreate($customerId);
        foreach (($cart['cartItems'] ?? []) as $ci) if ((string)$ci['itemId']===$itemId) {
            $q=(int)$ci['quantity'] - 1;
            if ($q > 0) {
                $cartModel->updateQuantity($customerId,$itemId,$q);
                if (!empty($ci['selectedPickupTime'])) {
                    try { $cartModel->updateItemField($customerId, $itemId, 'selectedPickupTime', $ci['selectedPickupTime']); } catch(Throwable) {}
                }
            } else {
                $cartModel->removeItem($customerId,$itemId);
            }
            break;
        }
    }
    if ($action==='remove' && $itemId) $cartModel->removeItem($customerId, $itemId);
    header('Location: cart.php'); exit;
}
$cart = $cartModel->getOrCreate($customerId);
$grouped = [];
$total = 0;
$_cartItemIds = [];
foreach (($cart['cartItems'] ?? []) as $ci) {
    $_cartItemIds[] = (string)$ci['itemId'];
    $providerId = rp_oid($ci['providerId']);
    $provider = $providerModel->findById($providerId);
    $item = $itemModel->findById(rp_oid($ci['itemId']));
    $logoTxt = $provider['businessName'] ?? 'Provider';
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
// ── Cart count ──
$_cartForCount = (new Cart())->getOrCreate($customerId);
$cartCount = array_sum(array_map(fn($ci) => (int)($ci['quantity'] ?? 1), (array)($_cartForCount['cartItems'] ?? [])));

// ── Full notifications ──
$_notifModel  = new Notification();
$notifications = (array)$_notifModel->getByCustomer($customerId);
$unreadCount   = $_notifModel->getUnreadCount($customerId);

// ── Expiry alerts ──
$_watchedIds = array_unique(array_merge($_cartItemIds, $_favItemIds));
foreach ($_watchedIds as $_wId) {
    try {
        $_wItem = $itemModel->findById($_wId);
        if (!$_wItem || !isset($_wItem['expiryDate'])) continue;
        $_expiry = $_wItem['expiryDate']->toDateTime()->getTimestamp();
        if ($_expiry >= $now && $_expiry <= $soon) {
            $expiryAlerts[] = ['name'=>$_wItem['itemName']??'Item','hoursLeft'=>ceil(($_expiry-$now)/3600),'source'=>in_array($_wId,$_cartItemIds)?'cart':'favourites'];
        }
    } catch (Throwable) { continue; }
}
$alertCount = count($expiryAlerts);
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
      <div class="provider-header-row">
        <?php if (!empty($g['provider']['businessLogo'])): ?>
          <img src="<?= rp_h($g['provider']['businessLogo']) ?>" alt="<?= rp_h($g['logoTxt']) ?>" style="height:50px;max-width:160px;object-fit:contain;">
        <?php endif; ?>
        <div class="provider-logo-text"><?= rp_h($g['logoTxt']) ?></div>
      </div>

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
            <?php if (($item['listingType'] ?? '') === 'donate'): ?>
              <div class="price donation-tag">Donation</div>
            <?php else: ?>
              <div class="price"><img src="../../images/SAR.png" class="riyal-img" alt="SAR"><?= rp_money((float)$ci['price'] * (int)$ci['quantity']) ?></div>
            <?php endif; ?>
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

    <?php
    $isDonationOnly = true;
    foreach ($grouped as $_pg) { foreach ($_pg['items'] as $_pr) { if (($_pr['item']['listingType'] ?? '') !== 'donate') { $isDonationOnly = false; break 2; } } }
    ?>
    <?php if (!$isDonationOnly): ?>
    <div class="cart-total-row">
      <strong>Total:</strong>
      <span><img src="../../images/SAR.png" class="riyal-img" alt="SAR"><?= rp_money($total) ?></span>
    </div>
    <?php endif; ?>
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
        html += `<a class="search-item-row" href="../shared/landing.php#providers">${logo}<div><div class="search-item-name">${p.businessName || ''}</div><div class="search-item-sub">${p.category || ''}</div></div></a>`;
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

function updateBellBadge(delta) {
  const badge = document.getElementById('bellBadge');
  if (!badge) return;
  const next = Math.max(0, (parseInt(badge.textContent)||0) + delta);
  if (next === 0) badge.style.display = 'none';
  else { badge.textContent = next; badge.style.display = 'flex'; }
}
function markRead(el) {
  if (!el.classList.contains('unread')) return;
  const notifId = el.dataset.id;
  el.classList.remove('unread');
  const dot = el.querySelector('.notif-unread-dot'); if(dot) dot.remove();
  updateBellBadge(-1);
  fetch(window.location.pathname, {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'mark_read',notifId})}).catch(()=>{});
}
function markAllRead() {
  document.querySelectorAll('#notifDropdown .notif-item.unread').forEach(el=>{el.classList.remove('unread');const d=el.querySelector('.notif-unread-dot');if(d)d.remove();});
  const badge=document.getElementById('bellBadge'); if(badge) badge.style.display='none';
  const btn=document.querySelector('.notif-mark-all'); if(btn) btn.style.display='none';
  fetch(window.location.pathname,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'mark_all_read'})}).catch(()=>{});
}
</script>
</body>
</html>