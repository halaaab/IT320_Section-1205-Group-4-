
<?php
session_start();

require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Order.php';
require_once '../../back-end/models/OrderItem.php';

if (empty($_SESSION['providerId'])) {
header('Location: ../shared/login.php');
exit;
}

if (isset($_GET['logout'])) {
session_destroy();
header('Location: ../shared/landing.php');
exit;
}

$providerId = $_SESSION['providerId'];
$orderId = $_GET['orderId'] ?? '';
$itemId  = $_GET['itemId'] ?? '';

$providerModel = new Provider();
$orderModel = new Order();
$orderItemModel = new OrderItem();

$provider = $providerModel->findById($providerId);

$providerName = $provider['businessName'] ?? 'Provider';
$providerEmail = $provider['email'] ?? '';
$providerPhone = $provider['phoneNumber'] ?? '';
$providerLogo = $provider['businessLogo'] ?? '';
$firstName = explode(' ', $providerName)[0] ?? 'Provider';

if (empty($orderId)) {
die('Order not found.');
}

$order = $orderModel->findById($orderId);

if (!$order) {
die('Order not found.');
}

$orderItems = $orderItemModel->getByOrder($orderId);

/* نجيب فقط الآيتمات الخاصة بهذا البروفايدر */
$providerItems = [];

foreach ($orderItems as $item) {
if ((string)($item['providerId'] ?? '') === (string)$providerId) {
$providerItems[] = $item;
}
}

if (empty($providerItems)) {
die('You are not allowed to view this order.');
}

// بما أن التصميم كارد واحد، نعرض أول item افتراضياً
//$orderItem = $providerItems[0]; 

$orderItem = null;

if (!empty($itemId)) {
    foreach ($providerItems as $item) {
        if ((string)($item['_id'] ?? '') === (string)$itemId) {
            $orderItem = $item;
            break;
        }
    }
}

if (!$orderItem && !empty($providerItems)) {
    $orderItem = $providerItems[0];
}

if (!$orderItem) {
    die('Item not found or not allowed.');
}

$isDonation = ((float)($orderItem['price'] ?? 0) <= 0);
$itemStatus = strtolower(trim($orderItem['itemStatus'] ?? 'pending'));
$statusText = ($itemStatus === 'completed') ? 'Completed' : 'Pending';

$displayDate = '';
if (!empty($order['placedAt']) && $order['placedAt'] instanceof MongoDB\BSON\UTCDateTime) {
$displayDate = $order['placedAt']->toDateTime()->format('j F Y');
}

/* لو ضغط Mark As Completed */
 if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_completed'])) {
    $orderItemModel->updateById(
        (string)$orderItem['_id'],
        ['itemStatus' => 'completed']
    );

    // ── Notify the customer ──
    require_once '../../back-end/models/Notification.php';
    $customerId_ = (string)($order['customerId'] ?? '');
    if ($customerId_) {
        (new Notification())->notifyOrderCompleted(
            $customerId_,
            $orderId,
            $order['orderNumber'] ?? $orderId
        );
    }

    // Re-fetch provider items for this order after update
    $updatedOrderItems = $orderItemModel->getByOrder($orderId);
    $updatedProviderItems = [];
    foreach ($updatedOrderItems as $item) {
        if ((string)($item['providerId'] ?? '') === (string)$providerId) {
            $updatedProviderItems[] = $item;
        }
    }
    $allCompleted = true;
    foreach ($updatedProviderItems as $item) {
        if (strtolower(trim($item['itemStatus'] ?? 'pending')) !== 'completed') {
            $allCompleted = false;
            break;
        }
    }
    if ($allCompleted) {
        header('Location: provider-orders.php?tab=completed');
    } else {
        header('Location: provider-orders.php?tab=pending');
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Order #<?= htmlspecialchars($order['orderNumber'] ?? '') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Playfair Display', serif; background: #f4f7fc; min-height: 100vh; display: flex; flex-direction: column; }

    /* ── NAVBAR ── */
    nav.navbar {
      display: flex; align-items: center; justify-content: space-between;
      padding: 0 40px; height: 72px;
      background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%);
      position: sticky; top: 0; z-index: 100;
      box-shadow: 0 2px 16px rgba(26,58,107,0.18);
    }
    .nav-left { display: flex; align-items: center; gap: 0; }
    .nav-logo { height: 90px; }
    .nav-search-wrap { position: relative; }
    .nav-search-wrap svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); opacity: 0.6; pointer-events: none; }
    .nav-search-wrap input { background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.4); border-radius: 50px; padding: 10px 18px 10px 40px; color: #fff; font-size: 14px; outline: none; width: 260px; font-family: 'Playfair Display', serif; transition: width 0.3s, background 0.2s; }
    .nav-search-wrap input::placeholder { color: rgba(255,255,255,0.6); }
    .nav-search-wrap input:focus { width: 320px; background: rgba(255,255,255,0.25); }
    .nav-right { display: flex; align-items: center; gap: 14px; }
    .nav-provider-info { display: flex; align-items: center; gap: 14px; }
    .nav-provider-logo { width: 46px; height: 46px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #fff; overflow: hidden; flex-shrink: 0; }
    .nav-provider-logo img { width: 100%; height: 100%; object-fit: cover; }
    .nav-provider-text { display: flex; flex-direction: column; }
    .nav-provider-name { font-size: 15px; font-weight: 700; color: #fff; }
    .nav-provider-email { font-size: 12px; color: rgba(255,255,255,0.75); }

    /* ── LAYOUT ── */
    .page-body { display: flex; flex: 1; }

    /* ── SIDEBAR ── */
    .sidebar { width: 240px; min-height: calc(100vh - 72px); background: linear-gradient(180deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%); display: flex; flex-direction: column; padding: 36px 24px 28px; flex-shrink: 0; }
    .sidebar-welcome { color: rgba(255,255,255,0.75); font-size: 17px; font-weight: 400; margin-bottom: 4px; }
    .sidebar-name { color: rgba(255,255,255,0.55); font-size: 38px; font-weight: 700; line-height: 1.1; margin-bottom: 36px; }
    .sidebar-nav { display: flex; flex-direction: column; gap: 16px; flex: 1; }
    .sidebar-link { display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,0.75); text-decoration: none; font-size: 16px; font-weight: 400; padding: 10px 8px; border-radius: 0; transition: color 0.2s; background: none !important; -webkit-tap-highlight-color: transparent; }
    .sidebar-link:hover { color: #fff; }
    .sidebar-link.active { color: #fff !important; font-weight: 700; border-bottom: 2px solid rgba(255,255,255,0.5); background: none !important; padding-bottom: 6px; }
    .sidebar-link svg { flex-shrink: 0; opacity: 0.8; }
    .sidebar-link.active svg { opacity: 1; }
    .sidebar-logout { margin-top: 24px; background: #fff; color: #1a3a6b; border: none; border-radius: 50px; padding: 12px 0; font-size: 15px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; width: 100%; text-align: center; transition: background 0.2s; }
    .sidebar-logout:hover { background: #e8f0ff; }
    .sidebar-footer { margin-top: 24px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,0.15); display: flex; flex-direction: column; gap: 10px; align-items: center; }
    .sidebar-footer-social { display: flex; align-items: center; justify-content: center; gap: 8px; }
    .sidebar-social-icon { width: 28px; height: 28px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,0.4); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,0.8); font-size: 11px; font-weight: 700; text-decoration: none; transition: background 0.2s; }
    .sidebar-social-icon:hover { background: rgba(255,255,255,0.15); }
    .sidebar-footer-copy { color: rgba(255,255,255,0.45); font-size: 10px; display: flex; align-items: center; justify-content: center; gap: 4px; flex-wrap: wrap; }
    .main-content {
flex: 1;
padding: 30px 20px;
background: #eef3f9;
display: flex;
justify-content: center;
align-items: flex-start;
}

.order-details-wrapper {
width: 100%;
max-width: 620px;
display: flex;
flex-direction: column;
align-items: center;
flex-shrink: 0;
}

.order-title {
font-size: 30px;
color: #183482;
margin-bottom: 22px;
text-align: center;
font-weight: 700;
}

.order-details-card {
width: 100%;
background: #eef4fb;
border: 1.5px solid #cbd7e6;
border-radius: 24px;
padding: 22px 24px;
display: flex;
flex-direction: column;
align-items: center;
gap: 68px;
}

.order-details-img {
width: 95px;
height: 95px;
object-fit: contain;
object-position: center;
background: #fff;
display: block;
}

.order-details-placeholder {
width: 95px;
height: 95px;
border-radius: 14px;
background: #dce6f2;
display: flex;
align-items: center;
justify-content: center;
color: #6f86a8;
font-size: 13px;
text-align: center;
padding: 8px;
border: 1px solid #d7e1ee;
}

.order-details-info p {
font-size: 18px;
color: #183482;
margin-bottom: 12px;
line-height: 1.6;
}

.complete-form {
    width: 100%;
    display: flex;
    justify-content: center;   /* center button */
    margin-top: 10px;
}

.complete-btn {
background: #e48a2a;
color: #fff;
border: none;
border-radius: 999px;
padding: 11px 26px;
font-size: 18px;
font-weight: 700;
font-family: 'Playfair Display', serif;
cursor: pointer;
transition: 0.2s;
}

.complete-btn:hover {
background: #cf7720;
}

.currency-icon {
height: 14px;
object-fit: contain;
vertical-align: middle;
margin-left: 4px;
}
.page-header {
  margin-bottom: 28px;
  max-width: 900px;
  margin: 0 auto 20px auto;
}

.page-header h1 {
  font-size: 34px;
  font-weight: 700;
  font-family: 'Playfair Display', serif;
  background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
  -webkit-background-clip: text;
  -webkit-text-fill-color: transparent;
  background-clip: text;
  display: inline-block;
}

.page-header h1 span {
  -webkit-text-fill-color: transparent;
}
/* ── MOBILE HEADER / HAMBURGER ── */
.hamburger {
  display: none;
  flex-direction: column;
  gap: 5px;
  cursor: pointer;
  background: none;
  border: none;
  padding: 6px;
}

.hamburger span {
  display: block;
  width: 24px;
  height: 2.5px;
  background: #fff;
  border-radius: 2px;
  transition: all 0.3s;
}

.hamburger.open span:nth-child(1) {
  transform: translateY(7.5px) rotate(45deg);
}

.hamburger.open span:nth-child(2) {
  opacity: 0;
}

.hamburger.open span:nth-child(3) {
  transform: translateY(-7.5px) rotate(-45deg);
}

.mobile-menu {
  display: none;
  position: fixed;
  inset: 0;
  top: 72px;
  background: linear-gradient(180deg, #1a3a6b 0%, #2255a4 100%);
  z-index: 99;
  flex-direction: column;
  padding: 24px 20px;
}

.mobile-menu.open {
  display: flex;
}

.mobile-menu a {
  color: rgba(255,255,255,0.9);
  font-size: 22px;
  font-weight: 700;
  padding: 18px 0;
  border-bottom: 1px solid rgba(255,255,255,0.12);
  text-decoration: none;
}

.mobile-search {
  margin-top: 22px;
  position: relative;
}

.mobile-search svg {
  position: absolute;
  left: 14px;
  top: 50%;
  transform: translateY(-50%);
  opacity: 0.6;
  pointer-events: none;
}

.mobile-search input {
  width: 100%;
  background: rgba(255,255,255,0.15);
  border: 1.5px solid rgba(255,255,255,0.4);
  border-radius: 50px;
  padding: 12px 16px 12px 40px;
  color: #fff;
  outline: none;
  font-family: 'Playfair Display', serif;
}

.mobile-search input::placeholder {
  color: rgba(255,255,255,0.6);
}
.search-dropdown {
  display: none;
  position: absolute;
  top: calc(100% + 10px);
  left: 0;
  width: 360px;
  max-width: calc(100vw - 40px);
  background: #fff;
  border-radius: 18px;
  border: 1.5px solid #e0eaf5;
  box-shadow: 0 12px 40px rgba(26,58,107,0.18);
  z-index: 9999;
  overflow: hidden;
}

.search-dropdown.visible {
  display: block;
}

.sd-row {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 16px;
  text-decoration: none;
  color: inherit;
  transition: background 0.15s;
}

.sd-row:hover {
  background: #f4f8ff;
}

.sd-icon {
  width: 38px;
  height: 38px;
  border-radius: 10px;
  background: #edf3fb;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #1a3a6b;
  flex-shrink: 0;
}

.sd-info {
  min-width: 0;
}

.sd-name {
  font-size: 14px;
  font-weight: 700;
  color: #1a3a6b;
}

.sd-sub {
  font-size: 12px;
  color: #7a8fa8;
  margin-top: 2px;
}

.mobile-search {
  position: relative;
}

.mobile-search .search-dropdown {
  top: calc(100% + 12px);
  left: 0;
  width: 100%;
}

@media (max-width: 768px) {
  .hamburger {
    display: flex;
  }

  .sidebar {
    display: none;
  }

  .page-body {
    display: block;
  }

  .nav-search-wrap {
    display: none;
  }

  .nav-provider-text {
    display: none;
  }

  nav.navbar {
    padding: 0 16px;
  }

  .nav-logo {
    height: 70px;
  }

  .main-content {
    width: 100%;
    padding: 20px 16px;
    margin: 0;
    align-items: stretch;
  }

  .orders-page-wrap {
    width: 100%;
    max-width: 100%;
  }

  .tabs-row {
    display: flex;
    gap: 8px;
    width: 100%;
  }

  .tab-btn {
    flex: 1;
    min-width: 0;
    width: auto;
    padding: 10px 8px;
    font-size: 14px;
  }

  .orders-list {
    width: 100%;
    max-width: 100%;
    padding: 16px;
    gap: 16px;
  }

  .order-top,
  .order-left-block,
  .order-bottom {
    flex-direction: column;
    align-items: flex-start;
  }

  .order-item-img,
  .order-placeholder {
    width: 82px;
    height: 82px;
  }

  .order-price,
  .donation-text {
    margin-left: 0;
  }

  .view-order-btn {
    margin-right: 0;
  }
}
    </style>
</head>
<body>
 <nav class="navbar">
    <div class="nav-left">
      <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
    </div>
  <div class="nav-right">
  <div class="nav-search-wrap">
    <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
    <input type="text" placeholder="Search......"/>
  </div>

  <div class="nav-provider-info">
    <div class="nav-provider-logo">
      <?php if ($providerLogo): ?>
        <img src="<?= htmlspecialchars($providerLogo) ?>" alt="<?= htmlspecialchars($providerName) ?>"/>
      <?php else: ?>
        <?= mb_strtoupper(mb_substr($providerName, 0, 1)) ?>
      <?php endif; ?>
    </div>
    <div class="nav-provider-text">
      <span class="nav-provider-name"><?= htmlspecialchars($providerName) ?></span>
      <span class="nav-provider-email"><?= htmlspecialchars($providerEmail) ?></span>
    </div>
  </div>

  <button id="hamburger" class="hamburger" onclick="toggleMobileMenu()" aria-label="Open menu">
    <span></span>
    <span></span>
    <span></span>
  </button>
</div>
  </nav>
  <div class="mobile-menu" id="mobileMenu">
  <div class="mobile-search">
    <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="7"></circle>
      <path d="m21 21-4.3-4.3"></path>
    </svg>
    <input type="text" placeholder="Search..." />
  </div>

  <a href="provider-dashboard.php" onclick="closeMobileMenu()">Dashboard</a>
  <a href="provider-items.php" onclick="closeMobileMenu()">Items</a>
  <a href="provider-orders.php" onclick="closeMobileMenu()">Orders</a>
  <a href="provider-profile.php" onclick="closeMobileMenu()">Profile</a>
  <a href="provider-dashboard.php?logout=1" onclick="closeMobileMenu()">Log out</a>
</div>

  <div class="page-body">
    <aside class="sidebar">
      <p class="sidebar-welcome">Welcome Back ,</p>
      <p class="sidebar-name"><?= htmlspecialchars($firstName) ?></p>
      <nav class="sidebar-nav">
        <a href="provider-dashboard.php" class="sidebar-link ">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          Dashboard
        </a>
        <a href="provider-items.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 3H8a2 2 0 00-2 2v2h12V5a2 2 0 00-2-2z"/></svg>
          Items
        </a>
        <a href="provider-orders.php" class="sidebar-link active">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
          Orders
        </a>
        <a href="provider-profile.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
      </nav>
      <button class="sidebar-logout" onclick="window.location.href='provider-dashboard.php?logout=1'">Log out</button>
      <div class="sidebar-footer">
        <div class="sidebar-footer-social">
          <a href="#" class="sidebar-social-icon">in</a>
          <a href="#" class="sidebar-social-icon">&#120143;</a>
          <a href="#" class="sidebar-social-icon">&#9834;</a>
          
        </div>
        <div class="sidebar-footer-copy">
          <span>© 2026</span>
          <img src="../../images/Replate-white.png" alt="" style="height:40px;object-fit:contain;opacity:0.45;"/>
          <span>All rights reserved.</span>
        </div>
      </div>
    </aside>
    <main class="main-content">
<div class="order-details-wrapper">

<h1 class="order-title">
    Order number: <?= htmlspecialchars($order['orderNumber'] ?? '') ?>
</h1>

<div class="order-details-card">

    <div class="order-details-image-wrap">
        <?php if (!empty($orderItem['photoUrl'])): ?>
            <img class="order-details-img"
                 src="<?= htmlspecialchars($orderItem['photoUrl']) ?>"
                 alt="<?= htmlspecialchars($orderItem['itemName'] ?? 'Item') ?>">
        <?php else: ?>
            <div class="order-details-placeholder">No image</div>
        <?php endif; ?>
    </div>

    <div class="order-details-info">
        <p><strong>Item:</strong> <?= htmlspecialchars($orderItem['itemName'] ?? 'Item') ?></p>

        <p>
            <strong>Price:</strong>
            <?php if ($isDonation): ?>
                Donation
            <?php else: ?>
                <?= number_format((float)($orderItem['price'] ?? 0), 2) ?>
                <img src="../../images/SAR.png" class="currency-icon" alt="price">
            <?php endif; ?>
        </p>

        <p><strong>Quantity:</strong> <?= (int)($orderItem['quantity'] ?? 1) ?></p>
        <p><strong>Status:</strong> <?= $statusText ?></p>
        <p><strong>Pickup location:</strong> <?= htmlspecialchars($orderItem['pickupLocation'] ?? 'No location') ?></p>
        <p><strong>Order date:</strong> <?= htmlspecialchars($displayDate) ?></p>
    </div>

    <?php if ($itemStatus !== 'completed'): ?>
        <form method="POST" class="complete-form">
            <button type="submit" name="mark_completed" class="complete-btn">
                Mark As Completed
            </button>
        </form>
    <?php endif; ?>

</div>
</div>
</main>
</div>
<script>
function toggleMobileMenu() {
  const menu = document.getElementById('mobileMenu');
  const btn = document.getElementById('hamburger');
  menu.classList.toggle('open');
  btn.classList.toggle('open');
  document.body.style.overflow = menu.classList.contains('open') ? 'hidden' : '';
}

function closeMobileMenu() {
  document.getElementById('mobileMenu').classList.remove('open');
  document.getElementById('hamburger').classList.remove('open');
  document.body.style.overflow = '';
}
</script>
<script>
const profileSearchResults = [
  {
    title: 'Business Name',
    subtitle: 'Go to business name field',
    action: () => document.querySelector('[name="businessName"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  },
  {
    title: 'Business Description',
    subtitle: 'Go to business description field',
    action: () => document.querySelector('[name="businessDescription"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  },
  {
    title: 'Category',
    subtitle: 'Go to category field',
    action: () => document.querySelector('[name="category"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  },
  {
    title: 'Email',
    subtitle: 'Go to email field',
    action: () => document.querySelector('[name="email"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  },
  {
    title: 'Phone Number',
    subtitle: 'Go to phone number field',
    action: () => document.querySelector('[name="phoneNumber"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  },
  {
    title: 'City',
    subtitle: 'Go to city field',
    action: () => document.querySelector('[name="city"]')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }
];

function setupProfileSearch(inputId, dropdownId) {
  const input = document.getElementById(inputId);
  const dropdown = document.getElementById(dropdownId);

  if (!input || !dropdown) return;

  input.addEventListener('input', function () {
    const value = this.value.trim().toLowerCase();

    if (!value) {
      dropdown.classList.remove('visible');
      dropdown.innerHTML = '';
      return;
    }

    const results = profileSearchResults.filter(item =>
      item.title.toLowerCase().includes(value)
    );

    if (!results.length) {
      dropdown.innerHTML = `
        <div style="padding:16px;color:#8a9ab5;font-size:13px;text-align:center;">
          No matching result
        </div>
      `;
      dropdown.classList.add('visible');
      return;
    }

    dropdown.innerHTML = results.map((item, index) => `
      <div class="sd-row" data-index="${index}">
        <div class="sd-icon">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <circle cx="11" cy="11" r="8"></circle>
            <path d="m21 21-4.3-4.3"></path>
          </svg>
        </div>
        <div class="sd-info">
          <div class="sd-name">${item.title}</div>
          <div class="sd-sub">${item.subtitle}</div>
        </div>
      </div>
    `).join('');

    dropdown.classList.add('visible');

    dropdown.querySelectorAll('.sd-row').forEach((row, i) => {
      row.addEventListener('click', () => {
        results[i].action();
        dropdown.classList.remove('visible');
        input.value = '';
        closeMobileMenu?.();
      });
    });
  });

  document.addEventListener('click', (e) => {
    if (!dropdown.contains(e.target) && e.target !== input) {
      dropdown.classList.remove('visible');
    }
  });
}

setupProfileSearch('searchInput', 'searchDropdown');
setupProfileSearch('mobileSearchInput', 'mobileSearchDropdown');
</script>
</body>
</html>
  
