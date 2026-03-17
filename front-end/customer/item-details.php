<?php
// ================================================================
// item-details.php — Single Item Detail Page
// ================================================================
// URL PARAMS:  ?itemId=xxx
// VARIABLES:
//   $item       → full item object
//   $provider   → provider who listed this item
//   $location   → pickup location for this item
//   $category   → category object
//   $isSaved    → bool — has the logged-in customer favourited this?
//   $inCart     → bool — is this item already in cart?
// POST ACTIONS:
//   action=add_to_cart  → adds item to cart, redirects back
//   action=toggle_fav   → saves/unsaves item, redirects back
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/PickupLocation.php';
require_once '../../back-end/models/Category.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Favourite.php';

$itemId     = $_GET['itemId'] ?? '';
$item       = null;
$provider   = null;
$location   = null;
$category   = null;
$isSaved    = false;
$inCart     = false;
$customerId = $_SESSION['customerId'] ?? null;

if ($itemId) {
    $itemModel     = new Item();
    $providerModel = new Provider();
    $locationModel = new PickupLocation();
    $categoryModel = new Category();

    $item = $itemModel->findById($itemId);

    if ($item) {
        $provider = $providerModel->findById((string) $item['providerId']);
        $location = $locationModel->findById((string) $item['pickupLocationId']);
        $category = $categoryModel->findById((string) $item['categoryId']);
        if ($provider) unset($provider['passwordHash']);

        if ($customerId) {
            $isSaved = (new Favourite())->isSaved($customerId, $itemId);
            $cart    = (new Cart())->getOrCreate($customerId);
            foreach ($cart['cartItems'] as $ci) {
                if ((string) $ci['itemId'] === $itemId) { $inCart = true; break; }
            }
        }
    }
}

// ── Handle POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $customerId && $item) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_to_cart') {
        (new Cart())->addItem($customerId, [
            'itemId'     => $itemId,
            'providerId' => (string) $item['providerId'],
            'quantity'   => (int) ($_POST['quantity'] ?? 1),
            'itemName'   => $item['itemName'],
            'price'      => $item['price'],
        ]);
        header("Location: item-details.php?itemId=$itemId&added=1");
        exit;
    }

    if ($action === 'toggle_fav') {
        $favModel = new Favourite();
        $isSaved  ? $favModel->remove($customerId, $itemId) : $favModel->add($customerId, $itemId);
        header("Location: item-details.php?itemId=$itemId");
        exit;
    }
}

$justAdded = isset($_GET['added']);

// ── EXAMPLE: Add to cart form ──
// <form method="POST">
//   <input type="hidden" name="action" value="add_to_cart" />
//   <input type="number" name="quantity" value="1" min="1" max="[item.quantity]" />
//   <button type="submit">[inCart ? Update Cart : Add to Cart]</button>
// </form>
//
// ── EXAMPLE: Favourite toggle ──
// <form method="POST">
//   <input type="hidden" name="action" value="toggle_fav" />
//   <button type="submit">[isSaved ? Saved : Save]</button>
// </form>

// ── Added: helper functions for display ──
$isLoggedIn = !empty($customerId);

function fmtExpiry($value): string {
    if (!$value) return '';
    try {
        $dt   = $value instanceof MongoDB\BSON\UTCDateTime ? $value->toDateTime() : new DateTime((string)$value);
        $now  = new DateTime();
        $diff = $dt->diff($now);
        $days = (int)abs($dt->getTimestamp() - time()) / 86400;
        $daysInt = (int)$days;
        $label = $dt < $now ? 'expired' : ($daysInt . ' day' . ($daysInt !== 1 ? 's' : '') . ' left');
        return $dt->format('d M Y') . ' (' . $label . ')';
    } catch (Throwable) { return ''; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RePlate – <?= htmlspecialchars($item['itemName'] ?? 'Item') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>
  <style>
    *{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'DM Sans',sans-serif;background:#f0f5fc;color:#1a2a45;min-height:100vh;padding-bottom:90px}
    a{text-decoration:none;color:inherit}

    /* ── NAVBAR ── */
    nav.navbar{display:flex;align-items:center;justify-content:space-between;padding:0 40px;height:72px;background:linear-gradient(90deg,#1a3a6b 0%,#2255a4 60%,#3a7bd5 100%);position:sticky;top:0;z-index:100;box-shadow:0 2px 16px rgba(26,58,107,0.18)}
    .nav-logo{height:100px}
    .nav-left{display:flex;align-items:center;gap:16px}
    .nav-cart{width:40px;height:40px;border-radius:50%;border:2px solid rgba(255,255,255,0.7);display:flex;align-items:center;justify-content:center;text-decoration:none;transition:background 0.2s}
    .nav-cart:hover{background:rgba(255,255,255,0.15)}
    .nav-center{display:flex;align-items:center;gap:40px}
    .nav-center a{color:rgba(255,255,255,0.85);text-decoration:none;font-weight:500;font-size:15px;font-family:'Playfair Display',serif;transition:color 0.2s}
    .nav-center a:hover{color:#fff}
    .nav-right{display:flex;align-items:center;gap:12px}
    .btn-signup{background:#fff;color:#1a3a6b;border:none;border-radius:50px;padding:8px 22px;font-weight:700;font-size:14px;font-family:'Playfair Display',serif;cursor:pointer}
    .btn-login{background:transparent;color:#fff;border:2px solid #fff;border-radius:50px;padding:6px 22px;font-weight:700;font-size:14px;font-family:'Playfair Display',serif;cursor:pointer}
    .nav-avatar{width:38px;height:38px;border-radius:50%;border:2px solid rgba(255,255,255,0.6);display:flex;align-items:center;justify-content:center;text-decoration:none;background:rgba(255,255,255,0.15)}
    .nav-avatar:hover{background:rgba(255,255,255,0.25)}
    .nav-search-wrap{position:relative}
    .nav-search-wrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);opacity:0.6;pointer-events:none}
    .nav-search-wrap input{background:rgba(255,255,255,0.15);border:1.5px solid rgba(255,255,255,0.4);border-radius:50px;padding:9px 16px 9px 36px;color:#fff;font-size:14px;outline:none;width:220px;font-family:'Playfair Display',serif}
    .nav-search-wrap input::placeholder{color:rgba(255,255,255,0.6)}

    /* ── HERO IMAGE AREA ── */
    .item-hero{background:linear-gradient(180deg,#d8e8f8 0%,#f0f5fc 100%);position:relative;padding:32px 40px 0;min-height:300px;display:flex;align-items:flex-end;justify-content:center}
    .hero-back{position:absolute;top:20px;left:24px;width:40px;height:40px;border-radius:50%;border:2px solid #dce7f5;background:#fff;display:grid;place-items:center;color:#1a3a6b;font-size:22px;font-weight:700;cursor:pointer;box-shadow:0 2px 8px rgba(26,58,107,0.1);text-decoration:none}
    .hero-back:hover{background:#e8f0ff}
    .hero-fav{position:absolute;top:20px;right:24px;width:44px;height:44px;border-radius:50%;border:none;background:#fff;display:grid;place-items:center;font-size:26px;cursor:pointer;box-shadow:0 2px 12px rgba(26,58,107,0.12);transition:transform 0.2s}
    .hero-fav:hover{transform:scale(1.15)}
    .prov-hero-logo{position:absolute;top:20px;left:76px;height:44px;max-width:120px;object-fit:contain}
    .prov-hero-name{position:absolute;top:28px;left:76px;font-size:14px;font-weight:700;color:#7a8fa8;font-style:italic}
    .hero-food-img{max-height:270px;max-width:480px;width:100%;object-fit:contain;position:relative;z-index:1;filter:drop-shadow(0 8px 20px rgba(26,58,107,0.14))}
    .hero-placeholder{width:100%;max-width:480px;height:250px;background:linear-gradient(135deg,#c8dbf5,#dce7f5);border-radius:20px;display:grid;place-items:center;color:#7a8fa8;font-size:16px}

    /* ── CONTENT ── */
    .container{max-width:860px;margin:0 auto;padding:32px 24px 40px}

    .title-row{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;margin-bottom:10px}
    .item-title{font-family:'Playfair Display',serif;font-size:42px;color:#1a3a6b;font-weight:700;line-height:1.2}
    .item-price-big{font-weight:700;font-size:28px;color:#e07a1a;white-space:nowrap;padding-top:8px}
    .price-free-big{color:#1a6b3a}
    .item-ingredients{font-size:16px;color:#7a8fa8;margin-bottom:12px;line-height:1.6}
    .expiry-row{display:flex;align-items:center;gap:8px;font-size:15px;color:#1a2a45;margin-bottom:22px}
    .expiry-date{color:#e07a1a;font-weight:700}

    .divider{height:1.5px;background:#dce7f5;margin:22px 0}

    .section-label{font-family:'Playfair Display',serif;font-size:22px;color:#1a3a6b;font-weight:700;margin-bottom:14px}

    /* Quantity */
    .qty-row{display:flex;align-items:center;background:#fff;border:1.5px solid #dce7f5;border-radius:50px;width:fit-content;overflow:hidden;box-shadow:0 2px 8px rgba(26,58,107,0.07);margin-bottom:28px}
    .qty-btn{width:52px;height:52px;border:none;background:transparent;font-size:24px;font-weight:700;color:#1a3a6b;cursor:pointer;transition:background 0.15s;display:grid;place-items:center}
    .qty-btn:hover{background:#e8f0ff}
    .qty-val{min-width:60px;text-align:center;font-size:22px;font-weight:700;color:#1a2a45;border-left:1.5px solid #dce7f5;border-right:1.5px solid #dce7f5;height:52px;display:grid;place-items:center}

    /* Pickup times */
    .pickup-times{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:8px}
    .time-chip{padding:11px 20px;border:1.5px solid #dce7f5;border-radius:14px;background:#fff;font-size:15px;font-weight:500;color:#1a2a45;cursor:pointer;transition:all 0.2s;user-select:none}
    .time-chip.selected,.time-chip:hover{border-color:#1a3a6b;background:#e8f0ff;color:#1a3a6b;font-weight:700}

    /* Map */
    .map-box{width:100%;border-radius:20px;overflow:hidden;border:1.5px solid #dce7f5;background:linear-gradient(135deg,#c8dbf5,#dce7f5);height:210px;display:flex;align-items:center;justify-content:center;margin-bottom:8px;box-shadow:0 4px 14px rgba(26,58,107,0.08)}
    .map-pin{font-size:40px}
    .location-text{font-size:13px;color:#7a8fa8;margin-top:6px}

    /* Flash */
    .flash{background:#e8f7ec;border:1.5px solid #b6dfbf;color:#1a6b3a;border-radius:14px;padding:13px 18px;margin-bottom:18px;font-weight:600}

    /* Unavailable */
    .unavail{background:#fff1f0;border:1.5px solid #efb1ab;color:#b23a2c;border-radius:14px;padding:13px 18px;margin-bottom:20px;font-weight:600}

    /* Not found */
    .not-found{text-align:center;padding:80px 24px}
    .not-found h2{font-family:'Playfair Display',serif;font-size:30px;color:#1a3a6b;margin-bottom:10px}
    .back-link{display:inline-block;margin-top:18px;background:#e07a1a;color:#fff;border-radius:50px;padding:12px 28px;font-weight:700}

    /* Login prompt */
    .login-bar{position:fixed;bottom:0;left:0;right:0;background:#1a3a6b;text-align:center;padding:18px;color:#fff;font-size:16px;font-weight:600;box-shadow:0 -4px 20px rgba(26,58,107,0.25);z-index:200}
    .login-bar a{color:#f5c87a;text-decoration:underline}

    /* Sticky cart bar */
    .cart-bar{position:fixed;bottom:0;left:0;right:0;background:#e07a1a;z-index:200;box-shadow:0 -4px 20px rgba(224,122,26,0.3)}
    .cart-bar-inner{max-width:860px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:0 24px;height:68px}
    .cart-bar-btn{flex:1;border:none;background:transparent;color:#fff;font-family:'Playfair Display',serif;font-size:22px;font-weight:700;cursor:pointer;text-align:left}
    .cart-bar-price{font-family:'Playfair Display',serif;font-size:22px;font-weight:700;color:#fff}

    /* ── FOOTER ── */
    .footer{background:linear-gradient(90deg,#1a3a6b,#2255a4);color:#fff;padding:26px 40px;text-align:center}
    .footer-links{display:flex;justify-content:center;align-items:center;gap:18px;flex-wrap:wrap;font-size:14px;opacity:0.9;margin-bottom:8px}
    .footer-logo{font-family:'Playfair Display',serif;font-size:17px;font-weight:700}
    .footer-copy{font-size:12px;opacity:0.6}

    @media(max-width:700px){nav.navbar{padding:0 16px}.item-hero{padding:20px 16px 0}.item-title{font-size:28px}.nav-center{display:none}}
  </style>
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar">
  <div class="nav-left">
    <img class="nav-logo" src="../../images/Replate-white.png" alt="RePlate"/>
    <a href="../customer/cart.php" class="nav-cart">
      <img src="../../images/Shopping cart.png" alt="Cart" style="width:40px;height:40px;object-fit:contain;"/>
    </a>
  </div>
  <div class="nav-center">
    <a href="../shared/landing.php">Home Page</a>
    <a href="category.php">Categories</a>
    <a href="providers-list.php">Providers</a>
  </div>
  <div class="nav-right">
    <?php if ($isLoggedIn): ?>
      <a href="customer-profile.php" class="nav-avatar">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
    <?php else: ?>
      <a href="../shared/signup-customer.php"><button class="btn-signup">Sign up</button></a>
      <a href="../shared/login.php"><button class="btn-login">Log in</button></a>
      <div class="nav-search-wrap">
        <svg width="16" height="16" fill="none" stroke="#fff" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
        <input type="text" placeholder="Search.....">
      </div>
      <a href="customer-profile.php" class="nav-avatar">
        <svg width="20" height="20" fill="none" stroke="#fff" stroke-width="1.8" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
      </a>
    <?php endif; ?>
  </div>
</nav>

<?php if (!$item): ?>
  <div class="not-found">
    <h2>Item not found</h2>
    <p style="color:#7a8fa8">This item may no longer be available.</p>
    <a class="back-link" href="category.php">Back to categories</a>
  </div>
<?php else:
  $isFree      = ($item['listingType'] ?? '') === 'donate';
  $price       = (float)($item['price'] ?? 0);
  $maxQty      = max(1, (int)($item['quantity'] ?? 1));
  $pickupTimes = $item['pickupTimes'] ?? [];
  $provName    = $provider['businessName'] ?? '';
  $provLogo    = $provider['businessLogo'] ?? '';
  $expiryStr   = fmtExpiry($item['expiryDate'] ?? null);
  $lat         = $location['coordinates']['lat'] ?? null;
  $lng         = $location['coordinates']['lng'] ?? null;
  $mapLink     = ($lat && $lng) ? "https://www.google.com/maps?q={$lat},{$lng}" : null;
?>

<!-- ── HERO IMAGE AREA ── -->
<div class="item-hero">
  <a class="hero-back" href="javascript:history.back()">‹</a>

  <?php if ($provLogo): ?>
    <img class="prov-hero-logo" src="<?= htmlspecialchars($provLogo) ?>" alt="<?= htmlspecialchars($provName) ?>">
  <?php else: ?>
    <span class="prov-hero-name"><?= htmlspecialchars($provName) ?></span>
  <?php endif; ?>

  <?php if ($isLoggedIn): ?>
    <form method="post" style="display:inline">
      <input type="hidden" name="action" value="toggle_fav">
      <button class="hero-fav" type="submit"><?= $isSaved ? '❤️' : '🤍' ?></button>
    </form>
  <?php else: ?>
    <a class="hero-fav" href="../shared/login.php" style="text-decoration:none;display:grid;place-items:center">🤍</a>
  <?php endif; ?>

  <?php if (!empty($item['photoUrl'])): ?>
    <img class="hero-food-img" src="<?= htmlspecialchars($item['photoUrl']) ?>" alt="<?= htmlspecialchars($item['itemName'] ?? '') ?>">
  <?php else: ?>
    <div class="hero-placeholder">No image available</div>
  <?php endif; ?>
</div>

<!-- ── CONTENT ── -->
<main class="container">

  <?php if ($justAdded): ?>
    <div class="flash">✓ Added to cart! <a href="cart.php" style="color:#1a3a6b;font-weight:700;">View cart →</a></div>
  <?php endif; ?>

  <div class="title-row">
    <h1 class="item-title"><?= htmlspecialchars($item['itemName'] ?? 'Item') ?></h1>
    <?php if ($isFree): ?>
      <span class="item-price-big price-free-big">Free</span>
    <?php else: ?>
      <span class="item-price-big"><?= number_format($price, 2) ?> ﷼</span>
    <?php endif; ?>
  </div>

  <?php if (!empty($item['description'])): ?>
    <p class="item-ingredients"><?= htmlspecialchars($item['description']) ?></p>
  <?php endif; ?>

  <?php if ($expiryStr): ?>
    <div class="expiry-row">
      <span>📅</span>
      Expiry date: <span class="expiry-date"><?= htmlspecialchars($expiryStr) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($isLoggedIn && ($item['isAvailable'] ?? false)): ?>
    <form method="post" id="cartForm">
      <input type="hidden" name="action" value="add_to_cart">
      <input type="hidden" name="quantity" id="qtyInput" value="1">
      <?php if (!empty($pickupTimes)): ?>
        <input type="hidden" name="pickupTime" id="selectedTime" value="<?= htmlspecialchars($pickupTimes[0]) ?>">
      <?php endif; ?>

      <div class="divider"></div>
      <p class="section-label">Quantity</p>
      <div class="qty-row">
        <button type="button" class="qty-btn" onclick="changeQty(-1)">−</button>
        <div class="qty-val" id="qtyDisplay">1</div>
        <button type="button" class="qty-btn" onclick="changeQty(1)">+</button>
      </div>

      <?php if (!empty($pickupTimes)): ?>
        <div class="divider"></div>
        <p class="section-label">Pickup time</p>
        <div class="pickup-times" style="margin-bottom:28px">
          <?php foreach ($pickupTimes as $i => $t): ?>
            <div class="time-chip <?= $i === 0 ? 'selected' : '' ?>"
                 onclick="selectTime(this,'<?= htmlspecialchars($t) ?>')">
              <?= htmlspecialchars($t) ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($location): ?>
        <div class="divider"></div>
        <p class="section-label">Pickup location</p>
        <?php if ($mapLink): ?><a href="<?= htmlspecialchars($mapLink) ?>" target="_blank" rel="noopener"><?php endif; ?>
        <div class="map-box"><div class="map-pin">📍</div></div>
        <?php if ($mapLink): ?></a><?php endif; ?>
        <p class="location-text"><?= htmlspecialchars(trim(($location['street'] ?? '') . ', ' . ($location['city'] ?? ''))) ?></p>
      <?php endif; ?>
    </form>

  <?php elseif ($isLoggedIn && !($item['isAvailable'] ?? false)): ?>
    <div class="unavail">This item is no longer available.</div>
    <?php if (!empty($pickupTimes)): ?>
      <div class="divider"></div>
      <p class="section-label">Pickup time</p>
      <div class="pickup-times" style="margin-bottom:28px;opacity:0.5;pointer-events:none">
        <?php foreach ($pickupTimes as $t): ?><div class="time-chip"><?= htmlspecialchars($t) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <?php if (!empty($pickupTimes)): ?>
      <div class="divider"></div>
      <p class="section-label">Pickup time</p>
      <div class="pickup-times" style="margin-bottom:28px">
        <?php foreach ($pickupTimes as $t): ?><div class="time-chip"><?= htmlspecialchars($t) ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>
    <?php if ($location): ?>
      <div class="divider"></div>
      <p class="section-label">Pickup location</p>
      <div class="map-box"><div class="map-pin">📍</div></div>
      <p class="location-text"><?= htmlspecialchars(trim(($location['street'] ?? '') . ', ' . ($location['city'] ?? ''))) ?></p>
    <?php endif; ?>
  <?php endif; ?>

</main>

<!-- ── FOOTER ── -->
<footer class="footer">
  <div class="footer-links">
    <span>in</span><span>𝕏</span><span>tiktok</span>
    <span class="footer-logo">Replate</span>
    <span>✉</span><span>Replate@gmail.com</span>
  </div>
  <div class="footer-copy">© 2026 Replate &nbsp; All rights reserved.</div>
</footer>

<!-- ── STICKY BOTTOM BAR ── -->
<?php if ($isLoggedIn && ($item['isAvailable'] ?? false)): ?>
  <div class="cart-bar">
    <div class="cart-bar-inner">
      <button class="cart-bar-btn" type="submit" form="cartForm">
        <?= $inCart ? 'Update Cart' : 'Add to cart' ?>
      </button>
      <?php if (!$isFree): ?>
        <div class="cart-bar-price" id="totalDisplay"><?= number_format($price, 2) ?> ﷼</div>
      <?php else: ?>
        <div class="cart-bar-price">Free</div>
      <?php endif; ?>
    </div>
  </div>
<?php elseif (!$isLoggedIn): ?>
  <div class="login-bar">
    <a href="../shared/login.php">Log in</a> or <a href="../shared/signup-customer.php">sign up</a> to add this item to your cart.
  </div>
<?php endif; ?>

<script>
  const maxQty = <?= (int)$maxQty ?>;
  const price  = <?= $isFree ? 0 : $price ?>;
  let qty = 1;

  function changeQty(delta) {
    qty = Math.min(maxQty, Math.max(1, qty + delta));
    document.getElementById('qtyDisplay').textContent = qty;
    document.getElementById('qtyInput').value = qty;
    const td = document.getElementById('totalDisplay');
    if (td && price > 0) td.textContent = (price * qty).toFixed(2) + ' ﷼';
  }

  function selectTime(el, time) {
    document.querySelectorAll('.time-chip').forEach(c => c.classList.remove('selected'));
    el.classList.add('selected');
    const st = document.getElementById('selectedTime');
    if (st) st.value = time;
  }
</script>

<?php endif; // $item exists ?>
</body>
</html>
