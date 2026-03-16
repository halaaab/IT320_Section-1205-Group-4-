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
        <circle cx="11" cy="11" r="8"/>
        <path d="M21 21l-4.35-4.35"/>
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
        <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>
    </a>
  </div>
</nav>
<?php }
function rp_sidebar($firstName, $active='contact'){ ?>
<aside class="sidebar">
  <p class="sidebar-welcome">Welcome Back ,</p>
  <p class="sidebar-name"><?= rp_h($firstName) ?></p>

  <nav class="sidebar-nav">
    <a href="customer-profile.php" class="sidebar-link <?= $active==='profile' ? 'active' : '' ?>">
      <span>👤</span>
      Profile
    </a>

    <a href="favorites.php" class="sidebar-link <?= $active==='favorites' ? 'active' : '' ?>">
      <span>🔖</span>
      Favourites
    </a>

    <a href="orders.php" class="sidebar-link <?= $active==='orders' ? 'active' : '' ?>">
      <span>📦</span>
      Orders
    </a>

    <a href="customer-profile.php#notifications" class="sidebar-link <?= $active==='notification' ? 'active' : '' ?>">
      <span>🔔</span>
      Notification
    </a>

    <a href="contact.php" class="sidebar-link <?= $active==='contact' ? 'active' : '' ?>">
      <span>👥</span>
      Contact Us
    </a>
  </nav>

  <button class="sidebar-logout" onclick="window.location.href='customer-profile.php?logout=1'">
    Log out
  </button>

  <div class="sidebar-footer">
    <div class="sidebar-footer-social">
      <a href="#" class="sidebar-social-icon">in</a>
      <a href="#" class="sidebar-social-icon">&#120143;</a>
      <a href="#" class="sidebar-social-icon">&#9834;</a>
    </div>

    <div class="sidebar-footer-email">
      <span>✉</span>
      <span>Replate@gmail.com</span>
    </div>

    <div class="sidebar-footer-copy">
      <span>© 2026</span>
      <span>Replate</span>
      <span>All rights reserved.</span>
    </div>
  </div>
</aside>
<?php }
function rp_page_styles(){ ?>
<style>
*{box-sizing:border-box} html,body{margin:0;padding:0} body{background:#edf2f7;color:#1b2f74;font-family:'Playfair Display',serif} a{text-decoration:none}
.page-wrap{max-width:1120px;margin:0 auto;padding:18px 20px 42px}.page-title-row{display:flex;align-items:center;gap:16px;margin:10px 0 18px}.back-btn{width:40px;height:40px;border-radius:50%;background:#dfe7f5;color:#1b3f92;display:flex;align-items:center;justify-content:center;font-size:34px;line-height:1}.page-title{font-size:68px;line-height:.95;margin:0;color:#183482;font-weight:700}
.card-shell{background:#eef3f8;border:1.8px solid #bcc8d8;border-radius:28px;padding:28px}.provider-block{background:#eef3f8;border:1.8px solid #bcc8d8;border-radius:28px;padding:20px 22px;margin-bottom:28px}.provider-logo-text{font-size:48px;line-height:1;color:#d56e3b;opacity:.95;margin:0 0 12px 6px;text-transform:uppercase;letter-spacing:1px}.provider-card{background:#f9fbfd;border:1.6px solid #c8d1dc;border-radius:24px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;margin:12px 0}.provider-location-wrap{display:grid;grid-template-columns:minmax(260px,1fr) 240px;gap:18px;align-items:start;background:#fcfcfd;border:1.6px solid #c8d1dc;border-radius:24px;padding:16px 18px;margin:10px 0 16px}.provider-location-meta h3{margin:0 0 8px;color:#183482;font-size:22px}.provider-location-meta p{margin:0 0 8px;color:#4d6186;font-size:15px;line-height:1.45}.provider-location-badge{display:inline-flex;align-items:center;gap:8px;background:#eef4ff;color:#1b3f92;border-radius:999px;padding:8px 14px;font-size:14px;font-weight:700;margin-bottom:10px}.provider-map-frame{width:100%;height:190px;border:0;border-radius:20px;background:#eaf1fb}.provider-map-fallback{width:100%;height:190px;border-radius:20px;background:linear-gradient(135deg,#86b1d8 0%,#d5e6f1 100%);display:flex;align-items:center;justify-content:center;text-align:center;color:#183482;padding:18px;font-size:15px;line-height:1.5}.item-left{display:flex;align-items:center;gap:16px;min-width:0}.item-thumb{width:90px;height:70px;border-radius:18px;background:#fff;border:1.4px solid #ddd;object-fit:cover;display:block}.item-meta h3{margin:0;color:#5869aa;font-size:24px}.item-meta .price{margin-top:3px;color:#b7b3bc;font-size:18px}.qty-controls{display:flex;align-items:center;gap:12px;color:#eb8b24;font-size:24px;font-weight:700}.qty-btn{border:none;background:none;color:#eb8b24;font-size:24px;cursor:pointer;font-family:inherit;padding:0 2px}.remove-btn{border:none;background:none;color:#9ab1d8;font-size:24px;cursor:pointer;margin-left:8px}.primary-cta{display:block;width:min(370px,100%);margin:26px auto 0;background:#f6811f;color:#fff;border:none;border-radius:22px;padding:18px 20px;font-size:34px;font-family:'Playfair Display',serif;cursor:pointer;text-align:center}.secondary-cta{display:block;width:min(520px,100%);margin:16px auto 0;background:#173993;color:#fff;border:none;border-radius:22px;padding:18px 20px;font-size:32px;font-family:'Playfair Display',serif;cursor:pointer;text-align:center}.summary-panel{background:#dce6f7;border-radius:0;padding:34px 48px;margin-top:26px}.summary-row{display:flex;align-items:center;gap:10px;font-size:30px;font-weight:700;color:#173993}.summary-row .amount{color:#eb8b24;font-weight:400}
.segmented{display:flex;gap:22px;justify-content:center;margin-bottom:22px}.seg-btn{min-width:220px;padding:15px 26px;border-radius:22px;border:1.8px solid #ea8b2c;background:#fff;color:#183482;font-size:26px;font-family:'Playfair Display',serif}.seg-btn.active.orange,.seg-btn.active.blue{background:#f6811f;color:#fff}.order-row{background:#fcfcfd;border:1.6px solid #c8d1dc;border-radius:24px;padding:16px;display:flex;align-items:center;justify-content:space-between;gap:18px;margin:18px 0}.order-left{display:flex;align-items:center;gap:14px}.logo-box{width:146px;height:112px;border-radius:22px;border:1.4px solid #cfcfcf;background:#fff;display:flex;align-items:center;justify-content:center;padding:10px;text-align:center;font-size:34px;color:#d56e3b}.order-info h3{margin:0 0 8px;font-size:20px;color:#183482}.info-line{display:flex;align-items:center;gap:10px;color:#4166ad;font-size:15px;margin:6px 0}.order-right{min-width:160px;text-align:right}.order-total{color:#ea8b2c;font-size:22px;font-weight:700;margin-bottom:18px}.cancel-btn{display:inline-flex;align-items:center;justify-content:center;background:#f7a15d;color:#fff;border:none;border-radius:14px;padding:10px 26px;font-size:20px;font-family:'Playfair Display',serif;cursor:pointer}.detail-hero{background:#eef3f8;border:1.8px solid #bcc8d8;border-radius:28px;padding:22px}.hero-top{display:flex;align-items:center;gap:22px;margin-bottom:26px}.hero-brand{display:flex;align-items:center;gap:20px}.hero-logo{width:180px;height:140px;border-radius:22px;background:#fff;border:1.4px solid #cfcfcf;display:flex;align-items:center;justify-content:center;font-size:54px;color:#c84e3a;text-align:center}.hero-name{font-size:40px;font-weight:700;color:#183482}.status-badge{display:inline-flex;align-items:center;gap:8px;border-radius:999px;padding:10px 18px;font-size:17px;font-weight:700}.status-badge.current{background:#eef4ff;color:#2255a4}.status-badge.past{background:#eef8ef;color:#1a6b3a}.detail-grid{display:grid;grid-template-columns:1.2fr .95fr;gap:22px;margin-top:20px}.detail-item{background:#fcfcfd;border:1.6px solid #c8d1dc;border-radius:24px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px}.detail-price{color:#ea8b2c;font-size:22px;font-weight:700}.detail-meta{margin-top:20px;font-size:18px;line-height:1.9;color:#183482}.detail-meta strong{font-size:24px}.place-grid .provider-row{display:grid;grid-template-columns:1.2fr .9fr;gap:24px}.mini-map{width:150px;height:125px;border-radius:22px;border:1.4px solid #c8d1dc;background:linear-gradient(135deg,#86b1d8 0%,#d5e6f1 100%);position:relative;overflow:hidden}.mini-map:before,.mini-map:after{content:'';position:absolute;background:rgba(255,255,255,.5)}.mini-map:before{inset:18px 0 auto 0;height:10px;transform:rotate(-24deg)}.mini-map:after{inset:auto 0 34px 0;height:10px;transform:rotate(26deg)}.pin{position:absolute;left:58%;top:32%;font-size:30px}.pickup-card{display:flex;flex-direction:column;align-items:center;gap:8px}.pickup-title{font-size:20px;font-weight:700;color:#183482;text-align:center}.pickup-address{font-size:13px;color:#4e607e;width:150px}.modal-backdrop{position:fixed;inset:0;background:rgba(237,242,247,.72);display:flex;align-items:center;justify-content:center;z-index:100}.modal{background:#fff;border:1.6px solid #bcc8d8;border-radius:22px;box-shadow:0 10px 30px rgba(0,0,0,.06)}.success-modal{width:min(470px,92vw);padding:86px 24px;text-align:center;font-size:28px;font-weight:700;color:#3e62b6}.confirm-modal{width:min(440px,92vw);overflow:hidden}.confirm-body{padding:34px 28px;text-align:center;font-size:26px;font-weight:700;color:#3e62b6;line-height:1.35}.confirm-actions{display:grid;grid-template-columns:1fr 1fr;border-top:1.4px solid #c8d1dc}.confirm-actions form,.confirm-actions a{display:flex;align-items:center;justify-content:center;height:86px;font-size:30px}.yes-btn{color:#2eb35c}.no-btn{color:#d65252}
.content-with-sidebar{
  display:grid;
  grid-template-columns:240px 1fr;
  min-height:calc(100vh - 62px);
}

.sidebar{
  width:240px;
  min-height:calc(100vh - 62px);
  background:#2255a4;
  display:flex;
  flex-direction:column;
  padding:36px 24px 28px;
  flex-shrink:0;
}

.sidebar-welcome{
  color:rgba(255,255,255,0.75);
  font-size:18px;
  font-weight:400;
  margin-bottom:4px;
}

.sidebar-name{
  color:rgba(255,255,255,0.55);
  font-size:42px;
  font-weight:700;
  line-height:1.1;
  margin-bottom:36px;
}

.sidebar-nav{
  display:flex;
  flex-direction:column;
  gap:16px;
  flex:1;
}

.sidebar-link{
  display:flex;
  align-items:center;
  gap:10px;
  color:rgba(255,255,255,0.75);
  text-decoration:none;
  font-size:16px;
  font-weight:400;
  padding:10px 8px;
  background:none !important;
}

.sidebar-link:hover{
  color:#fff;
}

.sidebar-link.active{
  color:#fff !important;
  font-weight:700;
  border-bottom:2px solid rgba(255,255,255,0.5);
  padding-bottom:6px;
}

.sidebar-logout{
  margin-top:24px;
  background:#fff;
  color:#1a3a6b;
  border:none;
  border-radius:50px;
  padding:12px 0;
  font-size:16px;
  font-weight:700;
  font-family:'Playfair Display',serif;
  cursor:pointer;
  width:100%;
}

.sidebar-footer{
  margin-top:24px;
  padding-top:18px;
  border-top:1px solid rgba(255,255,255,0.15);
  display:flex;
  flex-direction:column;
  gap:12px;
  align-items:center;
}

.sidebar-footer-social{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  flex-wrap:wrap;
}

.sidebar-social-icon{
  width:30px;
  height:30px;
  border-radius:50%;
  border:1.5px solid rgba(255,255,255,0.45);
  display:flex;
  align-items:center;
  justify-content:center;
  color:rgba(255,255,255,0.8);
  font-size:12px;
  font-weight:700;
  text-decoration:none;
}

.sidebar-footer-email{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:6px;
  color:rgba(255,255,255,0.7);
  font-size:11px;
}

.sidebar-footer-copy{
  color:rgba(255,255,255,0.5);
  font-size:11px;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:6px;
  flex-wrap:wrap;
}
/* exact landing-page header/footer */
nav{display:flex;align-items:center;justify-content:space-between;padding:0 48px;height:72px;background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(26,58,107,0.18)}
.nav-left{display:flex;align-items:center;gap:16px}.nav-logo{height:100px}.nav-cart{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,0.7);display:flex;justify-content:center;align-items:center;cursor:pointer;transition:background .2s;text-decoration:none}.nav-cart:hover{background:rgba(255,255,255,0.15)}.nav-avatar svg{stroke:#fff}.nav-center{display:flex;align-items:center;gap:40px}.nav-center a{color:rgba(255,255,255,0.85);text-decoration:none;font-weight:500;font-size:15px;transition:color .2s}.nav-center a:hover{color:#fff}.nav-center a.active{color:#fff;font-weight:600;border-bottom:2px solid #fff;padding-bottom:2px}.nav-right{display:flex;align-items:center;gap:12px}.nav-search-wrap{position:relative}.search-dropdown{display:none;position:absolute;top:calc(100% + 10px);right:0;width:380px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(26,58,107,0.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden}.search-dropdown.open{display:block}.search-section-label{font-size:11px;font-weight:700;color:#b0c4d8;letter-spacing:.08em;text-transform:uppercase;padding:12px 16px 6px}.search-item-row{display:flex;align-items:center;gap:12px;padding:10px 16px;cursor:pointer;transition:background .15s;text-decoration:none}.search-item-row:hover{background:#f0f6ff}.search-thumb{width:38px;height:38px;border-radius:10px;background:#e0eaf5;flex-shrink:0;object-fit:cover;display:flex;align-items:center;justify-content:center;font-size:18px}.search-thumb img{width:100%;height:100%;object-fit:cover;border-radius:10px}.search-item-name{font-size:14px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif}.search-item-sub{font-size:12px;color:#7a8fa8}.search-price{margin-left:auto;font-size:13px;font-weight:700;color:#e07a1a;white-space:nowrap}.search-divider{height:1px;background:#f0f5fc;margin:4px 0}.search-empty{padding:24px 16px;text-align:center;color:#b0c4d8;font-size:14px;font-family:'Playfair Display',serif}.search-loading{padding:18px 16px;text-align:center;color:#b0c4d8;font-size:13px}.search-no-match{padding:8px 16px 12px;font-size:13px;color:#b0c4d8;font-style:italic}.search-provider-logo{width:38px;height:38px;border-radius:50%;background:#e0eaf5;flex-shrink:0;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:15px;font-weight:700;color:#2255a4}.search-provider-logo img{width:100%;height:100%;object-fit:cover}.nav-search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:.6;pointer-events:none}.nav-search-wrap input{background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.4);border-radius:50px;padding:9px 16px 9px 36px;color:#fff;font-size:14px;outline:none;width:240px;font-family:'Playfair Display',serif;transition:width .3s,background .2s}.nav-search-wrap input::placeholder{color:rgba(255,255,255,0.6)}.nav-search-wrap input:focus{width:300px;background:rgba(255,255,255,0.25)}.nav-avatar{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,0.6);display:flex;align-items:center;justify-content:center;cursor:pointer}.nav-bell-wrap{position:relative}.nav-bell{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,0.6);display:flex;align-items:center;justify-content:center;cursor:pointer;background:none;transition:background .2s}.nav-bell:hover{background:rgba(255,255,255,0.15)}.bell-badge{position:absolute;top:-3px;right:-3px;width:18px;height:18px;background:#e07a1a;border-radius:50%;border:2px solid transparent;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;color:#fff;pointer-events:none}.notif-dropdown{display:none;position:absolute;top:48px;right:0;width:320px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(26,58,107,0.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden}.notif-dropdown.open{display:block}.notif-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1.5px solid #f0f5fc}.notif-header-title{font-size:15px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif}.notif-empty{padding:28px 18px;text-align:center;color:#b0c4d8;font-size:14px}
footer{background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);padding:28px 48px;display:flex;flex-direction:column;align-items:center;gap:14px;margin-top:40px}.footer-top{display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:center}.social-icon{width:42px;height:42px;border-radius:50%;border:1.5px solid rgba(255,255,255,0.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;font-family:'Playfair Display',serif;transition:background .2s}.social-icon:hover{background:rgba(255,255,255,0.15)}.footer-divider{width:1px;height:22px;background:rgba(255,255,255,0.3)}.footer-brand{display:flex;align-items:center;gap:8px;color:#fff;font-size:16px;font-weight:700;font-family:'Playfair Display',serif}.footer-email{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,0.9);font-size:14px;font-family:'Playfair Display',serif}.footer-bottom{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,0.7);font-size:13px;font-family:'Playfair Display',serif;flex-wrap:wrap;justify-content:center}
/* exact customer-profile sidebar */
.sidebar{width:240px;min-height:calc(100vh - 72px);background:#2255a4;display:flex;flex-direction:column;padding:36px 24px 28px;flex-shrink:0}.sidebar-welcome{color:rgba(255,255,255,0.75);font-size:18px;font-weight:400;margin-bottom:4px}.sidebar-name{color:rgba(255,255,255,0.55);font-size:42px;font-weight:700;line-height:1.1;margin-bottom:36px}.sidebar-nav{display:flex;flex-direction:column;gap:16px;flex:1;background:transparent}.sidebar-link{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,0.75);text-decoration:none;font-size:16px;font-weight:400;padding:10px 8px;border-radius:0;transition:color .2s;background:none !important;-webkit-tap-highlight-color:transparent}.sidebar-link:hover{color:#fff;background:none !important}.sidebar-link.active{color:#fff !important;font-weight:700;border-bottom:2px solid rgba(255,255,255,0.5);background:none !important;padding-bottom:6px}.sidebar-link svg{flex-shrink:0;opacity:.8}.sidebar-link.active svg{opacity:1}.sidebar-logout{margin-top:24px;background:#fff;color:#1a3a6b;border:none;border-radius:50px;padding:12px 0;font-size:16px;font-weight:700;font-family:'Playfair Display',serif;cursor:pointer;width:100%;transition:background .2s;text-align:center}.sidebar-logout:hover{background:#e8f0ff}.sidebar-footer{margin-top:24px;padding-top:18px;border-top:1px solid rgba(255,255,255,0.15);display:flex;flex-direction:column;gap:12px;align-items:center}.sidebar-footer-social{display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap}.sidebar-social-icon{width:30px;height:30px;border-radius:50%;border:1.5px solid rgba(255,255,255,0.45);display:flex;align-items:center;justify-content:center;color:rgba(255,255,255,0.8);font-size:12px;font-weight:700;text-decoration:none;transition:background .2s;flex-shrink:0}.sidebar-social-icon:hover{background:rgba(255,255,255,0.15);color:#fff}.sidebar-footer-email{display:flex;align-items:center;justify-content:center;gap:6px;color:rgba(255,255,255,0.7);font-size:11px}.sidebar-footer-copy{color:rgba(255,255,255,0.5);font-size:11px;display:flex;align-items:center;justify-content:center;gap:6px;flex-wrap:wrap}
@media (max-width: 900px){.page-title{font-size:48px}.nav-center{display:none}.place-grid .provider-row{grid-template-columns:1fr}.content-with-sidebar{grid-template-columns:1fr}.sidebar{display:none}.detail-item,.provider-card,.order-row{flex-direction:column;align-items:flex-start}.provider-location-wrap{grid-template-columns:1fr}.order-right{text-align:left}.summary-panel{padding:24px 20px}.summary-row{font-size:24px}nav{padding:0 18px}.nav-logo{height:74px}.nav-search-wrap input{width:160px}.nav-search-wrap input:focus{width:190px}footer{padding:24px 18px}}
</style>
<?php }
?>
<?php
$ticketModel = new SupportTicket();
$errors=[]; $success='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    $reason = trim($_POST['reason'] ?? '');
    $description = trim($_POST['description'] ?? '');
    if (!in_array($reason, SupportTicket::REASONS, true)) $errors['reason']='Select a valid reason.';
    if (mb_strlen($description) < 10) $errors['description']='Please write a little more about the issue.';
    if (!$errors) { $ticketModel->create($customerId, ['reason'=>$reason,'description'=>$description]); $success='Your issue has been submitted successfully.'; }
}
$tickets = $ticketModel->getByCustomer($customerId);
?>
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Contact Us</title><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet"><?php rp_page_styles(); ?></head><body>
<nav class="topbar" style="position:static;">
  <div class="topbar-left">
    <a href="../shared/landing.php">
      <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate">
    </a>

    <a href="cart.php" class="nav-cart">
      <img src="../../images/Shopping cart.png" alt="Cart">
    </a>
  </div>

  <div class="topbar-center">
    <a href="../shared/landing.php">Home Page</a>
    <a href="category.php">Categories</a>
    <a href="providers-list.php">Providers</a>
  </div>

  <div class="topbar-right">
    <div class="search-pill">
      <span>⌕</span>
      <input type="text" placeholder="Search......" readonly>
    </div>

    <a href="customer-profile.php" class="avatar-pill">◯</a>
  </div>
</nav>
<div class="content-with-sidebar"><?php rp_sidebar($firstName, 'contact'); ?><div class="page-wrap" style="max-width:none;padding-top:14px;"><h1 class="page-title" style="font-size:60px;margin:0 0 14px 52px;">Contact us</h1><div class="card-shell contact-card" style="margin-left:52px;">
<?php if ($success): ?><div class="alert" style="margin-bottom:18px;"><?= rp_h($success) ?></div><?php endif; ?>
<form method="post">
  <div class="field-label">Reason</div>
  <select class="select-box" name="reason">
    <option value="">Choose reason</option>
    <?php foreach (SupportTicket::REASONS as $reason): ?><option value="<?= rp_h($reason) ?>" <?= (($_POST['reason'] ?? '') === $reason)?'selected':'' ?>><?= rp_h($reason) ?></option><?php endforeach; ?>
  </select>
  <?php if (!empty($errors['reason'])): ?><div style="color:#c14f4f;margin-top:6px;"><?= rp_h($errors['reason']) ?></div><?php endif; ?>
  <div class="field-label">Description</div>
  <textarea class="text-box" name="description" placeholder="Tell us about your problem…. "><?= rp_h($_POST['description'] ?? '') ?></textarea>
  <?php if (!empty($errors['description'])): ?><div style="color:#c14f4f;margin-top:6px;"><?= rp_h($errors['description']) ?></div><?php endif; ?>
  <button class="submit-btn" type="submit">Submit</button>
</form>
<?php if ($tickets): ?><div class="ticket-list"><?php foreach ($tickets as $t): ?><div class="ticket-card"><div class="ticket-top"><div class="ticket-reason"><?= rp_h($t['reason']) ?></div><div class="ticket-status <?= ($t['status'] ?? '')==='open'?'open':'' ?>"><?= ucfirst(rp_h($t['status'] ?? 'open')) ?></div></div><div class="ticket-desc"><?= nl2br(rp_h($t['description'])) ?></div>function rp_dt($dt, $fmt='d M Y, h:i A'){
    if ($dt instanceof MongoDB\BSON\UTCDateTime) return $dt->toDateTime()->format($fmt);
    if (is_numeric($dt)) return date($fmt, (int)$dt);
    if ($dt) return date($fmt, strtotime((string)$dt));
    return '';
}</div><?php endforeach; ?></div><?php endif; ?>
</div></div></div><?php rp_footer(); ?><script>
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
      dropdown.classList.add('open');
      return;
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
        .then(r => r.json())
        .then(render)
        .catch(() => { dropdown.innerHTML = '<div class="search-empty">Search is unavailable right now</div>'; dropdown.classList.add('open'); });
    }, 220);
  });
  document.addEventListener('click', function(e){
    const notif = document.getElementById('notifDropdown');
    const bellWrap = document.querySelector('.nav-bell-wrap');
    if(notif && bellWrap && !bellWrap.contains(e.target)) notif.classList.remove('open');
    if(!wrap.contains(e.target)) dropdown.classList.remove('open');
  });
})();
</script></body></html>
