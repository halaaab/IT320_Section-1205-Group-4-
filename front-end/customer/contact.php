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
    $tz = new DateTimeZone('Asia/Riyadh');
    if ($dt instanceof MongoDB\BSON\UTCDateTime) {
        $d = $dt->toDateTime();
        $d->setTimezone($tz);
        return $d->format($fmt);
    }
    if (is_numeric($dt)) {
        $d = new DateTime('@' . (int)$dt);
        $d->setTimezone($tz);
        return $d->format($fmt);
    }
    if ($dt) {
        $d = new DateTime((string)$dt);
        $d->setTimezone($tz);
        return $d->format($fmt);
    }
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

function rp_sidebar($firstName, $active='contact'){ ?>
<aside class="sidebar">
  <p class="sidebar-welcome">Welcome Back ,</p>
  <p class="sidebar-name"><?= rp_h($firstName) ?></p>
  <nav class="sidebar-nav">
    <a href="customer-profile.php" class="sidebar-link <?= $active==='profile'?'active':'' ?>">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      Profile
    </a>
    <a href="favorites.php" class="sidebar-link <?= $active==='favorites'?'active':'' ?>">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
      Favourites
    </a>
    <a href="orders.php" class="sidebar-link <?= $active==='orders'?'active':'' ?>">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
      Orders
    </a>
    <a href="#" class="sidebar-link <?= $active==='notification'?'active':'' ?>">
      <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
      Notification
    </a>
    <a href="contact.php" class="sidebar-link <?= $active==='contact'?'active':'' ?>">
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
.search-item-name{font-size:14px;font-weight:700;color:#1a3a6b}
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
.notif-dropdown{display:none;position:absolute;top:48px;right:0;width:320px;background:#fff;border-radius:16px;box-shadow:0 8px 40px rgba(26,58,107,.18);border:1.5px solid #e0eaf5;z-index:9999;overflow:hidden}
.notif-dropdown.open{display:block}
.notif-header{display:flex;align-items:center;justify-content:space-between;padding:16px 18px 12px;border-bottom:1.5px solid #f0f5fc}
.notif-header-title{font-size:15px;font-weight:700;color:#1a3a6b}
.notif-empty{padding:28px 18px;text-align:center;color:#b0c4d8;font-size:14px}

/* ── SIDEBAR — exact copy from customer-profile.php ── */
.page-body { display: flex; flex: 1; }
.sidebar { width: 240px; min-height: calc(100vh - 72px); background: #2255a4; display: flex; flex-direction: column; padding: 36px 24px 28px; flex-shrink: 0; }
.sidebar-welcome { color: rgba(255,255,255,0.75); font-size: 18px; font-weight: 400; margin-bottom: 4px; }
.sidebar-name { color: rgba(255,255,255,0.55); font-size: 42px; font-weight: 700; line-height: 1.1; margin-bottom: 36px; }
.sidebar-nav { display: flex; flex-direction: column; gap: 16px; flex: 1; background: transparent; }
.sidebar-link { display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,0.75); text-decoration: none; font-size: 16px; font-weight: 400; padding: 10px 8px; border-radius: 0; transition: color 0.2s; background: none !important; -webkit-tap-highlight-color: transparent; }
.sidebar-link:hover { color: #fff; background: none !important; }
.sidebar-link.active { color: #fff !important; font-weight: 700; border-bottom: 2px solid rgba(255,255,255,0.5); background: none !important; padding-bottom: 6px; }
.sidebar-link svg { flex-shrink: 0; opacity: 0.8; }
.sidebar-link.active svg { opacity: 1; }
.sidebar-logout { margin-top: 24px; background: #fff; color: #1a3a6b; border: none; border-radius: 50px; padding: 12px 0; font-size: 16px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; width: 100%; transition: background 0.2s; text-align: center; }
.sidebar-logout:hover { background: #e8f0ff; }
.sidebar-footer { margin-top: 24px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,0.15); display: flex; flex-direction: column; gap: 12px; align-items: center; }
.sidebar-footer-social { display: flex; align-items: center; justify-content: center; gap: 8px; flex-wrap: wrap; }
.sidebar-social-icon { width: 30px; height: 30px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,0.45); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,0.8); font-size: 12px; font-weight: 700; text-decoration: none; transition: background 0.2s; flex-shrink: 0; }
.sidebar-social-icon:hover { background: rgba(255,255,255,0.15); color: #fff; }
.sidebar-footer-email { display: flex; align-items: center; justify-content: center; gap: 6px; color: rgba(255,255,255,0.7); font-size: 11px; }
.sidebar-footer-copy { color: rgba(255,255,255,0.5); font-size: 11px; display: flex; align-items: center; justify-content: center; gap: 6px; flex-wrap: wrap; }

/* ── MAIN CONTENT ── */
.main-content{flex:1;padding:32px 48px 60px}
.page-title{font-size:56px;font-weight:700;color:#183482;margin:0 0 28px;font-family:'Playfair Display',serif}

/* ── CONTACT CARD ── */
.contact-card{background:#fff;border:1.8px solid #d2dce8;border-radius:28px;padding:28px 32px;width:min(480px,100%);box-shadow:0 2px 12px rgba(26,58,107,.06)}
.field-label{font-size:20px;font-weight:700;color:#183482;margin:18px 0 10px}
.field-label:first-child{margin-top:0}

/* Select with visible options styling */
.select-box{width:100%;border:1.6px solid #c8d1dc;border-radius:20px;background:#fff;color:#183482;font-family:'Playfair Display',serif;padding:14px 18px;font-size:17px;outline:none;cursor:pointer;appearance:auto}
.select-box:focus{border-color:#5b7fc8;box-shadow:0 0 0 3px rgba(91,127,200,.15)}

.text-box{width:100%;border:1.6px solid #c8d1dc;border-radius:20px;background:#fff;color:#183482;font-family:'Playfair Display',serif;padding:16px 18px;height:140px;font-size:16px;resize:vertical;outline:none}
.text-box:focus{border-color:#5b7fc8;box-shadow:0 0 0 3px rgba(91,127,200,.15)}
.text-box::placeholder{color:#a0aec0}

.submit-btn{margin-top:22px;background:#173993;color:#fff;border:none;border-radius:18px;padding:14px 40px;font-size:20px;font-family:'Playfair Display',serif;cursor:pointer;transition:background .2s;display:block}
.submit-btn:hover{background:#0f2874}

/* Alert / success */
.alert{background:#fff8eb;border:1px solid #f2c17d;color:#a05e00;border-radius:16px;padding:14px 18px;margin:0 0 18px;font-size:16px}
.field-error{color:#c14f4f;margin-top:6px;font-size:14px}

/* Past tickets */
.ticket-list{margin-top:28px;display:grid;gap:14px}
.ticket-card{background:#f8fafc;border:1.4px solid #d2dce8;border-radius:18px;padding:16px}
.ticket-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}
.ticket-reason{font-size:18px;font-weight:700;color:#183482}
.ticket-status{font-size:13px;padding:5px 12px;border-radius:999px;background:#f1f6ff;color:#183482}
.ticket-status.open{background:#fff1e4;color:#d56f1f}
.ticket-desc{font-size:15px;line-height:1.5;color:#4d6186}
.ticket-date{margin-top:8px;font-size:12px;color:#7a86a1}

/* ── FOOTER ── */
footer{background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);padding:28px 48px;display:flex;flex-direction:column;align-items:center;gap:14px}
.footer-top{display:flex;align-items:center;gap:18px;flex-wrap:wrap;justify-content:center}
.social-icon{width:42px;height:42px;border-radius:50%;border:1.5px solid rgba(255,255,255,.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:16px;font-weight:700;cursor:pointer;text-decoration:none;transition:background .2s}
.social-icon:hover{background:rgba(255,255,255,.15)}
.footer-divider{width:1px;height:22px;background:rgba(255,255,255,.3)}
.footer-brand{display:flex;align-items:center;gap:8px;color:#fff;font-size:16px;font-weight:700}
.footer-email{display:flex;align-items:center;gap:6px;color:rgba(255,255,255,.9);font-size:14px}
.footer-bottom{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,.7);font-size:13px;flex-wrap:wrap;justify-content:center}

@media(max-width:900px){
  .page-body{flex-direction:column}
  .sidebar{width:100%;min-height:auto;padding:20px 20px 16px}
  .sidebar-name{font-size:26px;margin-bottom:12px}
  .sidebar-nav{flex-direction:row;flex-wrap:wrap;gap:4px}
  .sidebar-link{padding:7px 10px;font-size:14px;white-space:nowrap}
  .sidebar-logout{margin-top:12px;padding:9px 0;font-size:14px}
  .sidebar-footer{margin-top:12px;padding-top:10px}
  .main-content{padding:24px 20px 40px}
  nav{padding:0 18px}
  .nav-center{display:none}
  .nav-logo{height:74px}
  footer{padding:24px 18px}
}
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
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Contact Us – RePlate</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
  <?php rp_page_styles(); ?>
</head>
<body>
<?php rp_top_header(); ?>

<div class="page-body">
  <?php rp_sidebar($firstName, 'contact'); ?>

  <main class="main-content">
    <h1 class="page-title">Contact us</h1>

    <div class="contact-card">
      <?php if ($success): ?>
        <div class="alert"><?= rp_h($success) ?></div>
      <?php endif; ?>

      <form method="post">
        <div class="field-label">Reason</div>
        <select class="select-box" name="reason">
          <option value="">Choose reason</option>
          <?php foreach (SupportTicket::REASONS as $reason): ?>
            <option value="<?= rp_h($reason) ?>" <?= (($_POST['reason'] ?? '') === $reason)?'selected':'' ?>>
              <?= rp_h($reason) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['reason'])): ?>
          <div class="field-error"><?= rp_h($errors['reason']) ?></div>
        <?php endif; ?>

        <div class="field-label">Description</div>
        <textarea class="text-box" name="description" placeholder="Tell us about your problem…."><?= rp_h($_POST['description'] ?? '') ?></textarea>
        <?php if (!empty($errors['description'])): ?>
          <div class="field-error"><?= rp_h($errors['description']) ?></div>
        <?php endif; ?>

        <button class="submit-btn" type="submit">Submit</button>
      </form>

      <?php if ($tickets): ?>
      <div class="ticket-list">
        <?php foreach ($tickets as $t): ?>
        <div class="ticket-card">
          <div class="ticket-top">
            <div class="ticket-reason"><?= rp_h($t['reason']) ?></div>
            <div class="ticket-status <?= ($t['status'] ?? '')==='open'?'open':'' ?>"><?= ucfirst(rp_h($t['status'] ?? 'open')) ?></div>
          </div>
          <div class="ticket-desc"><?= nl2br(rp_h($t['description'])) ?></div>
          <div class="ticket-date">Submitted <?= rp_dt($t['submittedAt']) ?></div>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </main>
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
