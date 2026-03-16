<?php
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Category.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Favourite.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Item.php';

$categoryModel = new Category();
$itemModel     = new Item();
$providerModel = new Provider();

$categories = $categoryModel->getAll();
$items      = $itemModel->getAvailable(['sort' => ['createdAt' => -1], 'limit' => 20]);
$providers  = $providerModel->findAll([], ['limit' => 8]);

$isLoggedIn = !empty($_SESSION['customerId']);
$userName   = $_SESSION['userName'] ?? '';

// Expiry alerts for logged-in customer
$expiryAlerts = [];
$alertCount   = 0;
if ($isLoggedIn) {
    $customerId  = $_SESSION['customerId'];
    $now  = time();
    $soon = $now + 48 * 3600;
    $cartModel   = new Cart();
    $cart        = $cartModel->getOrCreate($customerId);
    $cartItemIds = array_map(fn($ci) => (string)$ci['itemId'], (array)($cart['cartItems'] ?? []));
    $favModel    = new Favourite();
    $favs        = $favModel->getByCustomer($customerId);
    $favItemIds  = array_map(fn($f) => (string)$f['itemId'], $favs);
    $watchedIds  = array_unique(array_merge($cartItemIds, $favItemIds));
    $itemModel2  = new Item();
    foreach ($watchedIds as $wid) {
        try {
            $witem = $itemModel2->findById($wid);
            if (!$witem || !isset($witem['expiryDate'])) continue;
            $expiry = $witem['expiryDate']->toDateTime()->getTimestamp();
            if ($expiry >= $now && $expiry <= $soon) {
                $hoursLeft = ceil(($expiry - $now) / 3600);
                $expiryAlerts[] = ['name' => $witem['itemName'] ?? 'Item', 'hoursLeft' => $hoursLeft, 'source' => in_array($wid, $cartItemIds) ? 'cart' : 'favourites'];
            }
        } catch (Throwable) { continue; }
    }
    $alertCount = count($expiryAlerts);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RePlate – Help Riyadh Go Green</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;1,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>

  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Playfair Display', serif;
      background: #fafdff;
    }

    /* ── NAVBAR ── */
    nav {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 48px;
      height: 72px;
      background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%);
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 2px 16px rgba(26, 58, 107, 0.18);
    }

    .nav-left {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .nav-logo {
      height: 100px;
    }

    .nav-cart {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      border: 2px solid rgba(255, 255, 255, 0.7);
      display: flex;
      justify-content: center;
      align-items: center;
      cursor: pointer;
      transition: background 0.2s;
      text-decoration: none;
    }

    .nav-cart:hover { background: rgba(255, 255, 255, 0.15); }

    .nav-avatar svg { stroke: #fff; }

    .nav-center {
      display: flex;
      align-items: center;
      gap: 40px;
    }

    .nav-center a {
      color: rgba(255, 255, 255, 0.85);
      text-decoration: none;
      font-weight: 500;
      font-size: 15px;
      transition: color 0.2s;
    }

    .nav-center a:hover { color: #fff; }

    .nav-center a.active {
      color: #fff;
      font-weight: 600;
      border-bottom: 2px solid #fff;
      padding-bottom: 2px;
    }

    .nav-right {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .nav-search-wrap { position: relative; }
    .search-dropdown { display: none; position: absolute; top: calc(100% + 10px); right: 0; width: 380px; background: #fff; border-radius: 16px; box-shadow: 0 8px 40px rgba(26,58,107,0.18); border: 1.5px solid #e0eaf5; z-index: 9999; overflow: hidden; }
    .search-dropdown.open { display: block; }
    .search-section-label { font-size: 11px; font-weight: 700; color: #b0c4d8; letter-spacing: 0.08em; text-transform: uppercase; padding: 12px 16px 6px; }
    .search-item-row { display: flex; align-items: center; gap: 12px; padding: 10px 16px; cursor: pointer; transition: background 0.15s; text-decoration: none; }
    .search-item-row:hover { background: #f0f6ff; }
    .search-thumb { width: 38px; height: 38px; border-radius: 10px; background: #e0eaf5; flex-shrink: 0; object-fit: cover; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    .search-thumb img { width: 100%; height: 100%; object-fit: cover; border-radius: 10px; }
    .search-item-name { font-size: 14px; font-weight: 700; color: #1a3a6b; font-family: 'Playfair Display', serif; }
    .search-item-sub { font-size: 12px; color: #7a8fa8; }
    .search-price { margin-left: auto; font-size: 13px; font-weight: 700; color: #e07a1a; white-space: nowrap; }
    .search-divider { height: 1px; background: #f0f5fc; margin: 4px 0; }
    .search-empty { padding: 24px 16px; text-align: center; color: #b0c4d8; font-size: 14px; font-family: 'Playfair Display', serif; }
    .search-loading { padding: 18px 16px; text-align: center; color: #b0c4d8; font-size: 13px; }
    .search-no-match { padding: 8px 16px 12px; font-size: 13px; color: #b0c4d8; font-style: italic; }
    .search-provider-logo { width: 38px; height: 38px; border-radius: 50%; background: #e0eaf5; flex-shrink: 0; overflow: hidden; display: flex; align-items: center; justify-content: center; font-size: 15px; font-weight: 700; color: #2255a4; }
    .search-provider-logo img { width: 100%; height: 100%; object-fit: cover; }

    .nav-search-wrap svg {
      position: absolute;
      left: 12px;
      top: 50%;
      transform: translateY(-50%);
      opacity: 0.6;
      pointer-events: none;
    }

    .nav-search-wrap input {
      background: rgba(255, 255, 255, 0.15);
      border: 1.5px solid rgba(255, 255, 255, 0.4);
      border-radius: 50px;
      padding: 9px 16px 9px 36px;
      color: #fff;
      font-size: 14px;
      outline: none;
      width: 240px;
      font-family: 'Playfair Display', serif;
      transition: width 0.3s, background 0.2s;
    }

    .nav-search-wrap input::placeholder { color: rgba(255, 255, 255, 0.6); }
    .nav-search-wrap input:focus { width: 300px; background: rgba(255, 255, 255, 0.25); }

    .nav-avatar {
      width: 38px;
      height: 38px;
      border-radius: 50%;
      border: 2px solid rgba(255, 255, 255, 0.6);
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }

    .btn-signup {
      background: #fff;
      color: #1a3a6b;
      border: none;
      border-radius: 50px;
      padding: 9px 22px;
      font-weight: 700;
      font-size: 14px;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      box-shadow: 0 2px 8px rgba(0,0,0,0.1);
      transition: transform 0.15s, box-shadow 0.15s;
    }

    .btn-signup:hover { transform: translateY(-1px); box-shadow: 0 4px 16px rgba(0,0,0,0.15); }

    .btn-login {
      background: transparent;
      color: #fff;
      border: 2px solid #fff;
      border-radius: 50px;
      padding: 8px 22px;
      font-weight: 700;
      font-size: 14px;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      transition: background 0.2s;
    }

    .btn-login:hover { background: rgba(255, 255, 255, 0.15); }

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
    .notif-meta { font-size: 12px; color: #7a8fa8; display: flex; align-items: center; gap: 6px; margin-top: 3px; }
    .notif-source-tag { background: #e8f0ff; color: #2255a4; border-radius: 50px; padding: 2px 8px; font-size: 11px; font-weight: 700; }
    .notif-source-tag.cart { background: #e8f7ee; color: #1a6b3a; }
    .notif-hours { color: #e07a1a; font-weight: 700; }

    /* ── HERO ── */
    .hero {
      position: relative;
      min-height: calc(90vh - 50px);
      display: flex;
      align-items: center;
      overflow: hidden;
      background: #fafdff;
    }

    .hero-bg {
      position: absolute;
      inset: 0;
      background-image: url('../../images/landing-banner.png');
      background-size: cover;
      background-position: top right;
      opacity: 1;
    }

    .hero-overlay {
      position: absolute;
      inset: 0;
      background: linear-gradient(90deg, rgba(250,253,255,0.95) 35%, rgba(250,253,255,0.5) 60%, transparent 100%);
      pointer-events: none;
    }

    .hero-content {
      position: relative;
      z-index: 2;
      padding: 50px;
      max-width: 1000px;
    }

    .hero-subtitle {
      font-size: 22px;
      color: #3a5a8a;
      font-weight: 400;
      margin-bottom: 12px;
      letter-spacing: 0.01em;
      line-height: 1.5;
    }

    .hero-title {
      font-family: 'Playfair Display', serif;
      font-size: 64px;
      margin-bottom: 30px;
      line-height: 1.3;
      letter-spacing: -1px;
      background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .btn-shop {
      background: #1a3a6b;
      color: #fff;
      border: none;
      border-radius: 50px;
      padding: 18px 48px;
      font-size: 17px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      letter-spacing: 0.02em;
      box-shadow: 0 8px 24px rgba(26, 58, 107, 0.3);
      transition: transform 0.2s, box-shadow 0.2s, background 0.2s;
    }

    .btn-shop:hover {
      background: #2255a4;
      transform: translateY(-2px);
      box-shadow: 0 12px 32px rgba(26, 58, 107, 0.4);
    }

    /* ── SHARED SECTION ── */
    .section {
      padding: 48px 48px 32px;
      background: #fafdff;
    }

    .section-title {
      font-size: 32px;
      font-family: 'Playfair Display', serif;
      margin-bottom: 28px;
      background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      display: inline-block;
    }

    .section-title .gradient {
      background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    /* ── SCROLL ROW ── */
    .scroll-wrapper {
      position: relative;
    }

    .scroll-row {
      display: flex;
      gap: 20px;
      overflow-x: auto;
      overflow-y: visible;
      scroll-behavior: smooth;
      /* padding gives room for box-shadow and translateY hover */
      padding: 10px 6px 18px;
      margin: -10px -6px -18px;
      cursor: grab;
      -ms-overflow-style: none;
      scrollbar-width: none;
      align-items: stretch;
    }

    .scroll-row::-webkit-scrollbar { display: none; }
    .scroll-row.dragging { cursor: grabbing; user-select: none; }

    .scroll-row > * {
      flex: 0 0 auto;
    }

    .scroll-arrows {
      display: flex;
      justify-content: center;
      gap: 14px;
      margin-top: 24px;
    }

    .arrow-btn {
      width: 34px;
      height: 34px;
      border-radius: 50%;
      border: 2px solid #1a3a6b;
      background: #fff;
      color: #1a3a6b;
      font-size: 13px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.2s, color 0.2s;
    }

    .arrow-btn:hover { background: #1a3a6b; color: #fff; }

    /* ── PRODUCT CARDS ── */
    .product-card {
      min-width: 260px;
      max-width: 260px;
      background: #f2f4f8;
      border-radius: 24px;
      border: 1.5px solid #c8d8ee;
      padding: 18px 18px 20px;
      display: flex;
      flex-direction: column;
      gap: 0;
      box-shadow: 0 2px 14px rgba(26, 58, 107, 0.07);
      flex-shrink: 0;
      transition: box-shadow 0.2s, transform 0.2s;
    }

    .product-card:hover {
      box-shadow: 0 8px 28px rgba(26, 58, 107, 0.13);
      transform: translateY(-3px);
    }

    .product-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
    }

    .provider-logo-box {
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .provider-logo-img {
      width: 32px;
      height: 32px;
      background: #c8d8ee;
      border-radius: 50%;
      flex-shrink: 0;
    }

    .provider-logo-name {
      font-size: 15px;
      font-weight: 700;
      color: #1a3a6b;
      font-family: 'Playfair Display', serif;
    }

    .heart-btn {
      background: none;
      border: none;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 0;
      transition: transform 0.2s;
    }

    .heart-btn:hover { transform: scale(1.15); }

    .heart-btn svg {
      width: 28px;
      height: 28px;
      overflow: visible;
    }

    .heart-btn .heart-path {
      fill: none;
      stroke: #8b1a1a;
      stroke-width: 2;
      transition: fill 0.2s, stroke 0.2s;
    }

    .heart-btn.liked .heart-path {
      fill: #c0392b;
      stroke: #c0392b;
    }

    .product-img-box {
      width: 100%;
      height: 180px;
      background: #d8e6f5;
      border-radius: 14px;
      margin-bottom: 16px;
    }

    .product-divider {
      width: 100%;
      height: 1.5px;
      background: #c0d2e8;
      margin-bottom: 14px;
    }

    .product-bottom {
      display: flex;
      flex-direction: column;
      gap: 8px;
    }

    .product-name-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }

    .product-name {
      font-size: 18px;
      font-weight: 700;
      color: #1a3a6b;
      font-family: 'Playfair Display', serif;
    }

    .product-price-row {
      display: flex;
      align-items: center;
      gap: 5px;
    }

    .product-price {
      font-size: 16px;
      font-weight: 700;
      color: #e07a1a;
    }

    .sar-icon {
      width: 22px;
      height: 22px;
      background: #c8d8ee;
      border-radius: 4px;
      flex-shrink: 0;
    }

    .product-desc {
      font-size: 13px;
      color: #4a6a9a;
      line-height: 1.5;
      font-family: 'Playfair Display', serif;
    }

    .btn-view {
      background: #1a3a6b;
      color: #fff;
      border: none;
      border-radius: 50px;
      padding: 12px 0;
      font-size: 15px;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      font-weight: 700;
      width: 80%;
      text-align: center;
      margin: 8px auto 0;
      display: block;
      transition: background 0.2s;
    }

    .btn-view:hover { background: #2255a4; }

    /* ── CATEGORY CARDS ── */
    .category-card {
      min-width: 300px;
      max-width: 300px;
      background: #f2f4f8;
      border-radius: 24px;
      border: 1.5px solid #c8d8ee;
      padding: 20px;
      display: flex;
      align-items: center;
      gap: 16px;
      box-shadow: 0 2px 14px rgba(26, 58, 107, 0.07);
      flex-shrink: 0;
      transition: box-shadow 0.2s, transform 0.2s;
    }

    .category-card:hover {
      box-shadow: 0 8px 28px rgba(26, 58, 107, 0.13);
      transform: translateY(-3px);
    }

    .category-img-box {
      width: 100px;
      height: 100px;
      background: #c8d8ee;
      border-radius: 12px;
      flex-shrink: 0;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .category-img-box img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 12px;
    }

    .category-info { display: flex; flex-direction: column; gap: 8px; }

    .category-name {
      font-size: 17px;
      font-weight: 700;
      color: #1a3a6b;
      font-family: 'Playfair Display', serif;
    }

    .btn-cat-shop {
      background: #1a3a6b;
      color: #fff;
      border: none;
      border-radius: 50px;
      padding: 6px 18px;
      font-size: 12px;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      font-weight: 700;
      width: fit-content;
      transition: background 0.2s;
    }

    .btn-cat-shop:hover { background: #2255a4; }

    /* ── PROVIDER CARDS ── */
    .provider-card {
      min-width: 220px;
      max-width: 220px;
      background: #f2f4f8;
      border-radius: 24px;
      border: 1.5px solid #c8d8ee;
      padding: 28px 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 12px;
      box-shadow: 0 2px 14px rgba(26, 58, 107, 0.07);
      flex-shrink: 0;
      transition: box-shadow 0.2s, transform 0.2s;
    }

    .provider-card:hover {
      box-shadow: 0 8px 28px rgba(26, 58, 107, 0.13);
      transform: translateY(-3px);
    }

    .provider-logo-big {
      width: 90px;
      height: 90px;
      background: #e8f0f8;
      border-radius: 50%;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }
    .provider-logo-big img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 50%;
    }
    .provider-logo-big .logo-placeholder {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 28px;
      font-weight: 700;
      color: #2255a4;
      font-family: 'Playfair Display', serif;
      background: linear-gradient(135deg, #dce8f8 0%, #c8d8ee 100%);
    }

    .provider-type-label {
      font-size: 14px;
      color: #4a6a9a;
      font-family: 'Playfair Display', serif;
      font-weight: 700;
    }

    /* ── WHO WE ARE ── */
    .who-section {
      padding: 80px 48px;
      display: flex;
      align-items: center;
      gap: 56px;
      position: relative;
      overflow: hidden;
      background-image: url('../../images/whoweare.png');
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
    }

    .who-section::before {
      content: '';
      position: absolute;
      inset: 0;
      
      pointer-events: none;
    }

    .who-logo-box {
      position: relative;
      z-index: 1;
      flex-shrink: 0;
    }

    .who-content {
      position: relative;
      z-index: 1;
    }

    .who-content h2 {
      font-size: 34px;
      font-family: 'Playfair Display', serif;
      margin-bottom: 18px;
      background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
      display: inline-block;
    }

    .who-content h2 .gradient {
      background: linear-gradient(90deg, #143496 0%, #66a1d9 100%);
      -webkit-background-clip: text;
      -webkit-text-fill-color: transparent;
      background-clip: text;
    }

    .who-content p {
      font-size: 15px;
      color: #1a3a6b;
      line-height: 1.9;
      font-family: 'Playfair Display', serif;
    }

    /* ── FOOTER ── */
    footer {
      background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%);
      padding: 28px 48px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 14px;
    }

    .footer-top {
      display: flex;
      align-items: center;
      gap: 18px;
    }

    .social-icon {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      border: 1.5px solid rgba(255,255,255,0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 16px;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      font-family: 'Playfair Display', serif;
      transition: background 0.2s;
    }

    .social-icon:hover { background: rgba(255,255,255,0.15); }

    .footer-divider {
      width: 1px;
      height: 22px;
      background: rgba(255,255,255,0.3);
    }

    .footer-brand {
      display: flex;
      align-items: center;
      gap: 8px;
      color: #fff;
      font-size: 16px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
    }

    .footer-email {
      display: flex;
      align-items: center;
      gap: 6px;
      color: rgba(255,255,255,0.9);
      font-size: 14px;
      font-family: 'Playfair Display', serif;
    }

    .footer-bottom {
      display: flex;
      align-items: center;
      gap: 8px;
      color: rgba(255,255,255,0.7);
      font-size: 13px;
      font-family: 'Playfair Display', serif;
    }

    /* ── SCROLL MARGIN for sticky nav ── */
    #categories, #providers {
      scroll-margin-top: 80px;
    }
    .btn-signup,
.btn-login {
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

    /* ── EMPTY STATE ── */
    .empty-state {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 16px;
      padding: 48px 24px;
      color: #8aa3c0;
      font-size: 15px;
      font-family: 'Playfair Display', serif;
      text-align: center;
    }

    /* ── ANIMATIONS ── */
    @keyframes slideIn {
      from { opacity: 0; transform: translateX(-30px); }
      to   { opacity: 1; transform: translateX(0); }
    }

    @keyframes floatUp {
      from { opacity: 0; transform: translateY(30px); }
      to   { opacity: 1; transform: translateY(0); }
    }
  </style>
</head>
<body>

  <!-- NAVBAR -->
  <nav>
    <div class="nav-left">
      <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate Logo" />
      <a href="CART_LINK_HERE" class="nav-cart">
        <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;" />
      </a>
    </div>

    <div class="nav-center">
      <a href="#" class="active">Home Page</a>
      <a href="#categories">Categories</a>
      <a href="#providers">Providers</a>
    </div>

    <div class="nav-right">
<?php if (!$isLoggedIn): ?>
<button class="btn-signup" onclick="window.location.href='signup-customer.php'">Sign up</button>
<button class="btn-login" onclick="window.location.href='login.php'">Log in</button>
<?php endif; ?>
      <div class="nav-search-wrap" id="searchWrap">
        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24">
          <circle cx="11" cy="11" r="8"/>
          <path d="M21 21l-4.35-4.35"/>
        </svg>
        <input type="text" id="searchInput" placeholder="Search products or providers..." autocomplete="off"/>
        <div class="search-dropdown" id="searchDropdown"></div>
      </div>
      <?php if ($isLoggedIn): ?>
      <div class="nav-bell-wrap">
        <button class="nav-bell" onclick="toggleNotifDropdown()">
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
            <div>
              <p style="font-size:14px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;margin-bottom:3px;"><?= htmlspecialchars($alert['name']) ?></p>
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
      <a href="../customer/customer-profile.php" class="nav-avatar">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
          <circle cx="12" cy="7" r="4"/>
        </svg>
      </a>
      <?php else: ?>
      <button class="nav-avatar" onclick="document.getElementById('authModal').style.display='flex'" style="border:none;cursor:pointer;">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24">
          <path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/>
          <circle cx="12" cy="7" r="4"/>
        </svg>
      </button>
      <?php endif; ?>

      <!-- Auth required modal -->
      <div id="authModal" style="display:none;position:fixed;inset:0;background:rgba(12,22,45,0.5);z-index:9999;justify-content:center;align-items:center;" onclick="if(event.target===this)this.style.display='none'">
        <div style="background:#fff;border-radius:24px;padding:44px 40px;max-width:400px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,0.2);animation:floatUp 0.3s ease;">
          <div style="width:64px;height:64px;background:#e8f0ff;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;">
            <svg width="28" height="28" fill="none" stroke="#2255a4" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
          </div>
          <h3 style="font-size:22px;font-weight:700;color:#1a3a6b;font-family:'Playfair Display',serif;margin-bottom:10px;">Sign in to continue</h3>
          <p style="font-size:14px;color:#7a8fa8;margin-bottom:28px;line-height:1.6;">Please log in or create an account to access your profile.</p>
          <div style="display:flex;gap:12px;justify-content:center;">
            <a href="login.php" style="flex:1;padding:13px 0;border-radius:50px;background:#1a3a6b;color:#fff;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;text-decoration:none;display:flex;align-items:center;justify-content:center;transition:background 0.2s;" onmouseover="this.style.background='#2255a4'" onmouseout="this.style.background='#1a3a6b'">Log in</a>
            <a href="signup-customer.php" style="flex:1;padding:13px 0;border-radius:50px;background:transparent;color:#1a3a6b;font-size:15px;font-weight:700;font-family:'Playfair Display',serif;text-decoration:none;border:2px solid #1a3a6b;display:flex;align-items:center;justify-content:center;transition:background 0.2s;" onmouseover="this.style.background='#f0f5ff'" onmouseout="this.style.background='transparent'">Sign up</a>
          </div>
          <button onclick="document.getElementById('authModal').style.display='none'" style="margin-top:18px;background:none;border:none;color:#b0c4d8;font-size:13px;cursor:pointer;font-family:'Playfair Display',serif;">Maybe later</button>
        </div>
      </div>
    </div>
  </nav>

  <!-- HERO -->
  <section class="hero">
    <div class="hero-bg"></div>
    <div class="hero-overlay"></div>
    <div class="hero-content">
      <p class="hero-subtitle">Join the movement to reduce food waste and</p>
      <h1 class="hero-title">help Riyadh go green</h1>
      <button class="btn-shop">Shop now</button>
    </div>
  </section>

  <!-- BEST PRICES -->
  <section class="section">
    <h2 class="section-title"><span class="gradient">Best</span> Prices</h2>
    <?php if (!empty($items)): ?>
    <div class="scroll-wrapper">
      <div class="scroll-row" id="prices-row">
        <?php foreach ($items as $item):
          $itemName  = htmlspecialchars($item['itemName'] ?? 'Item');
          $itemPrice = number_format((float)($item['price'] ?? 0), 2);
          $itemDesc  = htmlspecialchars($item['description'] ?? '');
          $itemId    = (string)($item['_id'] ?? '');
          // Fetch provider name
          try {
            $prov = (new Provider())->findById((string)($item['providerId'] ?? ''));
            $providerName = htmlspecialchars($prov['businessName'] ?? 'Provider');
          } catch(Throwable) { $providerName = 'Provider'; }
        ?>
        <div class="product-card">
          <div class="product-card-top">
            <div class="provider-logo-box">
           <div class="provider-logo-img" style="overflow:hidden;border-radius:50%;display:flex;align-items:center;justify-content:center;">
  <?php if (!empty($prov['businessLogo'])): ?>
    <img src="<?= htmlspecialchars($prov['businessLogo']) ?>" style="width:100%;height:100%;object-fit:cover;" />
  <?php else: ?>
    <span style="font-size:13px;font-weight:700;color:#2255a4;"><?= mb_strtoupper(mb_substr($providerName, 0, 1)) ?></span>
  <?php endif; ?>
</div>
              <span class="provider-logo-name"><?= $providerName ?></span>
            </div>
            <button class="heart-btn"><svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path class="heart-path" d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg></button>
          </div>
         <div class="product-img-box" style="overflow:hidden;">
  <?php if (!empty($item['photoUrl'])): ?>
    <img src="<?= htmlspecialchars($item['photoUrl']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:14px;" />
  <?php else: ?>
    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#8aa3c0;font-size:13px;">No image</div>
  <?php endif; ?>
</div>
          <div class="product-divider"></div>
          <div class="product-bottom">
            <div class="product-name-row">
              <span class="product-name"><?= $itemName ?></span>
              <div class="product-price-row">
                <span class="product-price"><?= $itemPrice ?></span>
                <div class="sar-icon"></div>
              </div>
            </div>
            <p class="product-desc"><?= $itemDesc ?></p>
            <button class="btn-view" onclick="window.location.href='../customer/item-details.php?itemId=<?= $itemId ?>'">View item</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="scroll-arrows">
      <button class="arrow-btn" onclick="scrollRow('prices-row',-1)">&#9664;</button>
      <button class="arrow-btn" onclick="scrollRow('prices-row', 1)">&#9654;</button>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <svg width="48" height="48" fill="none" stroke="#b0c4d8" stroke-width="1.5" viewBox="0 0 24 24">
        <path d="M3 6h18M3 12h18M3 18h18"/>
      </svg>
      <p>No items available yet — check back soon once providers start listing!</p>
    </div>
    <?php endif; ?>
  </section>

  <!-- CATEGORIES -->
  <section class="section" id="categories">
    <h2 class="section-title"><span class="gradient">Categories</span></h2>
    <div class="scroll-wrapper">
      <div class="scroll-row" id="categories-row">
        <?php
        $catImageMap = [
            'bakery'    => '../../images/bakary.png',
            'groceries' => '../../images/grocery.png',
            'grocery'   => '../../images/grocery.png',
            'meals'     => '../../images/meals.png',
            'meal'      => '../../images/meals.png',
            'dairy'     => '../../images/diary.png',
            'sweets'    => '../../images/sweets.png',
            'sweet'     => '../../images/sweets.png',
        ];
        foreach ($categories as $cat):
            $catId   = (string)$cat['_id'];
            $catName = htmlspecialchars($cat['name'] ?? '');
            $catKey  = strtolower(trim($cat['name'] ?? ''));
            $catImg  = $catImageMap[$catKey] ?? '../../images/bakary.png';
        ?>
        <a class="category-card" href="../customer/category.php?categoryId=<?= urlencode($catId) ?>" style="text-decoration:none;">
          <div class="category-img-box">
            <img src="<?= $catImg ?>" alt="<?= $catName ?>"/>
          </div>
          <div class="category-info">
            <span class="category-name"><?= $catName ?></span>
            <button class="btn-cat-shop">Shop now</button>
          </div>
        </a>
        <?php endforeach; ?>

      </div>
    </div>
    <div class="scroll-arrows">
      <button class="arrow-btn" onclick="scrollRow('categories-row',-1)">&#9664;</button>
      <button class="arrow-btn" onclick="scrollRow('categories-row', 1)">&#9654;</button>
    </div>
  </section>

  <!-- PROVIDERS -->
  <section class="section" id="providers">
    <h2 class="section-title"><span class="gradient">Providers</span></h2>
    <?php if (!empty($providers)): ?>
    <div class="scroll-wrapper">
      <div class="scroll-row" id="providers-row">
        <?php foreach ($providers as $provider):
          $bizName  = htmlspecialchars($provider['businessName'] ?? 'Provider');
          $category = htmlspecialchars($provider['category'] ?? '');
          $provId   = (string)($provider['_id'] ?? '');
        ?>
        <a class="provider-card" href="../customer/providers-page.php?providerId=<?= urlencode($provId) ?>" style="text-decoration:none;">
          <div class="provider-logo-big">
            <?php if (!empty($provider['businessLogo'])): ?>
              <img src="<?= htmlspecialchars($provider['businessLogo']) ?>" alt="<?= $bizName ?>"/>
            <?php else: ?>
              <div class="logo-placeholder"><?= mb_strtoupper(mb_substr($bizName, 0, 1)) ?></div>
            <?php endif; ?>
          </div>
          <span class="provider-logo-name" style="font-size:15px;font-weight:700;color:#1a3a6b;text-align:center;"><?= $bizName ?></span>
          <span class="provider-type-label"><?= $category ?></span>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="scroll-arrows">
      <button class="arrow-btn" onclick="scrollRow('providers-row',-1)">&#9664;</button>
      <button class="arrow-btn" onclick="scrollRow('providers-row', 1)">&#9654;</button>
    </div>
    <?php else: ?>
    <div class="empty-state">
      <svg width="48" height="48" fill="none" stroke="#b0c4d8" stroke-width="1.5" viewBox="0 0 24 24">
        <rect x="3" y="3" width="18" height="18" rx="3"/>
        <path d="M3 9h18"/>
      </svg>
      <p>No providers have joined yet — they'll appear here once they sign up!</p>
    </div>
    <?php endif; ?>
  </section>

  <!-- WHO WE ARE -->
  <section class="who-section">
    <div class="who-logo-box">
      <img src="../../images/Replate-logo.png" alt="Replate" style="height:200px;object-fit:contain;opacity:1;"/>
    </div>
    <div class="who-content">
      <h2><span class="gradient">Who</span> we are?</h2>
      <p>"RePlate is a sustainability platform designed to reduce food waste in Riyadh by connecting individuals and businesses who have surplus or near-expiry food with people who need it. Through simple food listing, pickup scheduling, and timely expiry alerts, RePlate turns food that would otherwise go to waste into value for the community."</p>
    </div>
  </section>

  <!-- FOOTER -->
  <footer>
    <div class="footer-top">
      <div style="display:flex;align-items:center;gap:10px;">
        <a class="social-icon" href="#">in</a>
        <a class="social-icon" href="#">&#120143;</a>
        <a class="social-icon" href="#">&#9834;</a>
      </div>
      <div class="footer-divider"></div>
      <div class="footer-brand">
    
      </div>
      <div class="footer-divider"></div>
      <div class="footer-email">
        <svg width="16" height="16" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2" viewBox="0 0 24 24">
          <rect x="2" y="4" width="20" height="16" rx="2"/>
          <path d="M2 7l10 7 10-7"/>
        </svg>
        <a href="/cdn-cgi/l/email-protection" class="__cf_email__" data-cfemail="8ad8effae6ebfeefcaede7ebe3e6a4e9e5e7">Replate@gmail.com</a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© 2026</span>
      <img src="../../images/Replate-white.png" alt="Replate" style="height:50px;object-fit:contain;opacity:1;" />
      <span>All rights reserved.</span>
    </div>
  </footer>

  <script data-cfasync="false" src="/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script><script>
    // ── Heart toggle ──
    document.querySelectorAll('.heart-btn').forEach(btn => {
      btn.addEventListener('click', () => btn.classList.toggle('liked'));
    });

    // ── Active nav link on click ──
    document.querySelectorAll('.nav-center a').forEach(link => {
      link.addEventListener('click', () => {
        document.querySelectorAll('.nav-center a').forEach(l => l.classList.remove('active'));
        link.classList.add('active');
      });
    });

    // ── Active nav on scroll ──
    const sections = [
      { id: 'categories', link: document.querySelector('.nav-center a[href="#categories"]') },
      { id: 'providers',  link: document.querySelector('.nav-center a[href="#providers"]') },
    ];
    const homeLink = document.querySelector('.nav-center a[href="#"]');
    window.addEventListener('scroll', () => {
      let inSection = false;
      sections.forEach(({ id, link }) => {
        const el = document.getElementById(id);
        if (!el) return;
        const rect = el.getBoundingClientRect();
        if (rect.top <= 100 && rect.bottom > 100) {
          document.querySelectorAll('.nav-center a').forEach(l => l.classList.remove('active'));
          link.classList.add('active');
          inSection = true;
        }
      });
      if (!inSection && window.scrollY < 200) {
        document.querySelectorAll('.nav-center a').forEach(l => l.classList.remove('active'));
        homeLink.classList.add('active');
      }
    });

    // ── Scroll arrows ──
    function scrollRow(id, dir) {
      document.getElementById(id).scrollBy({ left: dir * 280, behavior: 'smooth' });
    }

    // ── Drag scroll ──
    document.querySelectorAll('.scroll-row').forEach(row => {
      let isDown = false, startX, scrollLeft;
      row.addEventListener('mousedown', e => {
        isDown = true; row.classList.add('dragging');
        startX = e.pageX - row.offsetLeft; scrollLeft = row.scrollLeft;
      });
      row.addEventListener('mouseleave', () => { isDown = false; row.classList.remove('dragging'); });
      row.addEventListener('mouseup',    () => { isDown = false; row.classList.remove('dragging'); });
      row.addEventListener('mousemove',  e => {
        if (!isDown) return; e.preventDefault();
        row.scrollLeft = scrollLeft - (e.pageX - row.offsetLeft - startX) * 1.5;
      });
    });

    // ── Bell notification dropdown ──
    function toggleNotifDropdown() {
      document.getElementById('notifDropdown').classList.toggle('open');
    }

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
          html += `<a class="search-item-row" href="../customer/providers-page.php?id=${p.id}">
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
          html += `<a class="search-item-row" href="../customer/item-details.php?id=${item.id}">
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

  </script>

</body>
</html>
