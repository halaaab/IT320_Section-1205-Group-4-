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
require_once '../../back-end/models/Provider.php';
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
    $tz = new DateTimeZone('Asia/Riyadh');
    if ($dt instanceof MongoDB\BSON\UTCDateTime) {
        $d = $dt->toDateTime(); $d->setTimezone($tz); return $d->format($fmt);
    }
    if (is_numeric($dt)) {
        $d = new DateTime('@'.(int)$dt); $d->setTimezone($tz); return $d->format($fmt);
    }
    if ($dt) {
        $d = new DateTime((string)$dt); $d->setTimezone($tz); return $d->format($fmt);
    }
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

/* ── NAV ── */
nav{display:flex;align-items:center;justify-content:space-between;padding:0 48px;height:72px;background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);position:sticky;top:0;z-index:10000;box-shadow:0 2px 16px rgba(26,58,107,.18)}
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
.nav-cart-wrap{position:relative;display:flex;align-items:center}
.cart-badge{position:absolute;top:-5px;right:-5px;min-width:19px;height:19px;background:#e53935;border-radius:50%;border:2px solid #2255a4;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none}
.bell-badge{position:absolute;top:-3px;right:-3px;width:18px;height:18px;background:#e53935;border-radius:50%;border:2px solid transparent;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none}

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
.pickup-label{font-size:16px;font-weight:700;color:#183482;text-align:center;margin-bottom:10px}
.provider-map{width:100%;height:160px;border-radius:18px;border:1.5px solid #d2dce8;background:#dde8f2;overflow:hidden;position:relative;z-index:1}
.map-fallback{width:100%;height:160px;border-radius:18px;background:linear-gradient(135deg,#86b1d8 0%,#d5e6f1 100%);display:flex;align-items:center;justify-content:center;text-align:center;color:#183482;font-size:13px;padding:12px}
.provider-right{padding:24px 20px;border-left:1.5px solid #e6edf5;display:flex;flex-direction:column;gap:12px}
.pickup-address{font-size:13px;color:#4d6186;text-align:center;margin-top:4px}
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
.riyal-img{height:16px;object-fit:contain;vertical-align:middle;margin-right:2px}
@keyframes cartPop{0%{transform:scale(0);opacity:0}70%{transform:scale(1.25);opacity:1}100%{transform:scale(1);opacity:1}}
@keyframes floatUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.cart-badge{animation:cartPop .4s cubic-bezier(0.175,0.885,0.32,1.275)}
.bell-badge{animation:cartPop .4s cubic-bezier(0.175,0.885,0.32,1.275)}


    .leaflet-pane,.leaflet-tile,.leaflet-marker-icon,.leaflet-marker-shadow,.leaflet-tile-pane,.leaflet-overlay-pane,.leaflet-shadow-pane,.leaflet-marker-pane,.leaflet-popup-pane,.leaflet-map-pane svg,.leaflet-map-pane canvas{z-index:1!important}
    .leaflet-control{z-index:2!important}
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

// Group items by provider
$groupedItems = [];
foreach ($orderItems as $_oi) {
    $_pid = (string)($_oi['providerId'] ?? 'unknown');
    if (!isset($groupedItems[$_pid])) {
        $_prov = null;
        $_loc  = null;
        try { $_prov = (new Provider())->findById($_pid); } catch(Throwable) {}
        try { $_loc = (new PickupLocation())->getDefault($_pid); } catch(Throwable) {}
        $groupedItems[$_pid] = [
            'provider' => $_prov,
            'location' => $_loc,
            'items'    => [],
        ];
    }
    $groupedItems[$_pid]['items'][] = $_oi;
}

// Check if entire order is donation
$isDonationOrder = true;
foreach ($orderItems as $_oi) {
    try {
        $_li = (new Item())->findById((string)($_oi['itemId'] ?? ''));
        if (!$_li || ($_li['listingType'] ?? '') !== 'donate') { $isDonationOrder = false; break; }
    } catch(Throwable) { $isDonationOrder = false; break; }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Order Details – RePlate</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <?php rp_page_styles(); ?>
</head>
<body>
<?php rp_top_header(); ?>

<div class="page-wrap">
  <div class="page-title-row">
    <a class="back-btn" href="orders.php">‹</a>
    <h1 class="page-title">Order number:<?= rp_h($order['orderNumber'] ?? '') ?></h1>
  </div>

  <div class="detail-card" style="padding:0;overflow:hidden;background:transparent;border:none;box-shadow:none;display:flex;flex-direction:column;gap:24px;">

    <?php foreach ($groupedItems as $_pid => $_group): ?>
    <?php
      $_prov     = $_group['provider'];
      $_loc      = $_group['location'];
      $_provName = $_prov['businessName'] ?? ($groupedItems[$_pid]['items'][0]['providerName'] ?? 'Store');
      $_lat      = null; $_lng = null;
      if ($_loc) {
          $_coords = $_loc['coordinates'] ?? null;
          if ($_coords) {
              $_ca = is_array($_coords) ? $_coords : (array)$_coords;
              $_lat = $_ca['lat'] ?? null;
              $_lng = $_ca['lng'] ?? null;
          }
      }
      $_locStr = '';
      if ($_loc) {
          $_locStr = implode(', ', array_filter([$_loc['street'] ?? '', $_loc['city'] ?? '', $_loc['zip'] ?? '']));
      }
      $_mapId = 'map_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $_pid);
    ?>
    <div class="detail-card" style="display:grid;grid-template-columns:1fr 220px;gap:0;overflow:hidden;">

      <!-- LEFT: provider + items -->
      <div style="padding:24px 26px;">
        <!-- Provider header -->
        <div class="provider-header" style="margin-bottom:20px;">
          <div class="prov-logo-box">
            <?php if (!empty($_prov['businessLogo'])): ?>
              <img src="<?= rp_h($_prov['businessLogo']) ?>" alt="<?= rp_h($_provName) ?>" style="max-height:90px;max-width:120px;object-fit:contain;">
            <?php else: ?>
              <div class="prov-logo-text"><?= rp_h($_provName) ?></div>
            <?php endif; ?>
          </div>
          <div class="prov-info">
            <div class="prov-name"><?= rp_h($_provName) ?></div>
            <div class="status-badge"><?= ucfirst(rp_h($order['orderStatus'] ?? 'pending')) ?></div>
          </div>
        </div>

        <!-- Items -->
        <?php foreach ($_group['items'] as $item): ?>
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
          <?php
          $_li2 = null;
          try { $_li2 = (new Item())->findById((string)($item['itemId'] ?? '')); } catch(Throwable) {}
          $isItemDonate = (($_li2['listingType'] ?? '') === 'donate');
          ?>
          <?php if ($isItemDonate): ?>
          <div class="item-price donation-tag">Donation</div>
          <?php else: ?>
          <div class="item-price"><img src="../../images/SAR.png" class="riyal-img" alt="SAR"><?= rp_money((float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 1)) ?></div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>

        <!-- Meta -->
        <div class="detail-meta">
          <?php if (!$isDonationOrder): ?>
          <div><strong>Payment method :</strong> Cash 💵</div>
          <?php endif; ?>
          <?php
          $_pt = $groupedItems[$_pid]['items'][0]['selectedPickupTime'] ?? '';
          if ($_pt && $_pt !== 'Anytime'):
          ?>
          <div><strong>Pickup time :</strong> <?= rp_h($_pt) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- RIGHT: map -->
      <div class="provider-right">
        <div class="pickup-label">Pick up location</div>
        <?php if ($_lat && $_lng): ?>
          <div id="<?= rp_h($_mapId) ?>" class="provider-map"></div>
        <?php else: ?>
          <div class="map-fallback">Location not available</div>
        <?php endif; ?>
        <div class="pickup-address"><?= rp_h($_locStr) ?></div>
      </div>

    </div>
    <?php endforeach; ?>

    <!-- Total -->
    <div class="detail-meta" style="padding:20px 26px;background:#fff;border:1.8px solid #d2dce8;border-radius:28px;">
      <?php if (!$isDonationOrder): ?>
      <div><strong>Total Amount :</strong> <span class="meta-amount"><img src="../../images/SAR.png" class="riyal-img" alt="SAR"><?= rp_money($order['totalAmount'] ?? 0) ?></span></div>
      <?php else: ?>
      <div><strong>Total Amount :</strong> <span class="donation-tag">Donation</span></div>
      <?php endif; ?>
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

// Init maps for each provider
document.addEventListener('DOMContentLoaded', function() {
  <?php foreach ($groupedItems as $_pid => $_group): ?>
  <?php
    $_loc2 = $_group['location'];
    $_lat2 = null; $_lng2 = null;
    if ($_loc2) {
        $_lat2 = $_loc2['coordinates']['lat'] ?? null;
        $_lng2 = $_loc2['coordinates']['lng'] ?? null;
    }
    $_mapId2 = 'map_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $_pid);
  ?>
  <?php if ($_lat2 && $_lng2): ?>
  (function() {
    var m = L.map('<?= $_mapId2 ?>', {zoomControl:false,dragging:false,scrollWheelZoom:false,doubleClickZoom:false,boxZoom:false,keyboard:false,tap:false,touchZoom:false})
      .setView([<?= (float)$_lat2 ?>, <?= (float)$_lng2 ?>], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'&copy; OpenStreetMap'}).addTo(m);
    L.marker([<?= (float)$_lat2 ?>, <?= (float)$_lng2 ?>]).addTo(m);
    setTimeout(function(){ m.invalidateSize(); }, 150);
  })();
  <?php endif; ?>
  <?php endforeach; ?>
});
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
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</html>