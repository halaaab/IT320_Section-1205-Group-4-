<?php
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/SupportTicket.php';
require_once '../../back-end/models/Notification.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

function e($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function fmtDate($value): string {
    if ($value instanceof MongoDB\BSON\UTCDateTime) {
        return $value->toDateTime()->format('j M Y g:ia');
    }
    return '-';
}

$customerId = $_SESSION['customerId'];
$customerName = $_SESSION['userName'] ?? 'Customer';
$ticketModel = new SupportTicket();
$notificationModel = new Notification();

$errors = [];
$success = false;
$reason = trim($_POST['reason'] ?? '');
$description = trim($_POST['description'] ?? '');
$reasons = SupportTicket::REASONS;
$notifications = $notificationModel->getByCustomer($customerId);
$unreadCount = $notificationModel->getUnreadCount($customerId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($reason, SupportTicket::REASONS, true)) {
        $errors['reason'] = 'Please select a valid reason.';
    }
    if (mb_strlen($description) < 10) {
        $errors['description'] = 'Please describe the issue in at least 10 characters.';
    }

    if (empty($errors)) {
        $ticketModel->create($customerId, [
            'reason'      => $reason,
            'description' => $description,
        ]);
        $success = true;
        $reason = '';
        $description = '';
    }
}

$tickets = $ticketModel->getByCustomer($customerId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>RePlate - Contact us</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@400;500;700&display=swap" rel="stylesheet">
  <style>
    :root{--blue:#1d3e97;--light:#eef2f7;--card:#fff;--border:#d6deea;--orange:#f58b2d;--muted:#73829b}
    *{box-sizing:border-box} body{margin:0;background:var(--light);font-family:'DM Sans',sans-serif;color:#213153}
    .navbar{background:linear-gradient(90deg,#173b96,#6ca8ea);color:#fff;padding:14px 28px;display:flex;justify-content:space-between;align-items:center}
    .brand{display:flex;gap:14px;align-items:center;font-weight:700}.brand-badge{width:34px;height:34px;border-radius:10px;background:#fff;color:#173b96;display:grid;place-items:center}
    .nav-links{display:flex;gap:18px;font-size:14px}.nav-right{display:flex;gap:12px;align-items:center}
    .search{background:rgba(255,255,255,.18);border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:9px 14px;color:#fff;min-width:190px}
    .icon-btn{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;border:1px solid rgba(255,255,255,.45);background:rgba(255,255,255,.12)}
    .badge{position:relative}.badge span{position:absolute;top:-4px;right:-2px;background:var(--orange);color:#fff;border-radius:999px;font-size:10px;min-width:18px;height:18px;display:grid;place-items:center;padding:0 4px}
    .container{max-width:1100px;margin:28px auto;padding:0 20px}
    .layout{display:grid;grid-template-columns:230px 1fr;gap:24px}
    .sidebar{background:linear-gradient(180deg,#4087d5,#173b96);color:#fff;border-radius:18px;padding:22px}
    .sidebar h3{margin:0 0 4px;font-family:'Playfair Display',serif;font-size:30px}
    .sidebar .sub{font-size:13px;opacity:.8;margin-bottom:18px}
    .menu a{display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:12px;color:#fff;text-decoration:none;opacity:.9}
    .menu a.active,.menu a:hover{background:rgba(255,255,255,.14);opacity:1}
    .content-card{background:#fff;border:1px solid var(--border);border-radius:18px;padding:24px;box-shadow:0 10px 24px rgba(24,54,110,.08)}
    .title{font-family:'Playfair Display',serif;color:var(--blue);font-size:38px;margin:0 0 18px}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
    .field{display:flex;flex-direction:column;gap:8px}
    label{font-weight:700;color:#173b96}
    input,select,textarea{width:100%;padding:12px 14px;border:1px solid var(--border);border-radius:14px;font:inherit;background:#fff}
    textarea{min-height:120px;resize:vertical}
    .full{grid-column:1/-1}
    .submit-btn{border:none;background:#173b96;color:#fff;border-radius:14px;padding:12px 24px;font-weight:700;cursor:pointer}
    .success,.error{padding:12px 14px;border-radius:12px;margin-bottom:14px}
    .success{background:#e8f7ec;color:#1f8d48;border:1px solid #bfe3c9}.error{background:#fff1f0;color:#b63a2c;border:1px solid #efb1ab}
    .history{margin-top:26px}
    .history h3{font-family:'Playfair Display',serif;color:var(--blue);font-size:30px;margin:0 0 14px}
    .ticket{border:1px solid #e7edf5;border-radius:16px;padding:16px;margin-bottom:12px}
    .ticket-top{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px}
    .chip{display:inline-block;padding:5px 10px;border-radius:999px;font-size:12px;font-weight:700}
    .chip.open{background:#fff1df;color:#d47417}.chip.resolved{background:#e8f7ec;color:#1f8d48}
    .footer{margin-top:38px;background:linear-gradient(90deg,#2446ab,#6da9e9);color:#fff;text-align:center;padding:20px;font-size:13px}
    @media (max-width:900px){.layout{grid-template-columns:1fr}.form-grid{grid-template-columns:1fr}.nav-links{display:none}}
  </style>
</head>
<body>
<header class="navbar">
  <div class="brand">
    <div class="brand-badge">R</div>
    <div>RePlate</div>
    <nav class="nav-links">
      <a href="../shared/landing.php" style="color:#fff;text-decoration:none">Home</a>
      <a href="orders.php" style="color:#fff;text-decoration:none">Orders</a>
      <a href="cart.php" style="color:#fff;text-decoration:none">Cart</a>
    </nav>
  </div>
  <div class="nav-right">
    <input class="search" value="<?= e($customerName) ?>" readonly>
    <a class="icon-btn badge" href="orders.php">🔔<?php if ($unreadCount > 0): ?><span><?= (int)$unreadCount ?></span><?php endif; ?></a>
    <a class="icon-btn" href="customer-profile.php">👤</a>
  </div>
</header>

<main class="container">
  <div class="layout">
    <aside class="sidebar">
      <h3>Welcome back, <?= e(explode(' ', $customerName)[0] ?? 'Customer') ?></h3>
      <div class="sub">Manage your account</div>
      <nav class="menu">
        <a href="customer-profile.php">👤 Profile</a>
        <a href="favorites.php">❤️ Favorites</a>
        <a href="orders.php">📦 Orders</a>
        <a href="cart.php">🛒 Cart</a>
        <a class="active" href="contact.php">✉️ Contact us</a>
        <a href="../shared/landing.php">↩ Logout</a>
      </nav>
    </aside>

    <section class="content-card">
      <h1 class="title">Contact us</h1>

      <?php if ($success): ?>
        <div class="success">Your support ticket has been submitted successfully.</div>
      <?php endif; ?>

      <form method="post">
        <div class="form-grid">
          <div class="field">
            <label for="reason">Reason</label>
            <select id="reason" name="reason">
              <option value="">Choose a reason</option>
              <?php foreach ($reasons as $r): ?>
                <option value="<?= e($r) ?>" <?= $reason === $r ? 'selected' : '' ?>><?= e($r) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['reason'])): ?><div class="error"><?= e($errors['reason']) ?></div><?php endif; ?>
          </div>

          <div class="field">
            <label for="customerName">Customer</label>
            <input id="customerName" type="text" value="<?= e($customerName) ?>" readonly>
          </div>

          <div class="field full">
            <label for="description">Description</label>
            <textarea id="description" name="description" placeholder="Describe your issue here..."><?= e($description) ?></textarea>
            <?php if (!empty($errors['description'])): ?><div class="error"><?= e($errors['description']) ?></div><?php endif; ?>
          </div>

          <div class="field full">
            <button class="submit-btn" type="submit">Submit</button>
          </div>
        </div>
      </form>

      <div class="history">
        <h3>Previous tickets</h3>
        <?php if (empty($tickets)): ?>
          <p style="color:#6e7c95">You have not submitted any support tickets yet.</p>
        <?php else: ?>
          <?php foreach ($tickets as $ticket): $status = $ticket['status'] ?? 'open'; ?>
            <div class="ticket">
              <div class="ticket-top">
                <div>
                  <strong><?= e($ticket['reason'] ?? 'Issue') ?></strong><br>
                  <span style="color:#6e7c95;font-size:13px;"><?= e(fmtDate($ticket['submittedAt'] ?? null)) ?></span>
                </div>
                <span class="chip <?= e($status) ?>"><?= e(ucfirst($status)) ?></span>
              </div>
              <div style="color:#44536f;line-height:1.6"><?= nl2br(e($ticket['description'] ?? '')) ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>
  </div>
</main>

<footer class="footer">© RePlate • Riyadh • hello@replate.com</footer>
</body>
</html>
