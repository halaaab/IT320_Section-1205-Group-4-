<?php
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Customer.php';
require_once '../../back-end/models/Notification.php';
require_once '../../back-end/models/Favourite.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Item.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

if (isset($_GET['delete'])) {
    $customerModel = new Customer();
    $customerModel->deleteById($_SESSION['customerId']);
    session_destroy();
    header('Location: ../shared/landing.php');
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../shared/landing.php');
    exit;
}

$customerId    = $_SESSION['customerId'];
$customerModel = new Customer();
$customer      = $customerModel->findById($customerId);
$errors        = [];
$success       = false;
$editMode      = isset($_GET['edit']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName    = trim($_POST['fullName']        ?? '');
    $phoneNumber = trim($_POST['phoneNumber']     ?? '');
    $currentPw   = $_POST['currentPassword']     ?? '';
    $newPw       = $_POST['newPassword']          ?? '';
    $confirmPw   = $_POST['confirmPassword']      ?? '';

    $newEmail = trim($_POST['email'] ?? '');
    if (!$fullName) $errors['fullName'] = 'Name is required.';
    if ($newEmail && !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if ($newPw) {
        if (!$customerModel->verifyPassword($currentPw, $customer['passwordHash'])) {
            $errors['currentPassword'] = 'Current password is incorrect.';
        }
        if (strlen($newPw) < 8) {
            $errors['newPassword'] = 'New password must be at least 8 characters.';
        }
        if ($newPw !== $confirmPw) {
            $errors['confirmPassword'] = 'Passwords do not match.';
        }
    }

    if (empty($errors)) {
        $updateData = ['fullName' => $fullName, 'phoneNumber' => $phoneNumber];
        if ($newEmail) $updateData['email'] = strtolower($newEmail);
        if ($newPw) $updateData['passwordHash'] = password_hash($newPw, PASSWORD_BCRYPT);
        $customerModel->updateById($customerId, $updateData);
        $_SESSION['userName'] = $fullName;
        $customer = $customerModel->findById($customerId);
        $success  = true;
        $editMode = false;
    } else {
        $editMode = true;
    }
}

$firstName = explode(' ', $customer['fullName'] ?? '')[0];

// ── Fetch expiry alerts: items in cart OR favourites expiring within 48h ──
$expiryAlerts = [];
$now   = time();
$soon  = $now + 48 * 3600;

// Cart item IDs
$cartModel  = new Cart();
$cart       = $cartModel->getOrCreate($customerId);
$cartItemIds = array_map(fn($ci) => (string)$ci['itemId'], (array)($cart['cartItems'] ?? []));

// Favourite item IDs
$favModel    = new Favourite();
$favs        = $favModel->getByCustomer($customerId);
$favItemIds  = array_map(fn($f) => (string)$f['itemId'], $favs);

$watchedIds  = array_unique(array_merge($cartItemIds, $favItemIds));

$itemModel   = new Item();
foreach ($watchedIds as $itemId) {
    try {
        $item = $itemModel->findById($itemId);
        if (!$item || !isset($item['expiryDate'])) continue;
        $expiry = $item['expiryDate']->toDateTime()->getTimestamp();
        if ($expiry >= $now && $expiry <= $soon) {
            $hoursLeft = ceil(($expiry - $now) / 3600);
            $source    = in_array($itemId, $cartItemIds) ? 'cart' : 'favourites';
            $expiryAlerts[] = [
                'id'        => $itemId,
                'name'      => $item['itemName'] ?? 'Item',
                'hoursLeft' => $hoursLeft,
                'source'    => $source,
            ];
        }
    } catch (Throwable) { continue; }
}
$alertCount = count($expiryAlerts);
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RePlate – My Profile</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'Playfair Display', serif; background: #fff; min-height: 100vh; display: flex; flex-direction: column; }

    nav.navbar { display: flex; align-items: center; justify-content: space-between; padding: 0 40px; height: 72px; background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%); position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 16px rgba(26,58,107,0.18); }
    .nav-logo { height: 100px; }
    .nav-left { display: flex; align-items: center; gap: 16px; }
    .nav-cart { width: 40px; height: 40px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.7); display: flex; align-items: center; justify-content: center; text-decoration: none; transition: background 0.2s; }
    .nav-cart:hover { background: rgba(255,255,255,0.15); }
    .nav-center { display: flex; align-items: center; gap: 40px; }
    .nav-center a { color: rgba(255,255,255,0.85); text-decoration: none; font-weight: 500; font-size: 15px; transition: color 0.2s; }
    .nav-center a:hover { color: #fff; }
    .nav-right { display: flex; align-items: center; gap: 12px; }
    .nav-search-wrap { position: relative; }
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
    .search-empty { padding: 24px 16px; text-align: center; color: #b0c4d8; font-size: 14px; font-family: 'Playfair Display', serif; }
    .search-no-match { padding: 8px 16px 12px; font-size: 13px; color: #b0c4d8; font-style: italic; }
    .search-loading { padding: 18px 16px; text-align: center; color: #b0c4d8; font-size: 13px; }
    .search-provider-logo { width: 38px; height: 38px; border-radius: 50%; background: #e0eaf5; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; color: #2255a4; }
    .search-provider-logo img { width: 100%; height: 100%; object-fit: cover; }
    .nav-search-wrap svg { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); opacity: 0.6; pointer-events: none; }
    .nav-search-wrap input { background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.4); border-radius: 50px; padding: 9px 16px 9px 36px; color: #fff; font-size: 14px; outline: none; width: 240px; font-family: 'Playfair Display', serif; transition: width 0.3s, background 0.2s; }
    .nav-search-wrap input::placeholder { color: rgba(255,255,255,0.6); }
    .nav-search-wrap input:focus { width: 300px; background: rgba(255,255,255,0.25); }
    .nav-avatar { width: 38px; height: 38px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); display: flex; align-items: center; justify-content: center; cursor: pointer; text-decoration: none; background: rgba(255,255,255,0.15); }
    .nav-avatar:hover { background: rgba(255,255,255,0.25); }
    .nav-bell-wrap { position: relative; }
    .nav-bell { width: 38px; height: 38px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.6); display: flex; align-items: center; justify-content: center; cursor: pointer; background: none; transition: background 0.2s; }
    .nav-bell:hover { background: rgba(255,255,255,0.15); }
    .bell-badge { position: absolute; top: -3px; right: -3px; width: 18px; height: 18px; background: #e07a1a; border-radius: 50%; border: 2px solid transparent; display: flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: #fff; pointer-events: none; }
    .notif-dropdown { display: none; position: absolute; top: 48px; right: 0; width: 320px; background: #fff; border-radius: 16px; box-shadow: 0 8px 40px rgba(26,58,107,0.18); border: 1.5px solid #e0eaf5; z-index: 9999; overflow: hidden; }
    .notif-dropdown.open { display: block; }
    .notif-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px 12px; border-bottom: 1.5px solid #f0f5fc; }
    .notif-header-title { font-size: 15px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; }
    .notif-empty { padding: 28px 18px; text-align: center; color: #b0c4d8; font-size: 14px; }
    .notif-item { display: flex; align-items: flex-start; gap: 12px; padding: 14px 18px; border-bottom: 1px solid #f5f8fc; transition: background 0.15s; }
    .notif-item:last-child { border-bottom: none; }
    .notif-item:hover { background: #f8fbff; }
    .notif-icon { width: 36px; height: 36px; border-radius: 50%; background: #fff4e6; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
    .notif-icon svg { stroke: #e07a1a; }
    .notif-text { flex: 1; }
    .notif-name { font-size: 14px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; margin-bottom: 3px; }
    .notif-meta { font-size: 12px; color: #7a8fa8; display: flex; align-items: center; gap: 6px; }
    .notif-source-tag { background: #e8f0ff; color: #2255a4; border-radius: 50px; padding: 2px 8px; font-size: 11px; font-weight: 700; }
    .notif-source-tag.cart { background: #e8f7ee; color: #1a6b3a; }
    .notif-hours { color: #e07a1a; font-weight: 700; }

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

    .main { flex: 1; padding: 40px 48px; background: #fff; overflow-y: auto; }
    .dashboard-grid { display: grid; grid-template-columns: 1fr 380px; gap: 32px; align-items: start; }
    .profile-col { display: flex; flex-direction: column; }
    .notif-col { display: flex; flex-direction: column; }

    /* Notification center panel */
    .notif-panel { background: #f8fbff; border-radius: 20px; border: 1.5px solid #e0eaf5; overflow: hidden; position: sticky; top: 24px; }
    .notif-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 18px 20px 14px; border-bottom: 1.5px solid #e8f0fa; background: #fff; }
    .notif-panel-title { font-size: 17px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; display: flex; align-items: center; gap: 8px; }
    .notif-count-badge { background: #e07a1a; color: #fff; border-radius: 50px; padding: 2px 10px; font-size: 12px; font-weight: 700; }
    .notif-count-badge.zero { background: #e0eaf5; color: #8a9ab5; }
    .mark-read-btn { font-size: 12px; color: #2255a4; background: none; border: none; cursor: pointer; font-family: 'Playfair Display', serif; font-weight: 600; padding: 0; transition: color 0.2s; }
    .mark-read-btn:hover { color: #1a3a6b; }
    .notif-panel-body { max-height: 520px; overflow-y: auto; }
    .notif-panel-body::-webkit-scrollbar { width: 4px; }
    .notif-panel-body::-webkit-scrollbar-track { background: transparent; }
    .notif-panel-body::-webkit-scrollbar-thumb { background: #c8d8ee; border-radius: 4px; }

    .notif-card { display: flex; align-items: flex-start; gap: 12px; padding: 14px 20px; border-bottom: 1px solid #eef4fc; transition: background 0.15s; cursor: pointer; }
    .notif-card:last-child { border-bottom: none; }
    .notif-card:hover { background: #f0f6ff; }
    .notif-card.unread { background: #fff; border-left: 3px solid #e07a1a; }
    .notif-card.unread:hover { background: #fff9f4; }
    .notif-card-icon { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; margin-top: 2px; }
    .notif-card-icon.expiry { background: #fff4e6; }
    .notif-card-icon.order { background: #e8f7ee; }
    .notif-card-icon.expiry svg { stroke: #e07a1a; }
    .notif-card-icon.order svg { stroke: #1a6b3a; }
    .notif-card-body { flex: 1; min-width: 0; }
    .notif-card-title { font-size: 13px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; margin-bottom: 3px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .notif-card-sub { font-size: 12px; color: #7a8fa8; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .tag { border-radius: 50px; padding: 2px 8px; font-size: 11px; font-weight: 700; }
    .tag-expiry { background: #fff4e6; color: #e07a1a; }
    .tag-cart { background: #e8f7ee; color: #1a6b3a; }
    .tag-fav { background: #e8f0ff; color: #2255a4; }
    .tag-order { background: #e8f7ee; color: #1a6b3a; }
    .notif-card-time { font-size: 11px; color: #b0c4d8; margin-top: 4px; }
    .notif-panel-empty { padding: 40px 20px; text-align: center; color: #b0c4d8; font-size: 14px; font-family: 'Playfair Display', serif; }
    .notif-panel-empty svg { display: block; margin: 0 auto 12px; }

    .profile-header { display: flex; align-items: center; gap: 16px; margin-bottom: 32px; flex-wrap: wrap; }
    .profile-title { font-size: 30px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; }
    .header-actions { display: flex; gap: 10px; align-items: center; }

    .btn-edit { background: #e07a1a; color: #fff; border: none; border-radius: 50px; padding: 12px 28px; font-size: 16px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s, transform 0.15s; text-decoration: none; }
    .btn-edit:hover { background: #c96a10; transform: translateY(-1px); }
    .btn-save { background: #1a3a6b; color: #fff; border: none; border-radius: 50px; padding: 12px 28px; font-size: 16px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: background 0.2s, transform 0.15s; }
    .btn-save:hover { background: #2255a4; transform: translateY(-1px); }
    .btn-cancel { background: transparent; color: #8a9ab5; border: 2px solid #c8d8ee; border-radius: 50px; padding: 10px 22px; font-size: 15px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; transition: border-color 0.2s, color 0.2s; text-decoration: none; }
    .btn-cancel:hover { border-color: #8a9ab5; color: #4a6a9a; }

    .field-row { display: flex; align-items: center; margin-bottom: 32px; gap: 0; }
    .field-label { font-size: 16px; font-weight: 700; color: #1a3a6b; min-width: 160px; font-family: 'Playfair Display', serif; }
    .field-col { flex: 1; max-width: 420px; display: flex; flex-direction: column; }
    .field-value { flex: 1; max-width: 420px; padding: 14px 22px; border-radius: 50px; border: 1.5px solid #d0ddf0; background: #fff; font-size: 15px; font-family: 'Playfair Display', serif; color: #3a5a8a; outline: none; transition: border-color 0.2s, box-shadow 0.2s; width: 100%; }
    .field-value[readonly] { background: #fff; color: #3a5a8a; cursor: default; }
    .field-value:not([readonly]):focus { border-color: #2255a4; box-shadow: 0 0 0 3px rgba(34,85,164,0.1); }
    .field-value.error { border-color: #c0392b; }
    .field-error { font-size: 12px; color: #c0392b; margin-top: 4px; padding-left: 22px; display: none; }
    .field-error.show { display: block; }

    .pw-section { margin-top: 8px; padding-top: 24px; border-top: 1.5px solid #e8f0f8; }
    .pw-section-title { font-size: 14px; font-weight: 700; color: #8a9ab5; letter-spacing: 0.08em; text-transform: uppercase; margin-bottom: 20px; }
    .password-wrap { position: relative; }
    .password-wrap .field-value { padding-right: 48px; }
    .toggle-pw { position: absolute; right: 16px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; padding: 0; display: flex; align-items: center; }

    .success-banner { background: #e8f7ee; border: 1.5px solid #a8d8b8; border-radius: 12px; padding: 14px 22px; color: #1a6b3a; font-size: 15px; font-weight: 600; margin-bottom: 28px; display: flex; align-items: center; gap: 10px; }
    .btn-delete { background: transparent; color: #c0392b; border: 2px solid #c0392b; border-radius: 50px; padding: 10px 24px; font-size: 14px; font-weight: 700; font-family: 'Playfair Display', serif; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; transition: background 0.2s, color 0.2s; }
    .btn-delete:hover { background: #c0392b; color: #fff; }
  </style>
</head>
<body>

  <nav class="navbar">
    <div class="nav-left">
      <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
      <a href="../customer/cart.php" class="nav-cart">
        <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;"/>
      </a>
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
        <button class="nav-bell" id="bellBtn" onclick="toggleNotifDropdown()">
          <svg width="18" height="18" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
        </button>
        <?php if ($alertCount > 0): ?>
        <span class="bell-badge"><?= $alertCount ?></span>
        <?php endif; ?>

        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-header">
            <span class="notif-header-title">⏰ Expiring Soon</span>
            <span style="font-size:12px;color:#b0c4d8;"><?= $alertCount ?> alert<?= $alertCount !== 1 ? 's' : '' ?></span>
          </div>
          <?php if (empty($expiryAlerts)): ?>
          <div class="notif-empty">
            <svg width="32" height="32" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 8px;display:block;"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
            No expiry alerts right now
          </div>
          <?php else: ?>
          <?php foreach ($expiryAlerts as $alert): ?>
          <div class="notif-item">
            <div class="notif-icon">
              <svg width="16" height="16" fill="none" stroke="#e07a1a" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            </div>
            <div class="notif-text">
              <p class="notif-name"><?= htmlspecialchars($alert['name']) ?></p>
              <div class="notif-meta">
                <span class="notif-hours">⏳ <?= $alert['hoursLeft'] ?>h left</span>
                <span class="notif-source-tag <?= $alert['source'] === 'cart' ? 'cart' : '' ?>">
                  <?= $alert['source'] === 'cart' ? '🛒 Cart' : '♥ Favourites' ?>
                </span>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      <a href="customer-profile.php" class="nav-avatar">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
    </div>
  </nav>

  <div class="page-body">
    <aside class="sidebar">
      <p class="sidebar-welcome">Welcome Back ,</p>
      <p class="sidebar-name"><?= htmlspecialchars($firstName) ?></p>
      <nav class="sidebar-nav">
        <a href="customer-profile.php" class="sidebar-link active">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          Profile
        </a>
        <a href="favorites.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M19 21l-7-5-7 5V5a2 2 0 012-2h10a2 2 0 012 2z"/></svg>
          Favourites
        </a>
        <a href="orders.php" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg>
          Orders
        </a>
        <a href="#" class="sidebar-link">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
          Notification
        </a>
        <a href="contact.php" class="sidebar-link">
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
          <svg width="13" height="13" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2" viewBox="0 0 24 24">
            <rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 7l10 7 10-7"/>
          </svg>
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
      <?php if ($success): ?>
      <div class="success-banner">
        <svg width="18" height="18" fill="none" stroke="#1a6b3a" stroke-width="2" viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>
        Profile updated successfully!
      </div>
      <?php endif; ?>

      <div class="dashboard-grid">
        <!-- LEFT: Profile form -->
        <div class="profile-col">
          <div class="profile-header">
            <h1 class="profile-title"><?= htmlspecialchars($firstName) ?>'s Profile</h1>
            <div class="header-actions">
              <?php if ($editMode): ?>
                <a href="customer-profile.php" class="btn-cancel">Cancel</a>
                <button type="submit" form="profileForm" class="btn-save">
                  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                  Save
                </button>
              <?php else: ?>
                <a href="customer-profile.php?edit=1" class="btn-edit">
                  Edit
                  <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                </a>
              <?php endif; ?>
            </div>
          </div>

      <form method="POST" id="profileForm">
        <div class="field-row">
          <label class="field-label" for="fullName">Full Name</label>
          <div class="field-col">
            <input class="field-value <?= isset($errors['fullName']) ? 'error' : '' ?>" id="fullName" name="fullName" type="text" value="<?= htmlspecialchars($customer['fullName'] ?? '') ?>" <?= $editMode ? '' : 'readonly' ?>/>
            <span class="field-error <?= isset($errors['fullName']) ? 'show' : '' ?>"><?= htmlspecialchars($errors['fullName'] ?? '') ?></span>
          </div>
        </div>

        <div class="field-row">
          <label class="field-label" for="email">Email Address</label>
          <div class="field-col">
            <input class="field-value <?= isset($errors['email']) ? 'error' : '' ?>" id="email" name="email" type="email" value="<?= htmlspecialchars($customer['email'] ?? '') ?>" <?= $editMode ? '' : 'readonly' ?>/>
            <span class="field-error <?= isset($errors['email']) ? 'show' : '' ?>"><?= htmlspecialchars($errors['email'] ?? '') ?></span>
          </div>
        </div>

        <div class="field-row">
          <label class="field-label" for="phoneNumber">
              Phone Number
              <span style="font-size:11px;font-weight:400;color:#b0c4d8;display:block;margin-top:2px;">Optional</span>
            </label>
          <div class="field-col">
            <input class="field-value" id="phoneNumber" name="phoneNumber" type="text" value="<?= htmlspecialchars($customer['phoneNumber'] ?? '') ?>" placeholder="e.g. +966 5X XXX XXXX" <?= $editMode ? '' : 'readonly' ?>/>
          </div>
        </div>

        <?php if (!$editMode): ?>
        <div class="field-row">
          <label class="field-label">Password</label>
          <div class="field-col">
            <input class="field-value" type="text" value="xxxxxxxxxxxxxxxxx" readonly/>
          </div>
        </div>
        <?php else: ?>
        <div class="pw-section">
          <p class="pw-section-title">Change Password <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#b0c4d8;">(leave blank to keep current)</span></p>
          <div class="field-row">
            <label class="field-label" for="currentPassword">Current Password</label>
            <div class="field-col">
              <div class="password-wrap">
                <input class="field-value <?= isset($errors['currentPassword']) ? 'error' : '' ?>" id="currentPassword" name="currentPassword" type="password" placeholder="Enter current password"/>
                <button type="button" class="toggle-pw" onclick="togglePw('currentPassword')" tabindex="-1"><svg width="18" height="18" fill="none" stroke="#8a9ab5" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
              </div>
              <span class="field-error <?= isset($errors['currentPassword']) ? 'show' : '' ?>"><?= htmlspecialchars($errors['currentPassword'] ?? '') ?></span>
            </div>
          </div>
          <div class="field-row">
            <label class="field-label" for="newPassword">New Password</label>
            <div class="field-col">
              <div class="password-wrap">
                <input class="field-value <?= isset($errors['newPassword']) ? 'error' : '' ?>" id="newPassword" name="newPassword" type="password" placeholder="Min 8 characters"/>
                <button type="button" class="toggle-pw" onclick="togglePw('newPassword')" tabindex="-1"><svg width="18" height="18" fill="none" stroke="#8a9ab5" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
              </div>
              <span class="field-error <?= isset($errors['newPassword']) ? 'show' : '' ?>"><?= htmlspecialchars($errors['newPassword'] ?? '') ?></span>
            </div>
          </div>
          <div class="field-row">
            <label class="field-label" for="confirmPassword">Confirm Password</label>
            <div class="field-col">
              <div class="password-wrap">
                <input class="field-value <?= isset($errors['confirmPassword']) ? 'error' : '' ?>" id="confirmPassword" name="confirmPassword" type="password" placeholder="Repeat new password"/>
                <button type="button" class="toggle-pw" onclick="togglePw('confirmPassword')" tabindex="-1"><svg width="18" height="18" fill="none" stroke="#8a9ab5" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
              </div>
              <span class="field-error <?= isset($errors['confirmPassword']) ? 'show' : '' ?>"><?= htmlspecialchars($errors['confirmPassword'] ?? '') ?></span>
            </div>
          </div>
        </div>

        <!-- Danger Zone -->
        <div style="margin-top:32px;padding-top:24px;border-top:1.5px solid #f0e8e8;">
          <p style="font-size:13px;font-weight:700;color:#c0392b;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:10px;">Danger Zone</p>
          <p style="font-size:14px;color:#7a8fa8;margin-bottom:16px;line-height:1.6;">Permanently delete your account and all your data. This cannot be undone.</p>
          <button type="button" class="btn-delete" onclick="document.getElementById('deleteModal').style.display='flex'">
            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 01-2 2H8a2 2 0 01-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4h6v2"/></svg>
            Delete Account
          </button>
        </div>
        <?php endif; ?>
      </form>

      <!-- Delete Confirm Modal -->
      <div id="deleteModal" style="display:none;position:fixed;inset:0;background:rgba(12,22,45,0.5);z-index:9999;justify-content:center;align-items:center;">
        <div style="background:#fff;border-radius:20px;padding:40px;max-width:420px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.2);">
          <svg width="48" height="48" fill="none" stroke="#c0392b" stroke-width="1.5" viewBox="0 0 24 24" style="margin:0 auto 16px;display:block;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          <h3 style="font-size:22px;font-weight:700;color:#1a3a6b;margin-bottom:10px;font-family:'Playfair Display',serif;">Delete Account?</h3>
          <p style="font-size:14px;color:#7a8fa8;margin-bottom:28px;line-height:1.6;">This will permanently delete your account and all your data. You cannot undo this.</p>
          <div style="display:flex;gap:14px;justify-content:center;">
            <button onclick="document.getElementById('deleteModal').style.display='none'" style="padding:12px 28px;border-radius:50px;border:2px solid #c8d8ee;background:#fff;color:#7a8fa8;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;cursor:pointer;">Cancel</button>
            <form method="POST" action="customer-profile.php?delete=1" style="display:inline;">
              <button type="submit" style="padding:12px 28px;border-radius:50px;border:none;background:#c0392b;color:#fff;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;cursor:pointer;">Yes, Delete</button>
            </form>
          </div>
        </div>
      </div>
        </div><!-- /profile-col -->

        <!-- RIGHT: Notification Center -->
        <div class="notif-col">
          <div class="notif-panel">
            <div class="notif-panel-header">
              <div class="notif-panel-title">
                <svg width="18" height="18" fill="none" stroke="#1a3a6b" stroke-width="2" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                Notification Center
                <span class="notif-count-badge <?= $alertCount === 0 ? 'zero' : '' ?>"><?= $alertCount ?></span>
              </div>
              <?php if ($alertCount > 0): ?>
              <button class="mark-read-btn" onclick="markAllRead()">Mark all read</button>
              <?php endif; ?>
            </div>

            <div class="notif-panel-body" id="notifPanelBody">
              <?php if (empty($expiryAlerts)): ?>
              <div class="notif-panel-empty">
                <svg width="40" height="40" fill="none" stroke="#c8d8ee" stroke-width="1.5" viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>
                You're all caught up!<br>
                <span style="font-size:12px;">Items expiring within 48h will appear here</span>
              </div>
              <?php else: ?>
              <?php foreach ($expiryAlerts as $i => $alert): ?>
              <div class="notif-card unread" id="nc-<?= $i ?>">
                <div class="notif-card-icon expiry">
                  <svg width="16" height="16" fill="none" stroke="#e07a1a" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div class="notif-card-body">
                  <p class="notif-card-title"><?= htmlspecialchars($alert['name']) ?></p>
                  <div class="notif-card-sub">
                    <span class="tag tag-expiry">⏳ <?= $alert['hoursLeft'] ?>h left</span>
                    <span class="tag <?= $alert['source'] === 'cart' ? 'tag-cart' : 'tag-fav' ?>">
                      <?= $alert['source'] === 'cart' ? '🛒 In Cart' : '♥ Favourited' ?>
                    </span>
                  </div>
                  <p class="notif-card-time">Expiring soon — pick it up before it's gone</p>
                </div>
              </div>
              <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div><!-- /notif-col -->

      </div><!-- /dashboard-grid -->
    </main>
  </div>

  <script>
    // ── Live Search ──
    const searchInput    = document.getElementById('searchInput');
    const searchDropdown = document.getElementById('searchDropdown');
    const searchWrap     = document.getElementById('searchWrap');
    let searchTimer = null;

    searchInput?.addEventListener('input', function() {
      clearTimeout(searchTimer);
      const q = this.value.trim();
      if (q.length < 2) { closeSearch(); return; }
      searchDropdown.innerHTML = '<div class="search-loading">Searching...</div>';
      searchDropdown.classList.add('open');
      searchTimer = setTimeout(() => doSearch(q), 280);
    });

    searchInput?.addEventListener('keydown', e => { if (e.key === 'Escape') closeSearch(); });

    // ── Unified outside-click handler ──
    document.addEventListener('click', e => {
      if (searchWrap && !searchWrap.contains(e.target)) closeSearch();
      const bellWrap = document.querySelector('.nav-bell-wrap');
      if (bellWrap && !bellWrap.contains(e.target)) {
        document.getElementById('notifDropdown')?.classList.remove('open');
      }
    });

    function closeSearch() { searchDropdown?.classList.remove('open'); }

    async function doSearch(q) {
      try {
        const res  = await fetch(`../../back-end/search.php?q=${encodeURIComponent(q)}`);
        const data = await res.json();
        renderResults(data, q);
      } catch(e) {
        searchDropdown.innerHTML = '<div class="search-empty">Something went wrong.</div>';
      }
    }

    function renderResults({ items = [], providers = [] }, q) {
      let html = '';

      // Providers always first
      html += '<div class="search-section-label">Providers</div>';
      if (providers.length) {
        providers.forEach(p => {
          const logo = p.businessLogo
            ? `<div class="search-provider-logo"><img src="${p.businessLogo}"/></div>`
            : `<div class="search-provider-logo">${p.businessName.charAt(0).toUpperCase()}</div>`;
          html += `<a class="search-item-row" href="providers-page.php?id=${p.id}">
            ${logo}
            <div><p class="search-item-name">${hl(p.businessName,q)}</p><p class="search-item-sub">${p.category}</p></div>
          </a>`;
        });
      } else {
        html += `<div class="search-no-match">No providers match "<em>${q}</em>"</div>`;
      }

      html += '<div class="search-divider"></div>';

      // Products second
      html += '<div class="search-section-label">Products</div>';
      if (items.length) {
        items.forEach(item => {
          const thumb = item.photoUrl
            ? `<div class="search-thumb"><img src="${item.photoUrl}"/></div>`
            : '<div class="search-thumb">🍱</div>';
          html += `<a class="search-item-row" href="item-details.php?id=${item.id}">
            ${thumb}
            <div><p class="search-item-name">${hl(item.name,q)}</p><p class="search-item-sub">Product</p></div>
            <span class="search-price">${item.price}</span>
          </a>`;
        });
      } else {
        html += `<div class="search-no-match">No products match "<em>${q}</em>"</div>`;
      }

      searchDropdown.innerHTML = html;
      searchDropdown.classList.add('open');
    }

    function hl(text, q) {
      return text.replace(
        new RegExp(`(${q.replace(/[.*+?^${}()|[\]\\]/g,'\\$&')})`, 'gi'),
        '<mark style="background:#fff4e6;color:#e07a1a;border-radius:3px;padding:0 2px;">$1</mark>'
      );
    }

    // ── Bell dropdown ──
    function toggleNotifDropdown() {
      document.getElementById('notifDropdown').classList.toggle('open');
    }

    // ── Mark all read ──
    function markAllRead() {
      document.querySelectorAll('.notif-card.unread').forEach(c => c.classList.remove('unread'));
      document.querySelector('.mark-read-btn')?.remove();
      const badge = document.querySelector('.notif-count-badge');
      if (badge) { badge.textContent = '0'; badge.classList.add('zero'); }
    }

    // ── Password toggle ──
    function togglePw(id) {
      const input = document.getElementById(id);
      input.type = input.type === 'password' ? 'text' : 'password';
    }
  </script>
</body>
</html>