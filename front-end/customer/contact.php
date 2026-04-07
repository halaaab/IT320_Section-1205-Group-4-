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
$customer   = (new Customer())->findById($customerId);
$firstName  = explode(' ', trim($customer['fullName'] ?? ($_SESSION['userName'] ?? 'Customer')))[0] ?: 'Customer';

// ── Load notifications + cart count ──
$notifications = []; $unreadCount = 0; $cartCount = 0;
try {
    $nm_ = new Notification();
    $notifications = (array)$nm_->getByCustomer($customerId);
    $unreadCount   = (int)$nm_->getUnreadCount($customerId);
} catch (Throwable) {}
try {
    $cm_ = new Cart(); $ct_ = $cm_->getOrCreate($customerId);
    $cartCount = array_sum(array_map(fn($ci)=>(int)($ci['quantity']??1),(array)($ct_['cartItems']??[])));
} catch (Throwable) {}
$alertCount = $unreadCount;

function rp_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function rp_dt($dt, $fmt='j F Y  g:ia'){
    $tz = new DateTimeZone('Asia/Riyadh');
    if ($dt instanceof MongoDB\BSON\UTCDateTime) { $d=$dt->toDateTime(); $d->setTimezone($tz); return $d->format($fmt); }
    if (is_numeric($dt)) { $d=new DateTime('@'.(int)$dt); $d->setTimezone($tz); return $d->format($fmt); }
    if ($dt) { $d=new DateTime((string)$dt); $d->setTimezone($tz); return $d->format($fmt); }
    return '';
}

$ticketModel = new SupportTicket();
$errors = []; $success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason      = trim($_POST['reason']      ?? '');
    $description = trim($_POST['description'] ?? '');
    if (!in_array($reason, SupportTicket::REASONS, true)) $errors['reason'] = 'Select a valid reason.';
    if (mb_strlen($description) < 10) $errors['description'] = 'Please write a little more about the issue.';
    if (!$errors) {
        $ticketModel->create($customerId, ['reason'=>$reason,'description'=>$description]);
        header('Location: contact.php?success=1');
        exit;
    }
}
$success = isset($_GET['success']) ? 'Your issue has been submitted successfully.' : '';
$tickets = $ticketModel->getByCustomer($customerId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RePlate – Contact Us</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet"/>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Playfair Display', serif; background: #e8eef5; min-height: 100vh; display: flex; flex-direction: column; }
    a { text-decoration: none; }

        nav.navbar { display: flex; align-items: center; justify-content: space-between; padding: 0 40px; height: 72px; background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%); position: sticky; top: 0; z-index: 10000; box-shadow: 0 2px 16px rgba(26,58,107,0.18); }
    .nav-logo { height: 100px; }
    .nav-left { display: flex; align-items: center; gap: 16px; }
    .nav-cart-wrap { position: relative; display: flex; align-items: center; }
    .nav-cart { width: 40px; height: 40px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.7); display: flex; align-items: center; justify-content: center; text-decoration: none; transition: background 0.2s; }
    .nav-cart:hover { background: rgba(255,255,255,0.15); }
    .cart-badge { position: absolute; top: -5px; right: -5px; min-width: 19px; height: 19px; background: #e53935; border-radius: 50%; border: 2px solid #2255a4; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: #fff; pointer-events: none; }
    .bell-badge { position: absolute; top: -3px; right: -3px; min-width: 18px; height: 18px; background: #e53935; border-radius: 50%; border: 2px solid #2255a4; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: #fff; pointer-events: none; }
    .nav-center { display: flex; align-items: center; gap: 40px; }
    .nav-center a { color: rgba(255,255,255,0.85); text-decoration: none; font-weight: 500; font-size: 15px; transition: color 0.2s; }
    .nav-center a:hover { color: #fff; }
    .nav-right { display: flex; align-items: center; gap: 12px; }
    .nav-search-wrap { position: relative; }
    .nav-search-wrap svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); opacity: 0.6; pointer-events: none; }
    .nav-search-wrap input { background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.4); border-radius: 50px; padding: 9px 16px 9px 36px; color: #fff; font-size: 14px; outline: none; width: 240px; font-family: 'Playfair Display', serif; transition: width 0.3s, background 0.2s; }
    .nav-search-wrap input::placeholder { color: rgba(255,255,255,0.6); }
    .nav-search-wrap input:focus { width: 300px; background: rgba(255,255,255,0.25); }
    .search-dropdown { display: none; position: absolute; top: calc(100% + 10px); right: 0; width: 380px; background: #fff; border-radius: 16px; box-shadow: 0 8px 40px rgba(26,58,107,0.18); border: 1.5px solid #e0eaf5; z-index: 9999; overflow: hidden; }
    .search-dropdown.open { display: block; }
    .search-section-label { font-size: 11px; font-weight: 700; color: #b0c4d8; letter-spacing: 0.08em; text-transform: uppercase; padding: 12px 16px 6px; }
    .search-item-row { display: flex; align-items: center; gap: 12px; padding: 10px 16px; cursor: pointer; transition: background 0.15s; text-decoration: none; }
    .search-item-row:hover { background: #f0f6ff; }
    .search-thumb { width: 38px; height: 38px; border-radius: 10px; background: #e0eaf5; flex-shrink: 0; display: flex; align-items: center; justify-content: center; font-size: 18px; overflow: hidden; }
    .search-thumb img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
    .search-item-name { font-size: 14px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; }
    .search-item-sub { font-size: 12px; color: #7a8fa8; }
    .search-price { margin-left: auto; font-size: 13px; font-weight: 700; color: #e07a1a; white-space: nowrap; }
    .search-divider { height: 1px; background: #f0f5fc; margin: 4px 0; }
    .search-empty { padding: 24px 16px; text-align: center; color: #b0c4d8; font-size: 14px; }
    .search-no-match { padding: 8px 16px 12px; font-size: 13px; color: #b0c4d8; font-style: italic; }
    .search-loading { padding: 18px 16px; text-align: center; color: #b0c4d8; font-size: 13px; }
    .search-provider-logo { width: 38px; height: 38px; border-radius: 50%; background: #e0eaf5; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; color: #2255a4; }
    .search-provider-logo img { width: 100%; height: 100%; object-fit: cover; }
    .nav-avatar { width: 38px; height: 38px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); display: flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; background: rgba(255,255,255,0.15); }
    .nav-avatar:hover { background: rgba(255,255,255,0.25); }
    .nav-bell-wrap { position: relative; }
    .nav-bell { width: 38px; height: 38px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); display: flex; align-items: center; justify-content: center; cursor: pointer; background: none; transition: background 0.2s; }
    .nav-bell:hover { background: rgba(255,255,255,0.15); }
    .notif-dropdown { display: none; position: absolute; top: 48px; right: 0; width: 360px; background: #fff; border-radius: 20px; box-shadow: 0 12px 48px rgba(26,58,107,0.18); border: 1.5px solid #e0eaf5; z-index: 99999; overflow: hidden; }
    .notif-dropdown.open { display: block; }
    .notif-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px 12px; border-bottom: 1.5px solid #f0f5fc; }
    .notif-header-title { font-size: 15px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; }
    .notif-empty { padding: 28px 18px; text-align: center; color: #b0c4d8; font-size: 14px; }
    .notif-item { display: flex; align-items: flex-start; gap: 12px; padding: 14px 18px; border-bottom: 1px solid #f5f8fc; transition: background 0.15s; }
    .notif-item:last-child { border-bottom: none; }
    .notif-item:hover { background: #f8fbff; }
    
.page-body { display: flex; flex: 1; }

    /* SIDEBAR — exact from customer-profile.php */
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

    /* MAIN */
    .main { flex: 1; padding: 40px 48px; display: flex; flex-direction: column; align-items: flex-start; }
    .page-title { font-size: 56px; font-weight: 700; color: #183482; margin: 0 0 28px; }
    .contact-layout { display: grid; grid-template-columns: min(480px,100%) 1fr; gap: 32px; width: 100%; align-items: start; }

    /* CONTACT CARD */
    .contact-card { background: #fff; border: 1.8px solid #d2dce8; border-radius: 28px; padding: 28px 32px; width: min(480px, 100%); box-shadow: 0 2px 12px rgba(26,58,107,0.06); }
    .field-label { font-size: 20px; font-weight: 700; color: #183482; margin: 18px 0 10px; }
    .field-label:first-child { margin-top: 0; }
    .select-box { width: 100%; border: 1.6px solid #c8d1dc; border-radius: 20px; background: #fff; color: #183482; font-family: 'Playfair Display', serif; padding: 14px 18px; font-size: 17px; outline: none; cursor: pointer; }
    .text-box { width: 100%; border: 1.6px solid #c8d1dc; border-radius: 20px; background: #fff; color: #183482; font-family: 'Playfair Display', serif; padding: 16px 18px; height: 140px; font-size: 16px; resize: vertical; outline: none; }
    .text-box::placeholder { color: #a0aec0; }
    .submit-btn { margin-top: 22px; background: #173993; color: #fff; border: none; border-radius: 18px; padding: 14px 40px; font-size: 20px; font-family: 'Playfair Display', serif; cursor: pointer; transition: background 0.2s; display: block; }
    .submit-btn:hover { background: #0f2874; }
    .alert-success { background: #fff8eb; border: 1px solid #f2c17d; color: #a05e00; border-radius: 16px; padding: 14px 18px; margin: 0 0 18px; font-size: 16px; }
    .field-error { color: #c14f4f; margin-top: 6px; font-size: 14px; }
    .ticket-list { margin-top: 28px; display: grid; gap: 14px; }
    .ticket-card { background: #f8fafc; border: 1.4px solid #d2dce8; border-radius: 18px; padding: 16px; }
    .ticket-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
    .ticket-reason { font-size: 18px; font-weight: 700; color: #183482; }
    .ticket-status { font-size: 13px; padding: 5px 12px; border-radius: 999px; background: #f1f6ff; color: #183482; }
    .ticket-status.open { background: #fff1e4; color: #d56f1f; }
    .ticket-desc { font-size: 15px; line-height: 1.5; color: #4d6186; }
    .ticket-date { margin-top: 8px; font-size: 12px; color: #7a86a1; }

    /* FOOTER */
    footer { background: linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%); padding: 28px 48px; display: flex; flex-direction: column; align-items: center; gap: 14px; }
    .footer-top { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; justify-content: center; }
    .social-icon { width: 42px; height: 42px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,0.5); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; text-decoration: none; transition: background 0.2s; }
    .social-icon:hover { background: rgba(255,255,255,0.15); }
    .footer-divider { width: 1px; height: 22px; background: rgba(255,255,255,0.3); }
    .footer-brand { display: flex; align-items: center; gap: 8px; color: #fff; font-size: 16px; font-weight: 700; }
    .footer-email { display: flex; align-items: center; gap: 6px; color: rgba(255,255,255,0.9); font-size: 14px; }
    .footer-bottom { display: flex; align-items: center; gap: 8px; color: rgba(255,255,255,0.7); font-size: 13px; flex-wrap: wrap; justify-content: center; }

    /* ── HAMBURGER ── */
    .hamburger{display:none;flex-direction:column;gap:5px;cursor:pointer;background:none;border:none;padding:6px}
    .hamburger span{display:block;width:24px;height:2.5px;background:#fff;border-radius:2px;transition:all .3s}
    .hamburger.open span:nth-child(1){transform:translateY(7.5px) rotate(45deg)}
    .hamburger.open span:nth-child(2){opacity:0}
    .hamburger.open span:nth-child(3){transform:translateY(-7.5px) rotate(-45deg)}
    .mobile-menu{display:none;position:fixed;inset:0;top:72px;background:linear-gradient(180deg,#1a3a6b 0%,#2255a4 100%);z-index:9999;flex-direction:column;padding:32px 28px;gap:0;overflow-y:auto}
    .mobile-menu.open{display:flex}
    .mobile-menu a{color:rgba(255,255,255,0.85);font-size:22px;font-weight:700;font-family:'Playfair Display',serif;padding:18px 0;border-bottom:1px solid rgba(255,255,255,0.12);text-decoration:none}
    .mobile-menu a:hover{color:#fff}
    .mobile-search{margin-bottom:16px;position:relative}
    .mobile-search svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);opacity:.6;pointer-events:none}
    .mobile-search input{width:100%;background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.4);border-radius:50px;padding:12px 16px 12px 40px;color:#fff;font-size:15px;outline:none;font-family:'Playfair Display',serif}
    .mobile-search input::placeholder{color:rgba(255,255,255,0.6)}
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

    @media(max-width:768px){
      nav.navbar{padding:0 18px}
      .nav-logo{height:74px}
      .nav-center{display:none}
      .nav-search-wrap{display:none}
      .hamburger{display:flex}
      .page-body{flex-direction:column}
      .sidebar{display:none}
      .main{padding:20px 16px}
      .page-title{font-size:36px}
      .contact-layout{grid-template-columns:1fr}
      footer{padding:24px 18px}
    }
  </style>
</head>
<body>

<nav class="navbar">
    <div class="nav-left">
      <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
      <div class="nav-cart-wrap">
        <a href="../customer/cart.php" class="nav-cart">
          <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;"/>
        </a>
        <?php if ($cartCount > 0): ?><span class="cart-badge"><?= $cartCount ?></span><?php endif; ?>
      </div>
    </div>
    <div class="nav-center">
      <a href="../shared/landing.php">Home Page</a>
      <a href="../shared/landing.php#categories">Categories</a>
      <a href="../shared/landing.php#providers">Providers</a>
    </div>
    <div class="nav-right">
      <div class="nav-search-wrap" id="searchWrap">
        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" id="searchInput" placeholder="Search products or providers..." autocomplete="off"/>
        <div class="search-dropdown" id="searchDropdown"></div>
      </div>
      <div class="nav-bell-wrap">
        <button class="nav-bell" onclick="toggleNotifDropdown()">
          <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        </button>
        <?php if ($unreadCount > 0): ?>
        <span class="bell-badge" id="bellBadge"><?= $unreadCount ?></span>
        <?php else: ?><span class="bell-badge" id="bellBadge" style="display:none">0</span><?php endif; ?>
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-header">
            <span class="notif-header-title">Notifications</span>
            <?php if ($unreadCount > 0): ?>
            <button class="notif-mark-all" onclick="markAllRead()" style="font-size:12px;color:#2255a4;background:none;border:none;cursor:pointer;font-family:'Playfair Display',serif;font-weight:600;">Mark all read</button>
            <?php endif; ?>
          </div>
          <div style="max-height:360px;overflow-y:auto;">
          <?php if (empty($notifications)): ?>
          <div class="notif-empty" style="padding:28px 16px;text-align:center;color:#b0c4d8;font-size:13px;">
            <svg width="30" height="30" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            You're all caught up!
          </div>
          <?php else: ?>
          <?php foreach (array_slice($notifications, 0, 8) as $notif_):
            $nIsRead_ = (bool)($notif_['isRead'] ?? false);
            $nMsg_    = htmlspecialchars($notif_['message'] ?? '');
            $nId_     = (string)($notif_['_id'] ?? '');
            $nType_   = $notif_['type'] ?? '';
            $nTime_   = '';
            try { if (!empty($notif_['createdAt'])) {
              $ts_ = $notif_['createdAt']->toDateTime()->getTimestamp();
              $d_  = time()-$ts_;
              $nTime_ = $d_<60 ? 'Just now' : ($d_<3600 ? floor($d_/60).'m ago' : ($d_<86400 ? floor($d_/3600).'h ago' : date('d M',$ts_)));
            }} catch(Throwable $e_) {}
            $nBl_     = $nIsRead_ ? '' : 'background:#fffaf5;border-left:3px solid #e07a1a;';
            $nIconBg_ = '#f2f4f8'; $nIconSvg_ = '';
            if ($nType_==='expiry_alert') {
                $rawN_    = $notif_['message'] ?? '';
                $urg_     = str_contains($rawN_,'[red]') ? 'red' : (str_contains($rawN_,'[orange]') ? 'orange' : 'yellow');
                $urgC_    = $urg_==='red' ? '#c0392b' : ($urg_==='orange' ? '#e07a1a' : '#d4ac0d');
                $nIconBg_ = $urg_==='red' ? '#fde8e8' : ($urg_==='orange' ? '#fff0e0' : '#fffbe6');
                $nIconSvg_= '<svg width="14" height="14" fill="none" stroke="' . $urgC_ . '" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>';
                $nBl_     = 'border-left:3px solid ' . $urgC_ . ';';
            }
            elseif ($nType_==='order_placed')    { $nIconBg_='#e8f7ee'; $nBl_='border-left:3px solid #1a6b3a;'; $nIconSvg_='<svg width="14" height="14" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/><polyline points="9 12 11 14 15 10"/></svg>'; }
            elseif ($nType_==='order_completed') { $nIconBg_='#e8f7ee'; $nBl_='border-left:3px solid #1a6b3a;'; $nIconSvg_='<svg width="14" height="14" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>'; }
            elseif ($nType_==='order_cancelled') { $nIconBg_='#fde8e8'; $nBl_='border-left:3px solid #e53935;'; $nIconSvg_='<svg width="14" height="14" fill="none" stroke="#e53935" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>'; }
            elseif ($nType_==='pickup_reminder') { $nIconBg_='#e8f0ff'; $nBl_='border-left:3px solid #2255a4;'; $nIconSvg_='<svg width="14" height="14" fill="none" stroke="#2255a4" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="3"/></svg>'; }
          ?>
          <div onclick="markRead(this)" data-id="<?= $nId_ ?>" style="display:flex;align-items:flex-start;gap:10px;padding:13px 16px;border-bottom:1px solid #f5f8fc;cursor:pointer;transition:background 0.15s;<?= $nBl_ ?>">
            <div style="width:32px;height:32px;border-radius:50%;background:<?= $nIconBg_ ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;"><?= $nIconSvg_ ?></div>
            <div style="flex:1;min-width:0;">
              <?php $nClean_ = trim(preg_replace('/\[(?:red|orange|yellow|pickup|completed|cancelled)\]\s*/', '', $nMsg_)); ?>
              <p style="font-size:12.5px;font-weight:<?= $nIsRead_?'500':'700' ?>;color:#1a3a6b;font-family:'Playfair Display',serif;margin-bottom:2px;line-height:1.4;"><?= htmlspecialchars($nClean_) ?></p>
              <span style="font-size:11px;color:#b0c4d8;"><?= $nTime_ ?></span>
            </div>
            <?php if (!$nIsRead_): ?><div class="unread-dot" style="width:7px;height:7px;background:#e07a1a;border-radius:50%;flex-shrink:0;margin-top:4px;"></div><?php endif; ?>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
          </div>
        </div>
      </div>
      <a href="customer-profile.php" class="nav-avatar">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
      <button id="hamburger" class="hamburger" onclick="toggleMobileMenu()" aria-label="Open menu">
        <span></span><span></span><span></span>
      </button>
    </div>
  </nav>
<div class="mobile-menu" id="mobileMenu">
  <div class="mobile-search">
    <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
    <input type="text" id="mobileSearchInput" placeholder="Search products or providers..." autocomplete="off"/>
  </div>
  <div id="mobileSearchDropdown" class="mobile-search-dropdown"></div>
  <a href="../shared/landing.php" onclick="closeMobileMenu()">Home</a>
  <a href="customer-profile.php" onclick="closeMobileMenu()">Profile</a>
  <a href="favorites.php" onclick="closeMobileMenu()">Favourites</a>
  <a href="orders.php" onclick="closeMobileMenu()">Orders</a>
  <a href="contact.php" onclick="closeMobileMenu()">Contact Us</a>
  <a href="customer-profile.php?logout=1" onclick="closeMobileMenu()">Log out</a>
</div>

<div class="page-body">

  <aside class="sidebar">
    <p class="sidebar-welcome">Welcome Back ,</p>
    <p class="sidebar-name"><?= rp_h($firstName) ?></p>
    <nav class="sidebar-nav">
      <a href="customer-profile.php" class="sidebar-link ">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        Profile
      </a>
      <a href="favorites.php" class="sidebar-link ">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
        Favourites
      </a>
      <a href="orders.php" class="sidebar-link ">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
        Orders
      </a>
      <a href="contact.php" class="sidebar-link active">
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

  <main class="main">
    <h1 class="page-title">Contact us</h1>

    <div class="contact-layout">
      <!-- LEFT: form -->
      <div class="contact-card">
        <?php if ($success): ?>
          <div class="alert-success"><?= rp_h($success) ?></div>
        <?php endif; ?>
        <form method="post">
          <div class="field-label">Reason</div>
          <select class="select-box" name="reason">
            <option value="">Choose reason</option>
            <?php foreach (SupportTicket::REASONS as $r): ?>
              <option value="<?= rp_h($r) ?>" <?= (($_POST['reason']??'')===$r)?'selected':'' ?>><?= rp_h($r) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['reason'])): ?><div class="field-error"><?= rp_h($errors['reason']) ?></div><?php endif; ?>
          <div class="field-label">Description</div>
          <textarea class="text-box" name="description" placeholder="Tell us about your problem…."><?= rp_h($_POST['description']??'') ?></textarea>
          <?php if (!empty($errors['description'])): ?><div class="field-error"><?= rp_h($errors['description']) ?></div><?php endif; ?>
          <button class="submit-btn" type="submit">Submit</button>
        </form>
      </div>

      <!-- RIGHT: tickets -->
      <div>
        <?php if ($tickets): ?>
        <div class="ticket-list" style="margin-top:0;">
          <?php foreach ($tickets as $t): ?>
          <div class="ticket-card">
            <div class="ticket-top">
              <div class="ticket-reason"><?= rp_h($t['reason']) ?></div>
            </div>
            <div class="ticket-desc"><?= nl2br(rp_h($t['description'])) ?></div>
            <div class="ticket-date">Submitted <?= rp_dt($t['submittedAt']) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </main>

</div>



<script>
function toggleNotifDropdown(){
  document.getElementById('notifDropdown').classList.toggle('open');
}
function markRead(el) {
  if (!el.dataset.id) return;
  el.style.background = ''; el.style.borderLeft = '';
  const dot = el.querySelector('.unread-dot'); if(dot) dot.remove();
  const badge = document.getElementById('bellBadge');
  if (badge) { const n=Math.max(0,(parseInt(badge.textContent)||0)-1); if(n===0) badge.style.display='none'; else badge.textContent=n; }
  fetch(window.location.pathname, {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'mark_read',notifId:el.dataset.id})}).catch(()=>{});
}
function markAllRead() {
  document.querySelectorAll('#notifDropdown [data-id]').forEach(el=>{
    el.style.background=''; el.style.borderLeft='';
    const d=el.querySelector('.unread-dot'); if(d) d.remove();
  });
  const b=document.getElementById('bellBadge'); if(b) b.style.display='none';
  fetch(window.location.pathname,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({action:'mark_all_read'})}).catch(()=>{});
}
(function(){
  const input=document.getElementById('searchInput'),dropdown=document.getElementById('searchDropdown'),wrap=document.getElementById('searchWrap');
  if(!input||!dropdown||!wrap) return;
  let timer=null;
  input.addEventListener('input',function(){
    clearTimeout(timer); const q=this.value.trim();
    if(q.length<2){dropdown.classList.remove('open');dropdown.innerHTML='';return;}
    dropdown.innerHTML='<div class="search-loading">Searching...</div>';dropdown.classList.add('open');
    timer=setTimeout(()=>{
      fetch('../../back-end/search.php?q='+encodeURIComponent(q)).then(r=>r.json()).then(data=>{
        const items=data.items||[],providers=data.providers||[];
        if(!items.length&&!providers.length){dropdown.innerHTML='<div class="search-empty">No matches found</div>';dropdown.classList.add('open');return;}
        let html='';
        if(providers.length){html+='<div class="search-section-label">Providers</div>';providers.forEach(p=>{const logo=p.businessLogo?`<div class="search-provider-logo"><img src="${p.businessLogo}"/></div>`:`<div class="search-provider-logo">${p.businessName.charAt(0)}</div>`;html+=`<a class="search-item-row" href="../customer/providers-page.php?providerId=${p.id}">${logo}<div><p class="search-item-name">${p.businessName}</p><p class="search-item-sub">${p.category||''}</p></div></a>`;});}
        if(items.length){html+='<div class="search-divider"></div><div class="search-section-label">Products</div>';items.forEach(item=>{const t=item.photoUrl?`<div class="search-thumb"><img src="${item.photoUrl}"/></div>`:'<div class="search-thumb">&#127837;</div>';html+=`<a class="search-item-row" href="../customer/item-details.php?itemId=${item.id}">${t}<div><p class="search-item-name">${item.name}</p></div><span class="search-price">${item.price||''}</span></a>`;});}
        dropdown.innerHTML=html;dropdown.classList.add('open');
      }).catch(()=>{dropdown.innerHTML='<div class="search-empty">Search unavailable</div>';dropdown.classList.add('open');});
    },220);
  });
  document.addEventListener('click',function(e){
    const bw=document.querySelector('.nav-bell-wrap');
    if(bw&&!bw.contains(e.target))document.getElementById('notifDropdown')?.classList.remove('open');
    if(!wrap.contains(e.target))dropdown.classList.remove('open');
  });
})();
function toggleMobileMenu(){
  const menu=document.getElementById('mobileMenu');
  const btn=document.getElementById('hamburger');
  menu.classList.toggle('open');
  btn.classList.toggle('open');
  document.body.style.overflow=menu.classList.contains('open')?'hidden':'';
}
function closeMobileMenu(){
  document.getElementById('mobileMenu')?.classList.remove('open');
  document.getElementById('hamburger')?.classList.remove('open');
  document.body.style.overflow='';
}
document.getElementById('mobileSearchInput')?.addEventListener('input', function(){
  const q = this.value.trim();
  const dd = document.getElementById('mobileSearchDropdown');
  if(!dd) return;
  if(q.length < 2){ dd.classList.remove('open'); dd.innerHTML=''; return; }
  dd.innerHTML = '<div style="padding:14px;text-align:center;color:#b0c4d8;font-size:13px;font-family:\'Playfair Display\',serif;">Searching...</div>';
  dd.classList.add('open');
  clearTimeout(window._mobTimer);
  window._mobTimer = setTimeout(()=>{
    fetch('../../back-end/search.php?q='+encodeURIComponent(q))
      .then(r=>r.json())
      .then(data=>{
        const items=data.items||[], providers=data.providers||[];
        if(!items.length&&!providers.length){
          dd.innerHTML='<div style="padding:14px;text-align:center;color:#b0c4d8;font-size:13px;">No matches found</div>';
          dd.classList.add('open'); return;
        }
        let html='';
        if(providers.length){
          html+='<div class="search-section-label">Providers</div>';
          providers.forEach(p=>{
            const logo=p.businessLogo
              ?`<div class="search-provider-logo"><img src="${p.businessLogo}"/></div>`
              :`<div class="search-provider-logo">${p.businessName.charAt(0)}</div>`;
            html+=`<a class="search-item-row" href="../customer/providers-page.php?providerId=${p.id}" onclick="closeMobileMenu()">${logo}<div><p class="search-item-name">${p.businessName}</p><p class="search-item-sub">${p.category||''}</p></div></a>`;
          });
        }
        if(items.length){
          if(providers.length) html+='<div class="search-divider"></div>';
          html+='<div class="search-section-label">Products</div>';
          items.forEach(item=>{
            const t=item.photoUrl
              ?`<div class="search-thumb"><img src="${item.photoUrl}"/></div>`
              :'<div class="search-thumb">&#127837;</div>';
            html+=`<a class="search-item-row" href="../customer/item-details.php?itemId=${item.id}" onclick="closeMobileMenu()">${t}<div><p class="search-item-name">${item.name}</p></div><span class="search-price">${item.price||''}</span></a>`;
          });
        }
        dd.innerHTML=html; dd.classList.add('open');
      })
      .catch(()=>{ dd.innerHTML='<div style="padding:14px;text-align:center;color:#b0c4d8;font-size:13px;">Search unavailable</div>'; dd.classList.add('open'); });
  }, 220);
});
</script>
</body>
</html>