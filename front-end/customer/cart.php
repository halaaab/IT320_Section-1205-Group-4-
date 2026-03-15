<?php
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/Notification.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function money($amount): string {
    return number_format((float)$amount, 2) . ' SAR';
}

$customerId         = $_SESSION['customerId'];
$customerName       = $_SESSION['userName'] ?? 'Customer';
$cartModel          = new Cart();
$providerModel      = new Provider();
$notificationModel  = new Notification();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $itemId = trim($_POST['itemId'] ?? '');

    if ($action === 'update' && $itemId !== '') {
        $qty = max(0, (int)($_POST['quantity'] ?? 1));
        if ($qty === 0) {
            $cartModel->removeItem($customerId, $itemId);
        } else {
            $cartModel->updateQuantity($customerId, $itemId, $qty);
        }
    } elseif ($action === 'remove' && $itemId !== '') {
        $cartModel->removeItem($customerId, $itemId);
    } elseif ($action === 'clear') {
        $cartModel->clear($customerId);
    }

    header('Location: cart.php');
    exit;
}

$cart         = $cartModel->getOrCreate($customerId);
$cartItems    = $cart['cartItems'] ?? [];
$notifications = $notificationModel->getByCustomer($customerId);
$unreadCount  = $notificationModel->getUnreadCount($customerId);

$grouped = [];
$grandTotal = 0.0;
$totalQty   = 0;

foreach ($cartItems as $item) {
    $providerId = (string)($item['providerId'] ?? '');
    if ($providerId === '') {
        continue;
    }

    $provider = $providerModel->findById($providerId);
    $providerName = $provider['businessName'] ?? 'Provider';
    $providerLogo = $provider['businessLogo'] ?? '';

    if (!isset($grouped[$providerId])) {
        $grouped[$providerId] = [
            'providerId'   => $providerId,
            'providerName' => $providerName,
            'providerLogo' => $providerLogo,
            'items'        => [],
            'subtotal'     => 0.0,
        ];
    }

    $lineTotal = (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 1);
    $grouped[$providerId]['items'][] = $item + ['lineTotal' => $lineTotal];
    $grouped[$providerId]['subtotal'] += $lineTotal;
    $grandTotal += $lineTotal;
    $totalQty   += (int)($item['quantity'] ?? 0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RePlate - Cart</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root{
      --blue:#1e3a8a;
      --blue-2:#6aa6e8;
      --light:#f3f6fb;
      --ink:#22304b;
      --muted:#78839a;
      --orange:#f68b2c;
      --card:#ffffff;
      --border:#d8e0ea;
      --radius:18px;
    }
    *{box-sizing:border-box}
    body{margin:0;font-family:'DM Sans',sans-serif;background:#eef2f7;color:var(--ink)}
    a{text-decoration:none;color:inherit}
    .navbar{background:linear-gradient(90deg,#173b96,#6ca8ea);color:#fff;padding:14px 28px;display:flex;align-items:center;justify-content:space-between;gap:18px}
    .brand{display:flex;align-items:center;gap:14px;font-weight:700}
    .brand-badge{width:34px;height:34px;border-radius:10px;background:#fff;color:#173b96;display:grid;place-items:center;font-weight:700}
    .nav-links{display:flex;gap:18px;font-size:14px;opacity:.95}
    .nav-right{display:flex;align-items:center;gap:12px}
    .search{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:9px 14px;color:#fff;min-width:190px}
    .icon-btn{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;border:1px solid rgba(255,255,255,.45);background:rgba(255,255,255,.12)}
    .badge{position:relative}
    .badge span{position:absolute;top:-4px;right:-2px;background:var(--orange);color:#fff;border-radius:999px;font-size:10px;min-width:18px;height:18px;display:grid;place-items:center;padding:0 4px}
    .container{max-width:1100px;margin:28px auto;padding:0 20px}
    .page-title{display:flex;align-items:center;gap:12px;margin-bottom:18px;font-family:'Playfair Display',serif}
    .page-title h1{margin:0;font-size:46px;color:#173b96}
    .page-title .back{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;background:#dfe8f6;color:#173b96;font-weight:700}
    .layout{display:grid;grid-template-columns:1.4fr .9fr;gap:24px;align-items:start}
    .card{background:var(--card);border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 10px 24px rgba(24,54,110,.08)}
    .group-card{padding:20px 20px 10px;margin-bottom:18px}
    .provider-head{display:flex;align-items:center;gap:14px;margin-bottom:14px}
    .provider-logo{width:70px;height:70px;border-radius:14px;background:#fff;border:1px solid var(--border);object-fit:cover;padding:10px}
    .provider-name{font-family:'Playfair Display',serif;font-size:28px;color:#d24c3f}
    .provider-sub{font-size:13px;color:var(--muted)}
    .item-row{display:grid;grid-template-columns:68px 1fr auto;gap:14px;align-items:center;padding:14px 0;border-top:1px solid #edf1f6}
    .item-photo{width:68px;height:68px;border-radius:14px;object-fit:cover;background:#eef3f8;border:1px solid var(--border)}
    .item-name{font-weight:700;color:#1f2f55}
    .item-price{font-size:13px;color:var(--muted);margin-top:4px}
    .item-actions{display:flex;align-items:center;gap:8px}
    .qty-form{display:flex;align-items:center;gap:6px}
    .qty-btn{width:30px;height:30px;border:none;border-radius:50%;background:#fff3e7;color:var(--orange);font-weight:700;cursor:pointer}
    .qty-val{min-width:26px;text-align:center;font-weight:700}
    .trash-btn{width:34px;height:34px;border:none;border-radius:10px;background:#eff3f8;color:#7b8aa5;cursor:pointer}
    .group-subtotal{margin-top:10px;padding-top:12px;border-top:1px dashed var(--border);display:flex;justify-content:space-between;font-weight:700;color:#173b96}
    .summary{padding:22px;position:sticky;top:18px}
    .summary h3{margin:0 0 14px;font-family:'Playfair Display',serif;font-size:30px;color:#173b96}
    .summary-line{display:flex;justify-content:space-between;padding:11px 0;border-bottom:1px solid #edf1f6;color:#5d6c87}
    .summary-line.total{border:none;padding-top:16px;font-weight:700;font-size:22px;color:#173b96}
    .primary-btn,.ghost-btn{display:block;width:100%;border:none;border-radius:14px;padding:15px 18px;font-size:18px;font-weight:700;cursor:pointer}
    .primary-btn{background:var(--orange);color:#fff;margin-top:16px}
    .ghost-btn{background:#eef3fa;color:#173b96;margin-top:10px}
    .empty{padding:36px;text-align:center;color:var(--muted)}
    .footer{margin-top:38px;background:linear-gradient(90deg,#2446ab,#6da9e9);color:#fff;text-align:center;padding:20px;font-size:13px}
    @media (max-width: 900px){
      .layout{grid-template-columns:1fr}
      .nav-links{display:none}
    }
  </style>
</head>
<body>
  <header class="navbar">
    <div class="brand">
      <div class="brand-badge">R</div>
      <div>RePlate</div>
      <nav class="nav-links">
        <a href="../shared/landing.php">Home</a>
        <a href="category.php">Categories</a>
        <a href="providers-list.php">Providers</a>
        <a href="favorites.php">Favorites</a>
        <a href="orders.php">Orders</a>
      </nav>
    </div>
    <div class="nav-right">
      <input class="search" value="<?= e($customerName) ?>" readonly>
      <a class="icon-btn badge" href="orders.php" title="Notifications">🔔<?php if ($unreadCount > 0): ?><span><?= (int)$unreadCount ?></span><?php endif; ?></a>
      <a class="icon-btn" href="customer-profile.php" title="Profile">👤</a>
    </div>
  </header>

  <main class="container">
    <div class="page-title">
      <a class="back" href="category.php">‹</a>
      <h1>Cart</h1>
    </div>

    <div class="layout">
      <section>
        <?php if (empty($grouped)): ?>
          <div class="card empty">
            <h3 style="margin-top:0;font-family:'Playfair Display',serif;color:#173b96;font-size:28px;">Your cart is empty</h3>
            <p>Add some items, then come back here to place your order.</p>
            <a class="primary-btn" href="category.php" style="display:inline-block;max-width:220px;">Browse items</a>
          </div>
        <?php else: ?>
          <?php foreach ($grouped as $group): ?>
            <div class="card group-card">
              <div class="provider-head">
                <?php if (!empty($group['providerLogo'])): ?>
                  <img class="provider-logo" src="<?= e($group['providerLogo']) ?>" alt="<?= e($group['providerName']) ?>">
                <?php else: ?>
                  <div class="provider-logo" style="display:grid;place-items:center;font-weight:700;color:#173b96;"><?= e(substr($group['providerName'],0,2)) ?></div>
                <?php endif; ?>
                <div>
                  <div class="provider-name"><?= e($group['providerName']) ?></div>
                  <div class="provider-sub"><?= count($group['items']) ?> item(s) in this order</div>
                </div>
              </div>

              <?php foreach ($group['items'] as $ci): ?>
                <div class="item-row">
                  <?php if (!empty($ci['photoUrl'] ?? '')): ?>
                    <img class="item-photo" src="<?= e($ci['photoUrl']) ?>" alt="<?= e($ci['itemName'] ?? '') ?>">
                  <?php else: ?>
                    <div class="item-photo" style="display:grid;place-items:center;color:#8a98ae;font-size:12px;">No image</div>
                  <?php endif; ?>

                  <div>
                    <div class="item-name"><?= e($ci['itemName'] ?? 'Item') ?></div>
                    <div class="item-price"><?= money($ci['price'] ?? 0) ?> each</div>
                  </div>

                  <div class="item-actions">
                    <form method="post" class="qty-form">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="itemId" value="<?= e((string)($ci['itemId'] ?? '')) ?>">
                      <input type="hidden" name="quantity" value="<?= max(0, ((int)($ci['quantity'] ?? 1))-1) ?>">
                      <button class="qty-btn" type="submit">−</button>
                    </form>

                    <div class="qty-val"><?= (int)($ci['quantity'] ?? 1) ?></div>

                    <form method="post" class="qty-form">
                      <input type="hidden" name="action" value="update">
                      <input type="hidden" name="itemId" value="<?= e((string)($ci['itemId'] ?? '')) ?>">
                      <input type="hidden" name="quantity" value="<?= ((int)($ci['quantity'] ?? 1))+1 ?>">
                      <button class="qty-btn" type="submit">+</button>
                    </form>

                    <form method="post">
                      <input type="hidden" name="action" value="remove">
                      <input type="hidden" name="itemId" value="<?= e((string)($ci['itemId'] ?? '')) ?>">
                      <button class="trash-btn" type="submit" title="Remove">🗑</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>

              <div class="group-subtotal">
                <span>Provider subtotal</span>
                <span><?= money($group['subtotal']) ?></span>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <aside class="card summary">
        <h3>Summary</h3>
        <div class="summary-line"><span>Providers</span><strong><?= count($grouped) ?></strong></div>
        <div class="summary-line"><span>Total quantity</span><strong><?= (int)$totalQty ?></strong></div>
        <div class="summary-line"><span>Service fee</span><strong>0.00 SAR</strong></div>
        <div class="summary-line total"><span>Total</span><span><?= money($grandTotal) ?></span></div>

        <?php if (!empty($grouped)): ?>
          <a href="checkout.php"><button class="primary-btn" type="button">Check out</button></a>
          <form method="post" onsubmit="return confirm('Clear your whole cart?');">
            <input type="hidden" name="action" value="clear">
            <button class="ghost-btn" type="submit">Clear cart</button>
          </form>
        <?php else: ?>
          <a href="category.php"><button class="primary-btn" type="button">Continue shopping</button></a>
        <?php endif; ?>
      </aside>
    </div>
  </main>

  <footer class="footer">
    © RePlate &nbsp;•&nbsp; Riyadh &nbsp;•&nbsp; hello@replate.com
  </footer>
</body>
</html>
