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
<footer class="site-footer">
  <div class="footer-top">
    <div class="footer-socials">
      <a href="#">in</a><a href="#">X</a><a href="#">♪</a><span class="footer-brand">Replate</span>
    </div>
    <div class="footer-email">✉ <span>Replate@gmail.com</span></div>
  </div>
  <div class="footer-bottom">© 2026 <span>Replate</span> <span>All rights reserved.</span></div>
</footer>
<?php }
function rp_top_header($active='') { ?>
<nav class="topbar">
  <div class="topbar-left">
    <a href="../shared/landing.php"><img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"></a>
    <a href="../customer/cart.php" class="nav-cart"><img src="../../images/Shopping cart.png" alt="Cart"></a>
  </div>
  <div class="topbar-center">
    <a class="<?= $active==='home'?'active':'' ?>" href="../shared/landing.php">Home Page</a>
    <a class="<?= $active==='categories'?'active':'' ?>" href="../customer/category.php">Categories</a>
    <a class="<?= $active==='providers'?'active':'' ?>" href="../customer/providers-list.php">Providers</a>
  </div>
  <div class="topbar-right">
    <div class="search-pill"><span>⌕</span><input type="text" placeholder="Search......" readonly></div>
    <a href="customer-profile.php" class="avatar-pill">◯</a>
  </div>
</nav>
<?php }
function rp_sidebar($firstName, $active='contact'){ ?>
<div class="sidebar">
  <a href="../shared/landing.php"><img class="sidebar-logo" src="../../images/Replate-white.png" alt="RePlate"></a>
  <div class="sidebar-welcome">Welcome Back ,</div>
  <div class="sidebar-name"><?= rp_h($firstName) ?></div>
  <div class="sidebar-links">
    <a class="<?= $active==='profile'?'active':'' ?>" href="customer-profile.php">Profile</a>
    <a class="<?= $active==='favorites'?'active':'' ?>" href="favorites.php">Favourites</a>
    <a class="<?= $active==='orders'?'active':'' ?>" href="orders.php">Orders</a>
    <a class="<?= $active==='notification'?'active':'' ?>" href="customer-profile.php#notifications">Notification</a>
    <a class="<?= $active==='contact'?'active':'' ?>" href="contact.php">Contact Us</a>
  </div>
  <a class="sidebar-logout" href="customer-profile.php?logout=1">Log out</a>
  <div class="sidebar-mini-footer">© 2026 Replate · All rights reserved.</div>
</div>
<?php }
function rp_page_styles(){ ?>
<style>
*{box-sizing:border-box} html,body{margin:0;padding:0} body{background:#edf2f7;color:#1b2f74;font-family:'Playfair Display',serif} a{text-decoration:none}
.topbar{height:62px;background:linear-gradient(90deg,#1b3f92 0%,#2d63b8 55%,#6da4e5 100%);display:flex;align-items:center;justify-content:space-between;padding:0 18px;position:sticky;top:0;z-index:30}.topbar-left,.topbar-center,.topbar-right{display:flex;align-items:center}.topbar-left{gap:12px}.nav-logo{height:38px}.nav-cart{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:1.6px solid rgba(255,255,255,.75)}.nav-cart img{width:28px;height:28px}.topbar-center{gap:34px}.topbar-center a{color:#fff;font-size:14px;font-weight:600;opacity:.95}.topbar-center a.active{text-decoration:underline;text-underline-offset:8px}.topbar-right{gap:10px}.search-pill{display:flex;align-items:center;gap:8px;border:1.4px solid rgba(255,255,255,.55);border-radius:999px;padding:6px 12px;color:#fff;background:rgba(255,255,255,.07)}.search-pill input{border:none;background:transparent;color:#fff;width:140px;font-family:'Playfair Display',serif;outline:none}.search-pill input::placeholder{color:#dbe8ff}.avatar-pill{width:34px;height:34px;border-radius:50%;border:1.4px solid rgba(255,255,255,.55);display:flex;align-items:center;justify-content:center;color:#fff;background:rgba(255,255,255,.07)}
.page-wrap{max-width:1120px;margin:0 auto;padding:18px 20px 42px}.page-title-row{display:flex;align-items:center;gap:16px;margin:4px 0 18px}.back-btn{width:40px;height:40px;border-radius:50%;background:#dfe7f5;color:#1b3f92;display:flex;align-items:center;justify-content:center;font-size:34px;line-height:1}.page-title{font-size:68px;line-height:.95;margin:0;color:#183482;font-weight:700}
.card-shell{background:#eef3f8;border:1.8px solid #bcc8d8;border-radius:28px;padding:28px}.provider-block{background:#eef3f8;border:1.8px solid #bcc8d8;border-radius:28px;padding:20px 22px;margin-bottom:28px}.provider-logo-text{font-size:48px;line-height:1;color:#d56e3b;opacity:.95;margin:0 0 12px 6px;text-transform:uppercase;letter-spacing:1px}.provider-card{background:#f9fbfd;border:1.6px solid #c8d1dc;border-radius:24px;padding:16px 18px;display:flex;align-items:center;justify-content:space-between;gap:16px;margin:12px 0}.item-left{display:flex;align-items:center;gap:16px;min-width:0}.item-thumb{width:90px;height:70px;border-radius:18px;background:#fff;border:1.4px solid #ddd;object-fit:cover;display:block}.item-meta h3{margin:0;color:#5869aa;font-size:24px}.item-meta .price{margin-top:3px;color:#b7b3bc;font-size:18px}.qty-controls{display:flex;align-items:center;gap:12px;color:#eb8b24;font-size:24px;font-weight:700}.qty-btn{border:none;background:none;color:#eb8b24;font-size:24px;cursor:pointer;font-family:inherit;padding:0 2px}.remove-btn{border:none;background:none;color:#9ab1d8;font-size:24px;cursor:pointer;margin-left:8px}.primary-cta{display:block;width:min(370px,100%);margin:26px auto 0;background:#f6811f;color:#fff;border:none;border-radius:22px;padding:18px 20px;font-size:34px;font-family:'Playfair Display',serif;cursor:pointer;text-align:center}.secondary-cta{display:block;width:min(520px,100%);margin:16px auto 0;background:#173993;color:#fff;border:none;border-radius:22px;padding:18px 20px;font-size:32px;font-family:'Playfair Display',serif;cursor:pointer;text-align:center}.summary-panel{background:#dce6f7;border-radius:0;padding:34px 48px;margin-top:26px}.summary-row{display:flex;align-items:center;gap:10px;font-size:30px;font-weight:700;color:#173993}.summary-row .amount{color:#eb8b24;font-weight:400}
.segmented{display:flex;gap:22px;justify-content:center;margin-bottom:22px}.seg-btn{min-width:220px;padding:15px 26px;border-radius:22px;border:1.8px solid #ea8b2c;background:#fff;color:#183482;font-size:26px;font-family:'Playfair Display',serif}.seg-btn.active.orange{background:#f6811f;color:#fff}.seg-btn.active.blue{background:#f6811f;color:#fff}.order-row{background:#fcfcfd;border:1.6px solid #c8d1dc;border-radius:24px;padding:16px;display:flex;align-items:center;justify-content:space-between;gap:18px;margin:18px 0}.order-left{display:flex;align-items:center;gap:14px}.logo-box{width:146px;height:112px;border-radius:22px;border:1.4px solid #cfcfcf;background:#fff;display:flex;align-items:center;justify-content:center;padding:10px;text-align:center;font-size:34px;color:#d56e3b}.order-info h3{margin:0 0 8px;font-size:20px;color:#183482}.info-line{display:flex;align-items:center;gap:10px;color:#4166ad;font-size:15px;margin:6px 0}.order-right{min-width:160px;text-align:right}.order-total{color:#ea8b2c;font-size:22px;font-weight:700;margin-bottom:18px}.cancel-btn{display:inline-flex;align-items:center;justify-content:center;background:#f7a15d;color:#fff;border:none;border-radius:14px;padding:10px 26px;font-size:20px;font-family:'Playfair Display',serif;cursor:pointer}.detail-hero{background:#eef3f8;border:1.8px solid #bcc8d8;border-radius:28px;padding:22px}.hero-top{display:flex;align-items:center;gap:22px;margin-bottom:26px}.hero-brand{display:flex;align-items:center;gap:20px}.hero-logo{width:180px;height:140px;border-radius:22px;background:#fff;border:1.4px solid #cfcfcf;display:flex;align-items:center;justify-content:center;font-size:54px;color:#c84e3a;text-align:center}.hero-name{font-size:40px;font-weight:700;color:#183482}.status-badge{display:inline-block;background:#efd15f;color:#183482;border-radius:6px;padding:6px 18px;font-size:18px;margin-top:8px}.detail-item{background:#fcfcfd;border:1.6px solid #c8d1dc;border-radius:24px;padding:18px;display:flex;align-items:center;justify-content:space-between;gap:18px;margin:14px 0}.detail-text h4{margin:0 0 6px;font-size:20px;color:#183482}.detail-text p{margin:0 0 10px;font-size:17px;line-height:1.35;color:#28457a}.detail-qty{font-size:18px;font-weight:700;color:#183482}.detail-price{font-size:22px;color:#ea8b2c;font-weight:700}.detail-meta{margin-top:20px;font-size:18px;line-height:1.9;color:#183482}.detail-meta strong{font-size:24px}.place-grid .provider-row{display:grid;grid-template-columns:1.2fr .9fr;gap:24px}.mini-map{width:150px;height:125px;border-radius:22px;border:1.4px solid #c8d1dc;background:linear-gradient(135deg,#86b1d8 0%,#d5e6f1 100%);position:relative;overflow:hidden}.mini-map:before,.mini-map:after{content:'';position:absolute;background:rgba(255,255,255,.5)}.mini-map:before{inset:18px 0 auto 0;height:10px;transform:rotate(-24deg)}.mini-map:after{inset:auto 0 34px 0;height:10px;transform:rotate(26deg)}.pin{position:absolute;left:58%;top:32%;font-size:30px}.pickup-card{display:flex;flex-direction:column;align-items:center;gap:8px}.pickup-title{font-size:20px;font-weight:700;color:#183482;text-align:center}.pickup-address{font-size:13px;color:#4e607e;width:150px}.modal-backdrop{position:fixed;inset:0;background:rgba(237,242,247,.72);display:flex;align-items:center;justify-content:center;z-index:100}.modal{background:#fff;border:1.6px solid #bcc8d8;border-radius:22px;box-shadow:0 10px 30px rgba(0,0,0,.06)}.success-modal{width:min(470px,92vw);padding:86px 24px;text-align:center;font-size:28px;font-weight:700;color:#3e62b6}.confirm-modal{width:min(440px,92vw);overflow:hidden}.confirm-body{padding:34px 28px;text-align:center;font-size:26px;font-weight:700;color:#3e62b6;line-height:1.35}.confirm-actions{display:grid;grid-template-columns:1fr 1fr;border-top:1.4px solid #c8d1dc}.confirm-actions form,.confirm-actions a{display:flex;align-items:center;justify-content:center;height:86px;font-size:30px}.yes-btn{color:#2eb35c}.no-btn{color:#d65252}
.content-with-sidebar{display:grid;grid-template-columns:240px 1fr;min-height:calc(100vh - 62px)}.sidebar{background:linear-gradient(180deg,#1d4098 0%,#2e66bf 100%);padding:16px 14px 18px;display:flex;flex-direction:column}.sidebar-logo{width:112px;margin-bottom:18px}.sidebar-welcome{color:#fff;font-size:18px;opacity:.95}.sidebar-name{color:#cfd9f5;font-size:48px;line-height:.92;font-weight:700;margin:6px 0 24px}.sidebar-links{display:flex;flex-direction:column;gap:18px;margin-top:8px}.sidebar-links a{color:#eef4ff;font-size:18px;padding:6px 0;border-bottom:1px solid transparent}.sidebar-links a.active{border-bottom-color:#fff}.sidebar-logout{margin-top:auto;display:block;background:#fff;color:#183482;border-radius:18px;text-align:center;padding:10px 12px;font-weight:700}.sidebar-mini-footer{margin-top:14px;color:#dce6ff;font-size:10px;text-align:center}.contact-card{width:min(540px,100%)}.field-label{font-size:22px;font-weight:700;color:#183482;margin:16px 0 10px}.select-box, .text-box{width:100%;border:1.6px solid #c8d1dc;border-radius:22px;background:#fff;color:#183482;font-family:'Playfair Display',serif}.select-box{padding:14px 18px;font-size:18px}.text-box{padding:18px;height:130px;font-size:17px;resize:vertical}.submit-btn{margin-top:22px;background:#173993;color:#fff;border:none;border-radius:18px;padding:12px 32px;font-size:22px;font-family:'Playfair Display',serif;cursor:pointer}.ticket-list{margin-top:28px;display:grid;gap:16px}.ticket-card{background:#fff;border:1.4px solid #c8d1dc;border-radius:20px;padding:16px}.ticket-top{display:flex;align-items:center;justify-content:space-between;margin-bottom:8px}.ticket-reason{font-size:20px;font-weight:700;color:#183482}.ticket-status{font-size:14px;padding:6px 12px;border-radius:999px;background:#f1f6ff;color:#183482}.ticket-status.open{background:#fff1e4;color:#d56f1f}.ticket-desc{font-size:16px;line-height:1.45;color:#4d6186}.ticket-date{margin-top:8px;font-size:13px;color:#7a86a1}.alert{background:#fff8eb;border:1px solid #f2c17d;color:#a05e00;border-radius:16px;padding:14px 18px;margin:0 0 16px}.site-footer{background:linear-gradient(90deg,#163992 0%,#2a58b4 55%,#7bb0ea 100%);padding:14px 20px 12px;color:#fff;margin-top:40px}.footer-top,.footer-bottom{display:flex;align-items:center;justify-content:center;gap:18px;flex-wrap:wrap}.footer-socials{display:flex;align-items:center;gap:10px}.footer-socials a{width:22px;height:22px;border-radius:50%;border:1px solid rgba(255,255,255,.7);display:flex;align-items:center;justify-content:center;color:#fff;font-size:11px}.footer-brand,.footer-email,.footer-bottom{font-size:14px}
@media (max-width: 900px){.page-title{font-size:48px}.topbar-center{display:none}.place-grid .provider-row{grid-template-columns:1fr}.content-with-sidebar{grid-template-columns:1fr}.sidebar{display:none}.detail-item,.provider-card,.order-row{flex-direction:column;align-items:flex-start}.order-right{text-align:left}.summary-panel{padding:24px 20px}.summary-row{font-size:24px}}
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
<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Orders</title><link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet"><?php rp_page_styles(); ?></head><body><?php rp_top_header(); ?>
<div class="page-wrap"><div class="page-title-row"><a class="back-btn" href="../shared/landing.php">‹</a><h1 class="page-title">Orders</h1></div>
<div class="card-shell"><div class="segmented"><a class="seg-btn <?= $tab==='currently'?'active orange':'' ?>" href="orders.php?tab=currently">Currently</a><a class="seg-btn <?= $tab==='previously'?'active orange':'' ?>" href="orders.php?tab=previously">Previously</a></div>
<?php if (!$orders): ?><div style="text-align:center;padding:32px 12px;color:#6d7da0;font-size:24px;">No <?= $tab ?> orders yet.</div><?php endif; ?>
<?php foreach ($orders as $row): $o=$row['order']; $first=$row['first']; $oid=rp_oid($o['_id']); ?>
<a class="order-row" href="order-details.php?orderId=<?= rp_h($oid) ?>">
  <div class="order-left"><div class="logo-box"><?= rp_h(strtoupper($first['providerName'] ?? 'Store')) ?></div><div class="order-info"><h3><?= rp_h($first['providerName'] ?? 'Store') ?></h3><div class="info-line">🕒 <span><?= rp_dt($o['placedAt']) ?></span></div><div class="info-line">🪪 <span>Order number: <?= rp_h($o['orderNumber'] ?? '') ?></span></div></div></div>
  <div class="order-right"><div class="order-total">﷼ <?= rp_money($o['totalAmount'] ?? 0) ?></div><?php if (($o['orderStatus'] ?? '')==='pending'): ?><span class="cancel-btn" onclick="event.preventDefault();event.stopPropagation();window.location='orders.php?tab=currently&confirm=<?= rp_h($oid) ?>';">Cancel</span><?php endif; ?></div>
</a>
<?php endforeach; ?></div></div>
<?php if ($showCancel): ?><div class="modal-backdrop"><div class="modal confirm-modal"><div class="confirm-body">Are you sure you want to<br>cancel your order</div><div class="confirm-actions"><form method="post"><input type="hidden" name="action" value="cancel"><input type="hidden" name="orderId" value="<?= rp_h($showCancel) ?>"><button class="yes-btn" style="border:none;background:none;font:inherit;cursor:pointer;">Yes</button></form><a class="no-btn" href="orders.php?tab=currently">No</a></div></div></div><?php endif; ?>
<?php rp_footer(); ?></body></html>
