<?php
// ================================================================
// provider-dashboard.php — Provider Home Dashboard
// ================================================================
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Order.php';
require_once '../../back-end/models/OrderItem.php';
require_once '../../back-end/models/Provider.php';

if (empty($_SESSION['providerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../shared/landing.php');
    exit;
}

$providerId   = $_SESSION['providerId'];
$providerName = $_SESSION['providerName'] ?? '';

// Load provider for logo/email
$providerModel  = new Provider();
$providerData   = $providerModel->findById($providerId);
$providerLogo   = $providerData['businessLogo'] ?? '';
$providerEmail  = $providerData['email'] ?? '';
$firstName      = explode(' ', $providerName)[0];

// Items
$itemModel   = new Item();
$allItems    = $itemModel->getByProvider($providerId);
$saleItems   = array_filter($allItems, fn($i) => ($i['listingType'] ?? '') === 'sell');
$donateItems = array_filter($allItems, fn($i) => ($i['listingType'] ?? '') === 'donate');

usort($allItems, function($a, $b) {
    $ta = isset($a['createdAt']) ? $a['createdAt']->toDateTime()->getTimestamp() : 0;
    $tb = isset($b['createdAt']) ? $b['createdAt']->toDateTime()->getTimestamp() : 0;
    return $tb - $ta;
});
$recentItems = array_slice($allItems, 0, 5);

// Orders
$orderItemModel = new OrderItem();
$orderModel     = new Order();
$allOrderItems  = $orderItemModel->getByProvider($providerId);

usort($allOrderItems, function($a, $b) {
    $ta = isset($a['createdAt']) ? $a['createdAt']->toDateTime()->getTimestamp() : 0;
    $tb = isset($b['createdAt']) ? $b['createdAt']->toDateTime()->getTimestamp() : 0;
    return $tb - $ta;
});

$seenOrderIds  = [];
$recentOrders  = [];
$allOrderIds   = [];
foreach ($allOrderItems as $oi) {
    $oid = (string)$oi['orderId'];
    if (!in_array($oid, $allOrderIds)) $allOrderIds[] = $oid;
    if (count($recentOrders) < 5 && !in_array($oid, $seenOrderIds)) {
        $order = $orderModel->findById($oid);
        if ($order) {
            $order['_snapshot'] = $oi;
            $recentOrders[]     = $order;
            $seenOrderIds[]     = $oid;
        }
    }
}

$totalOrders     = count($allOrderIds);
$pendingOrders   = 0;
$completedOrders = 0;
foreach ($allOrderIds as $oid) {
    try {
        $o = $orderModel->findById($oid);
        if (!$o) continue;
        if ($o['orderStatus'] === 'pending')   $pendingOrders++;
        if ($o['orderStatus'] === 'completed') $completedOrders++;
    } catch (Throwable) {}
}

$stats = [
    'totalItems'      => count($allItems),
    'totalOrders'     => $totalOrders,
    'completedOrders' => $completedOrders,
    'pendingOrders'   => $pendingOrders,
];

function statusBadge(string $status): string {
    return match($status) {
        'pending'   => '<span class="badge badge-pending">Pending</span>',
        'completed' => '<span class="badge badge-completed">Completed</span>',
        'cancelled' => '<span class="badge badge-cancelled">Cancelled</span>',
        default     => '<span class="badge badge-pending">' . htmlspecialchars($status) . '</span>',
    };
}

function listingBadge(string $type): string {
    return match($type) {
        'sell'   => '<span class="badge badge-selling">Selling</span>',
        'donate' => '<span class="badge badge-donation">Donation</span>',
        default  => '',
    };
}

function timeAgo($utcDate): string {
    if (!$utcDate) return '';
    $ts   = $utcDate->toDateTime()->getTimestamp();
    $diff = time() - $ts;
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff/60) . 'm ago';
    if ($diff < 86400) return floor($diff/3600) . 'h ago';
    return $utcDate->toDateTime()->format('d M Y');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RePlate – Provider Dashboard</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
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
    .nav-left { display: flex; align-items: center; }
    .nav-logo { height: 90px; }
    .nav-search-wrap { position: relative; }
    .nav-search-wrap svg.search-icon { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); opacity: 0.6; pointer-events: none; }
    .nav-search-wrap input {
      background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.4);
      border-radius: 50px; padding: 10px 18px 10px 40px; color: #fff; font-size: 14px;
      outline: none; width: 260px; font-family: 'Playfair Display', serif;
      transition: width 0.3s, background 0.2s;
    }
    .nav-search-wrap input::placeholder { color: rgba(255,255,255,0.6); }
    .nav-search-wrap input:focus { width: 340px; background: rgba(255,255,255,0.25); }
    .nav-right { display: flex; align-items: center; gap: 14px; }
    .nav-provider-info { display: flex; align-items: center; gap: 14px; }
    .nav-provider-logo { width: 46px; height: 46px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); background: rgba(255,255,255,0.15); display: flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 700; color: #fff; overflow: hidden; flex-shrink: 0; }
    .nav-provider-logo img { width: 100%; height: 100%; object-fit: cover; }
    .nav-provider-text { display: flex; flex-direction: column; }
    .nav-provider-name { font-size: 15px; font-weight: 700; color: #fff; }
    .nav-provider-email { font-size: 12px; color: rgba(255,255,255,0.75); }

    /* ── SEARCH DROPDOWN ── */
    .search-dropdown {
      display: none; position: absolute; top: calc(100% + 10px); left: 0;
      width: 420px; background: #fff; border-radius: 18px;
      border: 1.5px solid #e0eaf5; box-shadow: 0 12px 40px rgba(26,58,107,0.18);
      z-index: 9999; overflow: hidden;
    }
    .search-dropdown.visible { display: block; }
    .sd-section-title {
      font-size: 11px; font-weight: 700; text-transform: uppercase;
      letter-spacing: 0.08em; color: #8a9ab5; padding: 12px 16px 6px;
      border-bottom: 1px solid #f0f5fc;
    }
    .sd-row {
      display: flex; align-items: center; gap: 12px;
      padding: 10px 16px; text-decoration: none; color: inherit;
      transition: background 0.15s; cursor: pointer;
    }
    .sd-row:hover { background: #f4f8ff; }
    .sd-thumb {
      width: 42px; height: 42px; border-radius: 10px; border: 1.5px solid #e0eaf5;
      background: #f0f5ff; overflow: hidden; flex-shrink: 0;
      display: flex; align-items: center; justify-content: center;
    }
    .sd-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .sd-info { flex: 1; min-width: 0; }
    .sd-name { font-size: 14px; font-weight: 700; color: #1a3a6b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .sd-sub  { font-size: 12px; color: #8a9ab5; margin-top: 2px; }
    .sd-badge { border-radius: 50px; padding: 2px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; flex-shrink: 0; }
    .sd-badge-sell     { background: #fff4e6; color: #e07a1a; }
    .sd-badge-donate   { background: #e8f7ee; color: #1a6b3a; }
    .sd-badge-pending  { background: #fff4e6; color: #e07a1a; }
    .sd-badge-completed{ background: #e8f7ee; color: #1a6b3a; }
    .sd-badge-cancelled{ background: #fde8e8; color: #c0392b; }
    .sd-empty { padding: 18px 16px; text-align: center; color: #b0c4d8; font-size: 13px; }
    .sd-loading { padding: 16px; text-align: center; color: #8a9ab5; font-size: 13px; }

    /* ── LAYOUT ── */
    .page-body { display: flex; flex: 1; }

    /* ── SIDEBAR ── */
    .sidebar { width: 240px; min-height: calc(100vh - 72px); background: linear-gradient(180deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%); display: flex; flex-direction: column; padding: 36px 24px 28px; flex-shrink: 0; }
    .sidebar-welcome { color: rgba(255,255,255,0.75); font-size: 17px; font-weight: 400; margin-bottom: 4px; }
    .sidebar-name { color: rgba(255,255,255,0.55); font-size: 38px; font-weight: 700; line-height: 1.1; margin-bottom: 36px; }
    .sidebar-nav { display: flex; flex-direction: column; gap: 16px; flex: 1; }
    .sidebar-link { display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,0.75); text-decoration: none; font-size: 16px; font-weight: 400; padding: 10px 8px; transition: color 0.2s; background: none !important; -webkit-tap-highlight-color: transparent; }
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

    /* ── MAIN ── */
    .main { flex: 1; padding: 36px 40px; overflow-y: auto; }

    /* ── PAGE HEADER ── */
    .page-header { margin-bottom: 28px; }
    .page-header h1 { font-size: 34px; font-weight: 700; font-family: 'Playfair Display', serif; background: linear-gradient(90deg, #143496 0%, #66a1d9 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; display: inline-block; }

    /* ── STATS ROW ── */
    .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 18px; margin-bottom: 28px; }
    .stat-card { background: #fff; border-radius: 18px; padding: 22px 24px; border: 1.5px solid #e0eaf5; box-shadow: 0 2px 12px rgba(26,58,107,0.05); position: relative; overflow: hidden; transition: transform 0.2s, box-shadow 0.2s; }
    .stat-card:hover { transform: translateY(-2px); box-shadow: 0 6px 24px rgba(26,58,107,0.1); }
    .stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; border-radius: 18px 18px 0 0; }
    .stat-card:nth-child(1)::before { background: linear-gradient(90deg, #1a3a6b, #3a7bd5); }
    .stat-card:nth-child(2)::before { background: linear-gradient(90deg, #e07a1a, #f5a623); }
    .stat-card:nth-child(3)::before { background: linear-gradient(90deg, #1a6b3a, #27ae60); }
    .stat-card:nth-child(4)::before { background: linear-gradient(90deg, #c0392b, #e74c3c); }
    .stat-label { font-size: 13px; font-weight: 700; color: #8a9ab5; text-transform: uppercase; letter-spacing: 0.06em; margin-bottom: 10px; }
    .stat-value { font-size: 42px; font-weight: 700; color: #1a3a6b; line-height: 1; margin-bottom: 6px; font-family: 'Playfair Display', serif; }
    .stat-sub { font-size: 12px; color: #b0c4d8; }

    /* ── DASHBOARD GRID ── */
    .dash-grid { display: grid; grid-template-columns: 1fr 1fr 320px; gap: 20px; align-items: start; }

    /* ── PANEL ── */
    .panel { background: #fff; border-radius: 20px; border: 1.5px solid #e0eaf5; overflow: hidden; box-shadow: 0 2px 12px rgba(26,58,107,0.05); }
    .panel-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 22px 14px; border-bottom: 1.5px solid #f0f5fc; }
    .panel-title { font-size: 20px; font-weight: 700; font-family: 'Playfair Display', serif; background: linear-gradient(90deg, #143496 0%, #66a1d9 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; display: inline-block; }
    .panel-link { font-size: 12px; color: #2255a4; text-decoration: none; font-weight: 600; transition: color 0.2s; }
    .panel-link:hover { color: #1a3a6b; }

    /* ── ORDER ROW ── */
    .order-row { display: flex; align-items: center; gap: 14px; padding: 16px 22px; border-bottom: 1px solid #f5f8fc; transition: background 0.15s; }
    .order-row:last-child { border-bottom: none; }
    .order-row:hover { background: #f8fbff; }
    .order-logo { width: 52px; height: 52px; border-radius: 12px; border: 1.5px solid #e0eaf5; overflow: hidden; flex-shrink: 0; display: flex; align-items: center; justify-content: center; background: #f0f5ff; }
    .order-logo img { width: 100%; height: 100%; object-fit: cover; }
    .order-info { flex: 1; min-width: 0; }
    .order-customer { font-size: 14px; font-weight: 700; color: #1a3a6b; margin-bottom: 4px; }
    .order-meta { font-size: 12px; color: #8a9ab5; display: flex; flex-direction: column; gap: 2px; }
    .order-meta-row { display: flex; align-items: center; gap: 5px; }
    .order-price { font-size: 15px; font-weight: 700; color: #e07a1a; white-space: nowrap; }
    .order-right { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }

    /* ── ITEM ROW ── */
    .item-row { display: flex; align-items: center; gap: 14px; padding: 14px 22px; border-bottom: 1px solid #f5f8fc; transition: background 0.15s; }
    .item-row:last-child { border-bottom: none; }
    .item-row:hover { background: #f8fbff; }
    .item-thumb { width: 64px; height: 64px; border-radius: 12px; background: #e0eaf5; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; }
    .item-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .item-info { flex: 1; min-width: 0; }
    .item-name { font-size: 14px; font-weight: 700; color: #1a3a6b; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .item-desc { font-size: 12px; color: #8a9ab5; margin-bottom: 4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .item-qty { font-size: 12px; color: #7a8fa8; font-weight: 600; }
    .item-right { display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }
    .item-price { font-size: 15px; font-weight: 700; color: #e07a1a; }

    /* ── RIGHT COLUMN ── */
    .right-col { display: flex; flex-direction: column; gap: 20px; }

    /* ── QUICK ACTIONS ── */
    .action-btn { display: flex; align-items: center; justify-content: center; gap: 8px; width: 100%; padding: 14px; background: #1a3a6b; color: #fff; border: none; border-radius: 12px; font-size: 15px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; text-decoration: none; transition: background 0.2s, transform 0.15s; margin-bottom: 10px; }
    .action-btn:last-child { margin-bottom: 0; }
    .action-btn:hover { background: #2255a4; transform: translateY(-1px); }
    .action-btn.secondary { background: #f4f7fc; color: #1a3a6b; border: 1.5px solid #e0eaf5; }
    .action-btn.secondary:hover { background: #e8f0ff; }
    .actions-body { padding: 18px 22px; }

    /* ── ITEMS OVERVIEW ── */
    .overview-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; padding: 18px 22px; }
    .overview-card { background: #f4f7fc; border-radius: 14px; padding: 16px; text-align: center; border: 1.5px solid #e0eaf5; }
    .overview-icon { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; margin: 0 auto 10px; background: #e8f0ff; border-radius: 50%; }
    .overview-label { font-size: 12px; font-weight: 700; color: #8a9ab5; text-transform: uppercase; letter-spacing: 0.05em; margin-bottom: 6px; }
    .overview-value { font-size: 36px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; }

    /* ── BADGES ── */
    .badge { border-radius: 50px; padding: 3px 10px; font-size: 11px; font-weight: 700; white-space: nowrap; }
    .badge-pending   { background: #fff4e6; color: #e07a1a; }
    .badge-completed { background: #e8f7ee; color: #1a6b3a; }
    .badge-cancelled { background: #fde8e8; color: #c0392b; }
    .badge-selling   { background: #fff4e6; color: #e07a1a; }
    .badge-donation  { background: #e8f7ee; color: #1a6b3a; }

    /* ── EMPTY STATE ── */
    .panel-empty { padding: 32px; text-align: center; color: #b0c4d8; font-size: 14px; }
    .panel-empty svg { display: block; margin: 0 auto 10px; }
  </style>
</head>
<body>

  <nav class="navbar">
    <div class="nav-left">
      <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
    </div>
    <div class="nav-right">

      <!-- ── SEARCH ── -->
      <div class="nav-search-wrap" id="searchWrap">
        <svg class="search-icon" width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" id="searchInput" placeholder="Search items ..." autocomplete="off"/>
        <div class="search-dropdown" id="searchDropdown"></div>
      </div>

      <div class="nav-provider-info">
        <div class="nav-provider-logo">
          <?php if ($providerLogo): ?>
            <img src="<?= htmlspecialchars($providerLogo) ?>" alt=""/>
          <?php else: ?>
            <?= mb_strtoupper(mb_substr($providerName, 0, 1)) ?>
          <?php endif; ?>
        </div>
        <div class="nav-provider-text">
          <span class="nav-provider-name"><?= htmlspecialchars($providerName) ?></span>
          <span class="nav-provider-email"><?= htmlspecialchars($providerEmail) ?></span>
        </div>
      </div>
    </div>
  </nav>

  <div class="page-body">
    <aside class="sidebar">
      <p class="sidebar-welcome">Welcome Back ,</p>
      <p class="sidebar-name"><?= htmlspecialchars($firstName) ?></p>
      <nav class="sidebar-nav">
        <a href="provider-dashboard.php" class="sidebar-link active">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
          DashBoard
        </a>
        <a href="provider-items.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/><path d="M16 3H8a2 2 0 00-2 2v2h12V5a2 2 0 00-2-2z"/></svg>
          Items
        </a>
        <a href="provider-orders.php" class="sidebar-link">
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

    <main class="main">

      <div class="page-header">
        <h1><span>Business</span> Summary</h1>
      </div>

      <!-- STATS ROW -->
      <div class="stats-row">
        <div class="stat-card">
          <p class="stat-label">Total Items</p>
          <p class="stat-value"><?= $stats['totalItems'] ?></p>
          <p class="stat-sub">Items listed</p>
        </div>
        <div class="stat-card">
          <p class="stat-label">Total Orders</p>
          <p class="stat-value"><?= $stats['totalOrders'] ?></p>
          <p class="stat-sub">All orders</p>
        </div>
        <div class="stat-card">
          <p class="stat-label">Completed Orders</p>
          <p class="stat-value"><?= $stats['completedOrders'] ?></p>
          <p class="stat-sub">Orders done</p>
        </div>
        <div class="stat-card">
          <p class="stat-label">Pending Orders</p>
          <p class="stat-value"><?= $stats['pendingOrders'] ?></p>
          <p class="stat-sub">Needs action</p>
        </div>
      </div>

      <!-- DASHBOARD GRID -->
      <div class="dash-grid">

        <!-- RECENT ORDERS -->
        <div class="panel">
          <div class="panel-header">
            <h2 class="panel-title">Recent <span>Orders</span></h2>
            <a href="provider-orders.php" class="panel-link">View all →</a>
          </div>
          <div class="panel-body">
            <?php if (empty($recentOrders)): ?>
            <div class="panel-empty">
              <svg width="36" height="36" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
              No orders yet
            </div>
            <?php else: ?>
            <?php foreach ($recentOrders as $order):
              $snap     = $order['_snapshot'] ?? [];
              $custName = htmlspecialchars($snap['providerName'] ?? 'Customer');
              $photo    = $snap['photoUrl'] ?? '';
              $price    = number_format((float)($order['totalAmount'] ?? 0), 2);
              $num      = htmlspecialchars($order['orderNumber'] ?? '');
              $time     = timeAgo($order['placedAt'] ?? null);
              $status   = $order['orderStatus'] ?? 'pending';
            ?>
            <div class="order-row">
              <div class="order-logo">
                <?php if ($photo): ?>
                  <img src="<?= htmlspecialchars($photo) ?>" alt=""/>
                <?php else: ?>
                  <svg width="22" height="22" fill="none" stroke="#2255a4" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?php endif; ?>
              </div>
              <div class="order-info">
                <p class="order-customer"><?= $custName ?></p>
                <div class="order-meta">
                  <div class="order-meta-row">
                    <svg width="12" height="12" fill="none" stroke="#8a9ab5" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <?= $time ?>
                  </div>
                  <div class="order-meta-row">
                    <svg width="12" height="12" fill="none" stroke="#8a9ab5" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg>
                    Order #<?= $num ?>
                  </div>
                </div>
              </div>
              <div class="order-right">
                <?= statusBadge($status) ?>
                <span class="order-price">﷼ <?= $price ?></span>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- RECENT ITEMS -->
        <div class="panel">
          <div class="panel-header">
            <h2 class="panel-title">Recent <span>Items</span></h2>
            <a href="provider-items.php" class="panel-link">View all →</a>
          </div>
          <div class="panel-body">
            <?php if (empty($recentItems)): ?>
            <div class="panel-empty">
              <svg width="36" height="36" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 7H4a2 2 0 00-2 2v10a2 2 0 002 2h16a2 2 0 002-2V9a2 2 0 00-2-2z"/></svg>
              No items yet — add your first item!
            </div>
            <?php else: ?>
            <?php foreach ($recentItems as $item):
              $iName  = htmlspecialchars($item['itemName'] ?? '');
              $iDesc  = htmlspecialchars($item['description'] ?? '');
              $iPrice = $item['listingType'] === 'donate' ? 'Free' : '﷼ ' . number_format((float)($item['price'] ?? 0), 2);
              $iQty   = (int)($item['quantity'] ?? 0);
              $iPhoto = $item['photoUrl'] ?? '';
              $iType  = $item['listingType'] ?? 'sell';
            ?>
            <div class="item-row">
              <div class="item-thumb">
                <?php if ($iPhoto): ?>
                  <img src="<?= htmlspecialchars($iPhoto) ?>" alt="<?= $iName ?>"/>
                <?php else: ?>
                  <svg width="28" height="28" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/><path d="M3 9h18"/></svg>
                <?php endif; ?>
              </div>
              <div class="item-info">
                <p class="item-name"><?= $iName ?></p>
                <p class="item-desc"><?= $iDesc ?></p>
                <p class="item-qty">Quantity: <?= $iQty ?></p>
              </div>
              <div class="item-right">
                <?= listingBadge($iType) ?>
                <span class="item-price"><?= $iPrice ?></span>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- RIGHT COLUMN -->
        <div class="right-col">
          <div class="panel">
            <div class="panel-header">
              <h2 class="panel-title">Quick <span>Actions</span></h2>
            </div>
            <div class="actions-body">
              <a href="provider-items.php" class="action-btn">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Item
              </a>
              <a href="provider-orders.php" class="action-btn secondary">
                <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
                Change Order Status
              </a>
            </div>
          </div>

          <div class="panel">
            <div class="panel-header">
              <h2 class="panel-title">Items <span>Overview</span></h2>
            </div>
            <div class="overview-grid">
              <div class="overview-card">
                <div class="overview-icon">
                  <svg width="20" height="20" fill="none" stroke="#2255a4" stroke-width="2" viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>
                </div>
                <p class="overview-label">Items For Sale</p>
                <p class="overview-value"><?= count($saleItems) ?></p>
              </div>
              <div class="overview-card">
                <div class="overview-icon" style="background:#e8f7ee;">
                  <svg width="20" height="20" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><path d="M12 22V7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/></svg>
                </div>
                <p class="overview-label">Items For Donation</p>
                <p class="overview-value"><?= count($donateItems) ?></p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <script>
    const searchInput    = document.getElementById('searchInput');
    const searchDropdown = document.getElementById('searchDropdown');
    let debounceTimer    = null;

    searchInput.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      const q = searchInput.value.trim();
      if (q.length < 2) { closeDropdown(); return; }
      debounceTimer = setTimeout(() => doSearch(q), 300);
    });

    // Close dropdown when clicking outside
    document.addEventListener('click', (e) => {
      if (!document.getElementById('searchWrap').contains(e.target)) closeDropdown();
    });

    searchInput.addEventListener('focus', () => {
      if (searchInput.value.trim().length >= 2) doSearch(searchInput.value.trim());
    });

    function closeDropdown() {
      searchDropdown.classList.remove('visible');
      searchDropdown.innerHTML = '';
    }

    function doSearch(q) {
      searchDropdown.innerHTML = '<div class="sd-loading">Searching...</div>';
      searchDropdown.classList.add('visible');

  fetch(`../../back-end/provider-search.php?q=${encodeURIComponent(q)}`)
        .then(r => r.json())
        .then(data => renderResults(data))
        .catch(() => {
          searchDropdown.innerHTML = '<div class="sd-empty">Something went wrong.</div>';
        });
    }

    function renderResults(data) {
      const items  = data.items  || [];
      const orders = data.orders || [];

      if (!items.length && !orders.length) {
        searchDropdown.innerHTML = '<div class="sd-empty">No results found.</div>';
        return;
      }

      let html = '';

      if (items.length) {
        html += `<div class="sd-section-title">Items</div>`;
        items.forEach(item => {
          const thumb = item.photoUrl
            ? `<img src="${esc(item.photoUrl)}" alt="" onerror="this.style.display='none'">`
            : `<svg width="20" height="20" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/></svg>`;
          const badgeClass = item.listingType === 'donate' ? 'sd-badge-donate' : 'sd-badge-sell';
          const badgeLabel = item.listingType === 'donate' ? 'Donation' : 'Selling';
          html += `
            <a class="sd-row" href="provider-item-details.php?id=${esc(item.id)}">
              <div class="sd-thumb">${thumb}</div>
              <div class="sd-info">
                <div class="sd-name">${esc(item.name)}</div>
                <div class="sd-sub">${esc(item.price)}</div>
              </div>
              <span class="sd-badge ${badgeClass}">${badgeLabel}</span>
            </a>`;
        });
      }

      if (orders.length) {
        html += `<div class="sd-section-title">Orders</div>`;
        orders.forEach(order => {
          const badgeClass = `sd-badge-${order.status}`;
          const statusLabel = order.status.charAt(0).toUpperCase() + order.status.slice(1);
          html += `
            <a class="sd-row" href="provider-orders.php">
              <div class="sd-thumb">
                <svg width="20" height="20" fill="none" stroke="#2255a4" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
              </div>
              <div class="sd-info">
                <div class="sd-name">Order #${esc(order.orderNumber)}</div>
                <div class="sd-sub">${order.itemName ? esc(order.itemName) + ' · ' : ''}﷼ ${esc(order.total)}</div>
              </div>
              <span class="sd-badge ${badgeClass}">${statusLabel}</span>
            </a>`;
        });
      }

      searchDropdown.innerHTML = html;
    }

    function esc(str) {
      const d = document.createElement('div');
      d.textContent = String(str ?? '');
      return d.innerHTML;
    }
  </script>
</body>
</html>