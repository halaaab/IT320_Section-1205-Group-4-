<?php
session_start();

require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Order.php';
require_once '../../back-end/models/OrderItem.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Notification.php';
require_once '../../back-end/models/Customer.php';
require_once '../../back-end/models/PickupLocation.php';

if (empty($_SESSION['providerId'])) {
    header('Location: ../shared/login.php'); exit;
}
if (isset($_GET['logout'])) {
    session_destroy(); header('Location: ../shared/landing.php'); exit;
}

$providerId = $_SESSION['providerId'];

// ── AJAX: Mark entire order as completed ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'complete_order') {
    header('Content-Type: application/json');
    try {
        $orderId_ = trim($_POST['orderId'] ?? '');
        if (!$orderId_) { echo json_encode(['success'=>false,'message'=>'Missing data']); exit; }

        $orderItemModel_ = new OrderItem();
        $orderModel_     = new Order();

        $allItems_ = $orderItemModel_->findAll([
            'orderId'    => new MongoDB\BSON\ObjectId($orderId_),
            'providerId' => new MongoDB\BSON\ObjectId($providerId),
        ]);
        foreach ($allItems_ as $it_) {
            $orderItemModel_->updateById((string)$it_['_id'], ['itemStatus' => 'completed']);
        }

        $order_ = $orderModel_->findById($orderId_);
        if ($order_) {
            $customerId_ = (string)($order_['customerId'] ?? '');
            if ($customerId_) {
                (new Notification())->notifyOrderCompleted(
                    $customerId_, $orderId_, $order_['orderNumber'] ?? $orderId_
                );
            }
        }
        echo json_encode(['success' => true]);
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$tab = $_GET['tab'] ?? 'pending';
if (!in_array($tab, ['pending','completed'], true)) $tab = 'pending';

$providerModel   = new Provider();
$orderModel      = new Order();
$orderItemModel  = new OrderItem();
$itemModel       = new Item();
$customerModel   = new Customer();
$locationModel   = new PickupLocation();

$providerItemsForSearch = $itemModel->getByProvider($providerId);
$provider      = $providerModel->findById($providerId);
$providerName  = $provider['businessName'] ?? 'Provider';
$providerEmail = $provider['email'] ?? '';
$providerLogo  = $provider['businessLogo'] ?? '';
$firstName     = explode(' ', $providerName)[0] ?? 'Provider';

// ── Revenue summary (completed orders) ───────────────────────────────────────
$allProviderItems = $orderItemModel->findAll([
    'providerId' => new MongoDB\BSON\ObjectId($providerId)
]);

$revenueToday = 0; $revenueWeek = 0; $revenueTotal = 0;
$todayStart = mktime(0,0,0); $weekStart = mktime(0,0,0) - 6*86400;
$completedCount = 0; $pendingCount = 0;

// Count distinct orders (not individual items) for pending/completed
$countedOrderIds = [];

foreach ($allProviderItems as $ri) {
    $status = strtolower(trim($ri['itemStatus'] ?? 'pending'));
    if ($status === 'cancelled') continue;

    // Revenue uses item-level data (correct — sum all completed item subtotals)
    if ($status !== 'pending') {
        $subtotal = ((float)($ri['price'] ?? 0)) * ((int)($ri['quantity'] ?? 1));
        $revenueTotal += $subtotal;
        $oid = (string)($ri['orderId'] ?? '');
        if ($oid) {
            $ord_ = $orderModel->findById($oid);
            if ($ord_ && !empty($ord_['placedAt']) && $ord_['placedAt'] instanceof MongoDB\BSON\UTCDateTime) {
                $ts = $ord_['placedAt']->toDateTime()->getTimestamp();
                if ($ts >= $todayStart) $revenueToday += $subtotal;
                if ($ts >= $weekStart)  $revenueWeek  += $subtotal;
            }
        }
    }
}

// ── Group by orderId ──────────────────────────────────────────────────────────
$groupedOrders = [];
foreach ($allProviderItems as $oi) {
    $oid = (string)($oi['orderId'] ?? '');
    if (!$oid) continue;
    if (!isset($groupedOrders[$oid])) $groupedOrders[$oid] = [];
    $groupedOrders[$oid][] = $oi;
}

// Count pending/completed based on PROVIDER'S items (matching the card logic)
$completedCount = 0; 
$pendingCount = 0;

foreach ($groupedOrders as $orderId => $items) {
    $order_ = $orderModel->findById($orderId);
    if (!$order_) continue;
    
    // Skip cancelled orders
    if (($order_['orderStatus'] ?? 'pending') === 'cancelled') continue;
    
    $hasAnyPending = false;
    foreach ($items as $it) {
        $s = strtolower(trim($it['itemStatus'] ?? 'pending'));
        if ($s !== 'completed' && $s !== 'cancelled') {
            $hasAnyPending = true;
            break;
        }
    }
    
    if ($hasAnyPending) {
        $pendingCount++;
    } else {
        $completedCount++;
    }
}

// ── Build cards list ──────────────────────────────────────────────────────────
$ordersToShow = [];
foreach ($groupedOrders as $orderId => $items) {
    $order = $orderModel->findById($orderId);
    if (!$order) continue;

    // Skip cancelled orders entirely — don't show them on either tab
    if (($order['orderStatus'] ?? '') === 'cancelled') continue;

    $hasAnyPending = false; $hasAnyCompleted = false;
    foreach ($items as $it) {
        $s = strtolower(trim($it['itemStatus'] ?? 'pending'));
        if ($s === 'completed') $hasAnyCompleted = true;
        elseif ($s !== 'cancelled') $hasAnyPending = true;
    }
    $overallStatus = $hasAnyPending ? 'pending' : 'completed';

    if ($tab === 'pending'   && $overallStatus !== 'pending')   continue;
    if ($tab === 'completed' && $overallStatus !== 'completed') continue;

    $placedDateObj = null; $placedDate = '';
    if (!empty($order['placedAt']) && $order['placedAt'] instanceof MongoDB\BSON\UTCDateTime) {
        $placedDateObj = $order['placedAt']->toDateTime();
        $placedDate    = $placedDateObj->format('j F Y');
    }

    // Customer name
    $customerName = '';
    if (!empty($order['customerId'])) {
        try {
            $cust = $customerModel->findById((string)$order['customerId']);
            if ($cust) $customerName = trim(($cust['firstName'] ?? '') . ' ' . ($cust['lastName'] ?? ''));
            if (!$customerName) $customerName = $cust['name'] ?? $cust['email'] ?? '';
        } catch (Throwable $e) {}
    }

    // Resolve photos & build items payload
    $itemsPayload = []; $orderTotal = 0;
    foreach ($items as $it) {
        $photoUrl = $it['photoUrl'] ?? '';
        if (empty($photoUrl) && !empty($it['itemId'])) {
            $src = $itemModel->findById((string)$it['itemId']);
            if ($src) $photoUrl = $src['photoUrl'] ?? '';
        }
        $qty = (int)($it['quantity'] ?? 1);
        $price = (float)($it['price'] ?? 0);

        // ── Pickup location: use snapshot, fall back to live location records ──
        $loc = trim((string)($it['pickupLocation'] ?? ''));
        $locLat = null; $locLng = null;
        $locRec = null;

        if ($loc === '') {
            // 1. Try the item's own pickupLocationId
            if (!empty($it['itemId'])) {
                $itemRec = $itemModel->findById((string)$it['itemId']);
                if ($itemRec && !empty($itemRec['pickupLocationId'])) {
                    try { $locRec = $locationModel->findById((string)$itemRec['pickupLocationId']); } catch(Throwable) {}
                }
            }
            // 2. Provider default location
            if (!$locRec) {
                try { $locRec = $locationModel->getDefault($providerId); } catch(Throwable) {}
            }
            // 3. Any location for this provider
            if (!$locRec) {
                $allLocs = $locationModel->getByProvider($providerId);
                if (!empty($allLocs)) $locRec = (array)$allLocs[0];
            }
            if ($locRec) {
                $parts = array_filter([
                    $locRec['label']  ?? ($locRec['locationName'] ?? ''),
                    $locRec['street'] ?? '',
                    $locRec['city']   ?? '',
                    $locRec['zip']    ?? '',
                ]);
                $loc = trim(implode(', ', $parts));
            }
        } else {
            // Snapshot string exists — still try to get coordinates from item's location record
            if (!empty($it['itemId'])) {
                $itemRec_ = $itemModel->findById((string)$it['itemId']);
                if ($itemRec_ && !empty($itemRec_['pickupLocationId'])) {
                    try { $locRec = $locationModel->findById((string)$itemRec_['pickupLocationId']); } catch(Throwable) {}
                }
            }
            if (!$locRec) {
                try { $locRec = $locationModel->getDefault($providerId); } catch(Throwable) {}
            }
            if (!$locRec) {
                $allLocs = $locationModel->getByProvider($providerId);
                if (!empty($allLocs)) $locRec = (array)$allLocs[0];
            }
        }

        // Extract coordinates from whichever record we found
        if ($locRec) {
            $locLat = $locRec['lat'] ?? ($locRec['coordinates']['lat'] ?? null);
            $locLng = $locRec['lng'] ?? ($locRec['coordinates']['lng'] ?? null);
        }
        $itemsPayload[] = [
            'itemName'       => $it['itemName']           ?? 'Item',
            'photoUrl'       => $photoUrl,
            'quantity'       => $qty,
            'price'          => $price,
            'subtotal'       => $price * $qty,
            'isDonation'     => $price <= 0,
            'pickupTime'     => $it['selectedPickupTime'] ?? '',
            'pickupDate'     => !empty($it['pickupDate']) && $it['pickupDate'] instanceof MongoDB\BSON\UTCDateTime
                                    ? $it['pickupDate']->toDateTime()->format('j F Y') : '',
            'pickupLocation' => $loc,
            'pickupLat'      => $locLat ? (float)$locLat : null,
            'pickupLng'      => $locLng ? (float)$locLng : null,
        ];
        $orderTotal += $price * $qty;
    }

    $firstPhoto = $itemsPayload[0]['photoUrl'] ?? '';

    $ordersToShow[] = [
        'orderId'       => $orderId,
        'orderNumber'   => $order['orderNumber'] ?? '',
        'status'        => $overallStatus,
        'placedDate'    => $placedDate,
        'placedDateObj' => $placedDateObj,
        'firstPhoto'    => $firstPhoto,
        'itemCount'     => count($items),
        'orderTotal'    => $orderTotal,
        'isDonation'    => $orderTotal <= 0,
        'customerName'  => $customerName,
        'items'         => $itemsPayload,
    ];
}

usort($ordersToShow, fn($a,$b) => $b['placedDateObj'] <=> $a['placedDateObj']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>RePlate – My Orders</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
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
  font-family: 'Playfair Display', serif;
  padding: 18px 0;
  border-bottom: 1px solid rgba(255,255,255,0.12);
  text-decoration: none;
}

.mobile-menu a:hover {
  color: #fff;
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
  font-size: 15px;
  outline: none;
  font-family: 'Playfair Display', serif;
}

.mobile-search input::placeholder {
  color: rgba(255,255,255,0.6);
}

.mobile-search-dropdown {
  display: none;
  background: #fff;
  border-radius: 14px;
  border: 1.5px solid #e0eaf5;
  box-shadow: 0 8px 32px rgba(26,58,107,0.18);
  margin-top: 8px;
  overflow: hidden;
  max-height: 320px;
  overflow-y: auto;
}

.mobile-search-dropdown.visible {
  display: block;
}
@media (max-width: 768px) {
  nav.navbar {
    padding: 0 18px;
  }

  .nav-logo {
    height: 72px;
  }

  .nav-right {
    gap: 10px;
    flex: 1;
    justify-content: flex-end;
  }

  .nav-provider-text {
    display: none;
  }

  .nav-provider-logo {
    width: 40px;
    height: 40px;
  }

  .nav-search-wrap {
    display: none;
  }

  .hamburger {
    display: flex;
  }

  .sidebar {
    display: none;
  }

  .page-body {
    display: block;
  }

  .main {
    padding: 20px 16px;
  }

  .page-header {
    margin-bottom: 20px;
  }

  .page-header h1 {
    font-size: 28px;
    line-height: 1.2;
  }

  /* ── STATS 2x2 GRID ── */
  .stats-row {
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    margin-bottom: 20px;
  }

  .stat-card {
    padding: 18px 16px;
  }

  .stat-value {
    font-size: 32px;
  }

  .stat-label {
    font-size: 11px;
  }

  .stat-sub {
    font-size: 11px;
  }

  /* ── MAIN CONTENT STACKS ── */
  .dash-grid {
    grid-template-columns: 1fr;
    gap: 16px;
  }

  .right-col {
    gap: 16px;
  }

  .panel-header {
    padding: 16px 18px 12px;
  }

  .panel-title {
    font-size: 18px;
  }

  .order-row,
  .item-row {
    padding: 14px 16px;
  }

  .order-logo,
  .item-thumb {
    width: 52px;
    height: 52px;
  }

  .overview-grid {
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    padding: 16px;
  }

  .overview-value {
    font-size: 28px;
  }

  .actions-body {
    padding: 16px;
  }

  .action-btn {
    padding: 13px;
    font-size: 14px;
  }

  .nav-search-wrap {
    display: none;
  }
}

    .page-body { display: flex; flex: 1; }
.main { flex: 1; padding: 24px 28px 24px 20px; overflow-y: auto; }

/* ── SIDEBAR ── */
.sidebar-welcome { color: rgba(255,255,255,.78); font-size: 17px; margin-bottom: 4px; }
.sidebar-name    { color: rgba(255,255,255,.62); font-size: 38px; font-weight: 700; line-height: 1.1; margin-bottom: 34px; }
.sidebar-nav     { display: flex; flex-direction: column; gap: 16px; flex: 1; }
.sidebar-link { display: flex; align-items: center; gap: 10px; color: rgba(255,255,255,.78); text-decoration: none; font-size: 16px; padding: 10px 8px; transition: .2s; }
.sidebar-link:hover { color: #fff; }
.sidebar-link.active { color: #fff; font-weight: 700; border-bottom: 2px solid rgba(255,255,255,.55); padding-bottom: 6px; }
.sidebar-link svg { flex-shrink: 0; }
.sidebar-logout { margin-top: 22px; background: #fff; color: #1a3a6b; border: none; border-radius: 999px; padding: 12px 0; font-size: 15px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; width: 100%; }
.sidebar-footer { margin-top: 24px; padding-top: 18px; border-top: 1px solid rgba(255,255,255,.14); display: flex; flex-direction: column; gap: 10px; align-items: center; }
.sidebar-footer-social { display: flex; align-items: center; justify-content: center; gap: 8px; }
.sidebar-social-icon { width: 28px; height: 28px; border-radius: 50%; border: 1.5px solid rgba(255,255,255,.35); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.82); font-size: 11px; font-weight: 700; text-decoration: none; }
.sidebar-footer-copy { color: rgba(255,255,255,.45); font-size: 10px; display: flex; align-items: center; justify-content: center; gap: 4px; flex-wrap: wrap; }

/* ── PAGE WRAP ── */
.orders-page-wrap { max-width: 1100px; margin: 0 auto; }
.page-header { margin-bottom: 18px; text-align: center; }
.page-header h1 { font-size: 34px; font-weight: 700; background: linear-gradient(90deg,#143496 0%,#66a1d9 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; display: inline-block; }

/* ── REVENUE SUMMARY BAR ── */
.revenue-bar { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
.revenue-card { background: #fff; border: 1.5px solid #d7e1ee; border-radius: 18px; padding: 16px 18px; display: flex; flex-direction: column; gap: 4px; }
.revenue-label { font-size: 11px; font-weight: 700; color: #8aa3c0; text-transform: uppercase; letter-spacing: .06em; }
.revenue-value { font-size: 22px; font-weight: 700; color: #1a3a6b; display: flex; align-items: center; gap: 5px; }
.revenue-value img { height: 18px; object-fit: contain; }
.revenue-value.green { color: #1a6b3a; }
.revenue-value.orange { color: #e07a1a; }
.revenue-sub { font-size: 12px; color: #8aa3c0; }

/* ── HEADER BAR ── */
.orders-header-bar { display: flex; align-items: center; justify-content: flex-start; gap: 12px; margin-bottom: 22px; flex-wrap: wrap; }
.seg-btn { min-width: 140px; padding: 10px 20px; border-radius: 18px; border: 1.8px solid #ea8b2c; background: #fff; color: #183482; font-size: 16px; font-family: 'Playfair Display', serif; text-decoration: none; text-align: center; display: inline-block; transition: .2s; }
.seg-btn.active  { background: #f6811f; color: #fff; border-color: #f6811f; }
.seg-btn:not(.active):hover { background: #fff8f2; }

/* ── 2x2 GRID ── */
.orders-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 14px; }

/* ── ORDER CARD ── */
.order-grid-card { background: #f2f4f8; border: 1.5px solid #c8d8ee; border-radius: 20px; overflow: hidden; display: flex; flex-direction: row; transition: box-shadow .2s, transform .2s, border-color .2s; cursor: pointer; box-shadow: 0 2px 14px rgba(26,58,107,.07); min-height: 130px; position: relative; }
.order-grid-card:hover { box-shadow: 0 8px 28px rgba(26,58,107,.13); transform: translateY(-3px); border-color: #ea8b2c; }
.order-card-photo { width: 130px; flex-shrink: 0; background: #d8e6f5; overflow: hidden; position: relative; }
.order-card-photo img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform .3s; }
.order-grid-card:hover .order-card-photo img { transform: scale(1.05); }
.order-card-photo-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #a0b4cc; font-size: 13px; }
.item-count-badge { position: absolute; top: 6px; left: 6px; background: rgba(26,58,107,.75); color: #fff; border-radius: 999px; font-size: 11px; font-weight: 700; padding: 2px 8px; backdrop-filter: blur(4px); }
.order-card-body { flex: 1; padding: 12px 14px; display: flex; flex-direction: column; gap: 4px; overflow: hidden; min-width: 0; }
.order-card-top-row { display: flex; align-items: flex-start; justify-content: space-between; gap: 8px; }
.order-card-name { font-size: 15px; font-weight: 700; color: #1a3a6b; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; }
.order-card-divider { width: 100%; height: 1px; background: #c0d2e8; margin: 3px 0; }
.order-card-meta { font-size: 12px; color: #4a6a9a; line-height: 1.5; display: flex; align-items: center; gap: 5px; }
.order-card-price { font-size: 13px; font-weight: 700; color: #e07a1a; margin-top: auto; padding-top: 4px; display: flex; align-items: center; gap: 4px; }
.order-card-price.donate-price { color: #1a6b3a; }
.order-card-price img { height: 13px; object-fit: contain; }
.badge { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; font-family: 'Playfair Display', serif; }
.badge-pending   { background: #fff7ed; color: #c06a10; border: 1px solid #f3c999; }
.badge-completed { background: #edfbf3; color: #1a7a45; border: 1px solid #b0e6c8; }

/* ── EMPTY STATE ── */
.empty-state { grid-column: 1 / -1; text-align: center; padding: 60px 24px; background: #fff; border: 1.5px dashed #c8d8ee; border-radius: 20px; }
.empty-state svg { display: block; margin: 0 auto 16px; opacity: .35; }
.empty-state p { font-size: 18px; color: #6d7da0; }

/* ── MODAL ── */
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(12,22,45,.45); z-index: 9999; justify-content: center; align-items: center; padding: 20px; }
.modal-overlay.open { display: flex; }
.modal-box.order-detail-box { max-width: 560px; width: 100%; background: #f7fbff; border-radius: 26px; border: 1.5px solid #cfdbea; box-shadow: 0 20px 60px rgba(26,58,107,.18); position: relative; display: flex; flex-direction: column; max-height: 88vh; overflow: hidden; }
.close-modal-btn { position: absolute; top: 14px; right: 14px; background: rgba(255,255,255,.9); border: 1.5px solid #d7e1ee; color: #8aa3c0; font-size: 22px; font-weight: 700; cursor: pointer; line-height: 1; z-index: 10; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: .2s; }
.close-modal-btn:hover { background: #fff; color: #e74c3c; border-color: #e74c3c; }

/* Modal header */
.modal-header { padding: 22px 24px 14px; border-bottom: 1px solid #e8f0f8; flex-shrink: 0; }
.modal-order-number { font-size: 20px; font-weight: 700; color: #1a3a6b; margin-bottom: 4px; }
.modal-meta-row { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-top: 4px; }
.modal-meta-chip { display: flex; align-items: center; gap: 5px; font-size: 12px; color: #7a8fa8; }

/* Items scroll */
.modal-items-scroll { overflow-y: auto; flex: 1; min-height: 0; padding: 10px 24px; display: flex; flex-direction: column; }
.modal-item-block { border-bottom: 1.5px solid #e8f0f8; padding: 14px 0; }
.modal-item-block:last-child { border-bottom: none; }
.modal-item-row { display: flex; align-items: center; gap: 14px; margin-bottom: 10px; }
.modal-item-thumb { width: 62px; height: 62px; border-radius: 12px; background: #d8e6f5; overflow: hidden; flex-shrink: 0; }
.modal-item-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
.modal-item-thumb-placeholder { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #a0b4cc; font-size: 11px; text-align: center; }
.modal-item-info { flex: 1; min-width: 0; }
.modal-item-name { font-size: 14px; font-weight: 700; color: #1a3a6b; margin-bottom: 3px; }
.modal-item-meta { font-size: 12px; color: #6a84a8; line-height: 1.6; }
.modal-item-price { font-size: 14px; font-weight: 700; color: #e07a1a; white-space: nowrap; display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
.modal-item-price img { height: 13px; object-fit: contain; }
.modal-item-price.is-donate { color: #1a6b3a; }

/* Map accordion per item */
.map-acc { border: 1.5px solid #d7e8f5; border-radius: 12px; overflow: hidden; margin-top: 4px; }
.map-acc-header { display: flex; align-items: center; justify-content: space-between; padding: 9px 12px; background: #f0f6ff; cursor: pointer; user-select: none; font-size: 12px; font-weight: 700; color: #1a3a6b; }
.map-acc-header:hover { background: #e4f0ff; }
.map-acc-addr { font-size: 11px; color: #6a84a8; font-weight: 400; margin-top: 1px; }
.map-acc-chevron { transition: transform .25s; color: #6a84a8; }
.map-acc-chevron.open { transform: rotate(180deg); }
.map-acc-body { display: none; }
.map-acc-body.open { display: block; }
.map-acc-body iframe { width: 100%; height: 180px; border: none; display: block; }

/* Modal footer */
.modal-footer { padding: 14px 24px 20px; border-top: 1px solid #e8f0f8; flex-shrink: 0; display: flex; align-items: center; justify-content: space-between; gap: 14px; }
.modal-total { display: flex; align-items: center; gap: 6px; font-size: 16px; font-weight: 700; color: #1a3a6b; }
.modal-total-num { color: #e07a1a; display: flex; align-items: center; gap: 4px; }
.modal-total-num img { height: 14px; object-fit: contain; }
.modal-total-num.is-donate { color: #1a6b3a; }
.complete-btn { background: #1a6b3a; color: #fff; border: none; border-radius: 40px; padding: 11px 24px; font-size: 14px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; transition: .2s; }
.complete-btn:hover { background: #145530; transform: translateY(-1px); }
.completed-label { color: #1a7a45; font-size: 14px; font-weight: 700; display: flex; align-items: center; gap: 6px; }

/* ── TOAST ── */
.toast { display: none; position: fixed; bottom: 28px; left: 50%; transform: translateX(-50%); background: #c0392b; color: #fff; padding: 14px 28px; border-radius: 14px; box-shadow: 0 10px 28px rgba(0,0,0,.18); z-index: 99999; font-size: 15px; font-family: 'Playfair Display', serif; font-weight: 600; max-width: 420px; width: max-content; line-height: 1.5; text-align: center; }
.toast.show { display: block; animation: fadeInUp .3s ease; }
@keyframes fadeInUp { from { opacity:0; transform: translateX(-50%) translateY(10px); } to { opacity:1; transform: translateX(-50%) translateY(0); } }

/* ── HAMBURGER + MOBILE ── */
@media (max-width: 768px) {
  .orders-grid { grid-template-columns: 1fr; }
  .order-card-photo { width: 110px; }
  .revenue-bar { grid-template-columns: 1fr 1fr; }
  .modal-footer { flex-direction: column; align-items: stretch; gap: 10px; }
  .complete-btn { width: 100%; text-align: center; }
}
</style>
</head>
<body>

 <nav class="navbar">
  <div class="nav-left">
    <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
  </div>

  <div class="nav-right">
    <div class="nav-search-wrap" id="searchWrap">
      <svg class="search-icon" width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
        <circle cx="11" cy="11" r="8"/>
        <path d="M21 21l-4.35-4.35"/>
      </svg>
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
    </div>

    <button id="hamburger" class="hamburger" onclick="toggleMobileMenu()" aria-label="Open menu">
      <span></span>
      <span></span>
      <span></span>
    </button>
  </div>
</nav>
<div class="mobile-menu" id="mobileMenu">
  <a href="provider-dashboard.php" onclick="closeMobileMenu()">Dashboard</a>
  <a href="provider-items.php" onclick="closeMobileMenu()">Items</a>
  <a href="provider-orders.php" onclick="closeMobileMenu()" style="color:#fff;">Orders</a>
  <a href="provider-profile.php" onclick="closeMobileMenu()">Profile</a>
  <a href="provider-dashboard.php?logout=1" onclick="closeMobileMenu()">Log out</a>
  <div class="mobile-search">
    <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
      <circle cx="11" cy="11" r="7"></circle>
      <path d="m21 21-4.3-4.3"></path>
    </svg>
    <input type="text" id="mobileSearchInput" placeholder="Search items ..." autocomplete="off"/>
    <div class="mobile-search-dropdown" id="mobileSearchDropdown"></div>
  </div>
</div>


<div class="page-body">
  <aside class="sidebar">
    <p class="sidebar-welcome">Welcome Back ,</p>
    <p class="sidebar-name"><?= htmlspecialchars($firstName) ?></p>
    <nav class="sidebar-nav">
      <a href="provider-dashboard.php" class="sidebar-link">
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
    <button class="sidebar-logout" onclick="window.location.href='provider-dashboard.php?logout=1'">Logout</button>
    <div class="sidebar-footer">
      <div class="sidebar-footer-social">
        <a href="#" class="sidebar-social-icon">in</a>
        <a href="#" class="sidebar-social-icon">&#120143;</a>
        <a href="#" class="sidebar-social-icon">&#9834;</a>
      </div>
      <div class="sidebar-footer-copy">
        <span>2026</span>
        <img src="../../images/Replate-white.png" alt="" style="height:40px;object-fit:contain;opacity:.45;">
        <span>All rights reserved.</span>
      </div>
    </div>
  </aside>

  <main class="main">
    <div class="orders-page-wrap">

      <div class="page-header"><h1>My Orders</h1></div>

      <!-- ── Revenue Summary Bar ── -->
      <div class="revenue-bar">
        <div class="revenue-card">
          <div class="revenue-label">Today</div>
          <?php if ($revenueToday > 0): ?>
            <div class="revenue-value orange"><?= number_format($revenueToday, 2) ?> <img src="../../images/SAR.png" alt=""></div>
          <?php else: ?>
            <div class="revenue-value" style="color:#8aa3c0;">—</div>
          <?php endif; ?>
          <div class="revenue-sub">Revenue today</div>
        </div>
        <div class="revenue-card">
          <div class="revenue-label">This Week</div>
          <?php if ($revenueWeek > 0): ?>
            <div class="revenue-value orange"><?= number_format($revenueWeek, 2) ?> <img src="../../images/SAR.png" alt=""></div>
          <?php else: ?>
            <div class="revenue-value" style="color:#8aa3c0;">—</div>
          <?php endif; ?>
          <div class="revenue-sub">Last 7 days</div>
        </div>
        <div class="revenue-card">
          <div class="revenue-label">Total Earned</div>
          <?php if ($revenueTotal > 0): ?>
            <div class="revenue-value orange"><?= number_format($revenueTotal, 2) ?> <img src="../../images/SAR.png" alt=""></div>
          <?php else: ?>
            <div class="revenue-value" style="color:#8aa3c0;">—</div>
          <?php endif; ?>
          <div class="revenue-sub">All time</div>
        </div>
        <div class="revenue-card">
          <div class="revenue-label">Orders</div>
          <div class="revenue-value">
            <span style="color:#e07a1a;"><?= $pendingCount ?></span>
            <span style="font-size:14px;color:#8aa3c0;font-weight:400;"> pending</span>
          </div>
          <div class="revenue-sub"><?= $completedCount ?> completed</div>
        </div>
      </div>

      <div class="orders-header-bar">
        <a class="seg-btn <?= $tab==='pending'   ? 'active':'' ?>" href="provider-orders.php?tab=pending">Pending</a>
        <a class="seg-btn <?= $tab==='completed' ? 'active':'' ?>" href="provider-orders.php?tab=completed">Completed</a>
      </div>

      <div class="orders-grid">
        <?php if (empty($ordersToShow)): ?>
          <div class="empty-state">
            <svg width="64" height="64" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24">
              <path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/>
              <rect x="9" y="3" width="6" height="4" rx="1"/>
              <line x1="9" y1="12" x2="15" y2="12"/><line x1="9" y1="16" x2="12" y2="16"/>
            </svg>
            <p>No <?= htmlspecialchars($tab) ?> orders yet.</p>
          </div>
        <?php else: ?>
          <?php foreach ($ordersToShow as $ord): ?>
            <?php
              $isDonation  = $ord['isDonation'];
              $statusBadge = $ord['status'] === 'completed'
                ? '<span class="badge badge-completed">Completed</span>'
                : '<span class="badge badge-pending">Pending</span>';
            ?>
            <div class="order-grid-card" onclick='openOrderModal(<?= htmlspecialchars(json_encode($ord, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS), ENT_QUOTES) ?>)'>
              <div class="order-card-photo">
                <?php if (!empty($ord['firstPhoto'])): ?>
                  <img src="<?= htmlspecialchars($ord['firstPhoto']) ?>" alt="" onerror="this.parentElement.innerHTML='<div class=\'order-card-photo-placeholder\'>No Image</div>'">
                <?php else: ?>
                  <div class="order-card-photo-placeholder">No Image</div>
                <?php endif; ?>
                <span class="item-count-badge"><?= $ord['itemCount'] ?> <?= $ord['itemCount'] === 1 ? 'item' : 'items' ?></span>
              </div>
              <div class="order-card-body">
                <div class="order-card-top-row">
                  <div class="order-card-name">#<?= htmlspecialchars($ord['orderNumber']) ?></div>
                  <?= $statusBadge ?>
                </div>
                <div class="order-card-divider"></div>
                <?php if ($ord['customerName']): ?>
                  <div class="order-card-meta">
                    <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    <?= htmlspecialchars($ord['customerName']) ?>
                  </div>
                <?php endif; ?>
                <div class="order-card-meta">
                  <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                  <?= htmlspecialchars($ord['placedDate']) ?>
                </div>
                <div class="order-card-price <?= $isDonation ? 'donate-price' : '' ?>">
                  <?php if ($isDonation): ?>
                    Donation
                  <?php else: ?>
                    <?= number_format($ord['orderTotal'], 2) ?> <img src="../../images/SAR.png" alt="">
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>

<!-- ── ORDER DETAIL MODAL ── -->
<div id="orderModal" class="modal-overlay" onclick="if(event.target===this)closeOrderModal()">
  <div class="modal-box order-detail-box">
    <button class="close-modal-btn" onclick="closeOrderModal()">&times;</button>
    <div class="modal-header">
      <div class="modal-order-number" id="modalOrderNumber">—</div>
      <div class="modal-meta-row">
        <div class="modal-meta-chip" id="modalCustomer"></div>
        <div class="modal-meta-chip" id="modalDate"></div>
      </div>
    </div>
    <div class="modal-items-scroll" id="modalItemsList"></div>
    <div class="modal-footer">
      <div class="modal-total">Total:&nbsp;<span class="modal-total-num" id="modalTotal">—</span></div>
      <div id="modalAction"></div>
    </div>
  </div>
</div>

<!-- ── TOAST ── -->
<div class="toast" id="toast"></div>


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
            <a class="sd-row" href="provider-items.php?openItem=${esc(item.id)}">
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
  <script>
function toggleMobileMenu() {
  const menu = document.getElementById('mobileMenu');
  const btn  = document.getElementById('hamburger');
  menu.classList.toggle('open');
  btn.classList.toggle('open');
  document.body.style.overflow = menu.classList.contains('open') ? 'hidden' : '';
}

function closeMobileMenu() {
  document.getElementById('mobileMenu').classList.remove('open');
  document.getElementById('hamburger').classList.remove('open');
  document.body.style.overflow = '';
  // Also clear mobile search
  const msd = document.getElementById('mobileSearchDropdown');
  if (msd) { msd.classList.remove('visible'); msd.innerHTML = ''; }
  const msi = document.getElementById('mobileSearchInput');
  if (msi) msi.value = '';
}

// ── Mobile search — independent, renders into its own dropdown ────────────────
(function() {
  const mInput    = document.getElementById('mobileSearchInput');
  const mDropdown = document.getElementById('mobileSearchDropdown');
  if (!mInput || !mDropdown) return;

  let mTimer = null;

  mInput.addEventListener('input', function() {
    clearTimeout(mTimer);
    const q = this.value.trim();
    if (q.length < 2) { mDropdown.classList.remove('visible'); mDropdown.innerHTML = ''; return; }
    mDropdown.innerHTML = '<div class="sd-loading">Searching...</div>';
    mDropdown.classList.add('visible');
    mTimer = setTimeout(() => mDoSearch(q), 300);
  });

  function mDoSearch(q) {
    fetch(`../../back-end/provider-search.php?q=${encodeURIComponent(q)}`)
      .then(r => r.json())
      .then(data => mRenderResults(data, q))
      .catch(() => { mDropdown.innerHTML = '<div class="sd-empty">Something went wrong.</div>'; });
  }

  function mRenderResults(data, q) {
    const items = data.items || [];
    if (!items.length) {
      mDropdown.innerHTML = '<div class="sd-empty">No items found.</div>'; return;
    }
    let html = '<div class="sd-section-title">Items</div>';
    items.forEach(item => {
      const thumb = item.photoUrl
        ? `<img src="${mEsc(item.photoUrl)}" alt="" onerror="this.style.display='none'">`
        : `<svg width="20" height="20" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="3"/></svg>`;
      const badgeClass = item.listingType === 'donate' ? 'sd-badge-donate' : 'sd-badge-sell';
      const badgeLabel = item.listingType === 'donate' ? 'Donation' : 'Selling';
      html += `<a class="sd-row" href="provider-items.php?openItem=${mEsc(item.id)}">
        <div class="sd-thumb">${thumb}</div>
        <div class="sd-info"><div class="sd-name">${mEsc(item.name)}</div><div class="sd-sub">${mEsc(item.price)}</div></div>
        <span class="sd-badge ${badgeClass}">${badgeLabel}</span>
      </a>`;
    });
    mDropdown.innerHTML = html;
  }

  function mEsc(str) {
    const d = document.createElement('div');
    d.textContent = String(str ?? '');
    return d.innerHTML;
  }
})();
</script>

<script>
// ── Order Detail Modal ────────────────────────────────────────────────────────
let _currentOrderId = null;

function openOrderModal(data) {
  _currentOrderId = data.orderId;

  document.getElementById('modalOrderNumber').textContent = 'Order #' + (data.orderNumber || '—');

  const custEl = document.getElementById('modalCustomer');
  custEl.innerHTML = data.customerName
    ? `<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg> ${data.customerName}`
    : '';

  document.getElementById('modalDate').innerHTML =
    `<svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ${data.placedDate || '—'}`;

  const list = document.getElementById('modalItemsList');
  list.innerHTML = '';
  data.items.forEach((it, idx) => {
    const thumb = it.photoUrl
      ? `<div class="modal-item-thumb"><img src="${it.photoUrl}" alt="${it.itemName}"></div>`
      : `<div class="modal-item-thumb"><div class="modal-item-thumb-placeholder">No Image</div></div>`;

    const priceHtml = it.isDonation
      ? `<div class="modal-item-price is-donate">Donation</div>`
      : `<div class="modal-item-price">${(it.price * it.quantity).toFixed(2)} <img src="../../images/SAR.png" alt=""></div>`;

    const metaParts = [`Qty: ${it.quantity}`];
    if (it.pickupDate) metaParts.push(it.pickupDate);
    if (it.pickupTime) metaParts.push(it.pickupTime);
    const meta = metaParts.join('  ·  ');

    let mapHtml = '';
    if (it.pickupLocation) {
      const mapDivId = `ordermap_${idx}`;
      mapHtml = `
        <div class="map-acc">
          <div class="map-acc-header" onclick="toggleMapAcc(this, ${idx})">
            <div>
              <div style="display:flex;align-items:center;gap:6px;">
                <svg width="12" height="12" fill="none" stroke="#1a3a6b" stroke-width="2" viewBox="0 0 24 24"><path d="M17.657 16.657L13.414 20.9a2 2 0 01-2.828 0l-4.243-4.243a8 8 0 1111.314 0z"/><circle cx="12" cy="11" r="3"/></svg>
                Pickup Location
              </div>
              <div class="map-acc-addr">${it.pickupLocation}</div>
            </div>
            <svg class="map-acc-chevron" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <div class="map-acc-body" data-lat="${it.pickupLat||''}" data-lng="${it.pickupLng||''}" data-mapid="${mapDivId}">
            <div id="${mapDivId}" style="width:100%;height:180px;display:block;"></div>
          </div>
        </div>`;
    }

    list.innerHTML += `
      <div class="modal-item-block">
        <div class="modal-item-row">
          ${thumb}
          <div class="modal-item-info">
            <div class="modal-item-name">${it.itemName}</div>
            <div class="modal-item-meta">${meta}</div>
          </div>
          ${priceHtml}
        </div>
        ${mapHtml}
      </div>`;
  });

  const totalEl = document.getElementById('modalTotal');
  if (data.isDonation) {
    totalEl.innerHTML = 'Donation'; totalEl.className = 'modal-total-num is-donate';
  } else {
    totalEl.innerHTML = `${parseFloat(data.orderTotal).toFixed(2)} <img src="../../images/SAR.png" alt="">`;
    totalEl.className = 'modal-total-num';
  }

  const actionEl = document.getElementById('modalAction');
  if (data.status !== 'completed') {
    actionEl.innerHTML = `<button class="complete-btn" onclick="markOrderCompleted()">✓ Mark as Completed</button>`;
  } else {
    actionEl.innerHTML = `<span class="completed-label"><svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg> Order Completed</span>`;
  }

  document.getElementById('orderModal').classList.add('open');
}

const _leafletMaps = {};
function toggleMapAcc(header, idx) {
  const body = header.nextElementSibling;
  const isOpen = body.classList.toggle('open');
  header.querySelector('.map-acc-chevron').classList.toggle('open', isOpen);
  if (isOpen && !_leafletMaps[idx]) {
    const lat = parseFloat(body.dataset.lat);
    const lng = parseFloat(body.dataset.lng);
    const mapId = body.dataset.mapid;
    if (!isNaN(lat) && !isNaN(lng)) {
      setTimeout(() => {
        const m = L.map(mapId, {
          zoomControl: false, dragging: false, scrollWheelZoom: false,
          doubleClickZoom: false, boxZoom: false, keyboard: false, tap: false, touchZoom: false
        }).setView([lat, lng], 14);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; OpenStreetMap contributors'
        }).addTo(m);
        L.marker([lat, lng]).addTo(m);
        setTimeout(() => m.invalidateSize(), 100);
        _leafletMaps[idx] = m;
      }, 50);
    }
  }
}

function closeOrderModal() {
  document.getElementById('orderModal').classList.remove('open');
  _currentOrderId = null;
}

function markOrderCompleted() {
  if (!_currentOrderId) return;
  const btn = document.querySelector('.complete-btn');
  if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }
  const fd = new FormData();
  fd.append('action',  'complete_order');
  fd.append('orderId', _currentOrderId);
  fetch('provider-orders.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        closeOrderModal(); showToast('✓ Order marked as completed!', 'success');
        setTimeout(() => location.reload(), 900);
      } else {
        showToast('Could not complete order. Please try again.', 'error');
        if (btn) { btn.disabled = false; btn.textContent = '✓ Mark as Completed'; }
      }
    })
    .catch(() => { showToast('Something went wrong.', 'error'); if (btn) { btn.disabled = false; btn.textContent = '✓ Mark as Completed'; } });
}

function showToast(msg, type='error') {
  const t = document.getElementById('toast');
  t.textContent = msg; t.style.background = type==='success' ? '#1a6b3a' : '#c0392b';
  t.classList.add('show'); setTimeout(() => t.classList.remove('show'), 3000);
}
</script>
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
</body>
</html>
</body>
</html>