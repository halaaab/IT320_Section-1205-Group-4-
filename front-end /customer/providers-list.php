<?php
// ================================================================
// providers-list.php — Browse All Providers
// ================================================================
// URL PARAMS:  ?category=xxx  (optional filter)
// VARIABLES:
//   $providers          → array of providers (filtered or all)
//   $categoryFilter     → current filter value
//   $providerCategories → all valid categories for filter tabs
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';

$providerModel      = new Provider();
$categoryFilter     = $_GET['category'] ?? 'all';
$providerCategories = array_merge(['all'], Provider::CATEGORIES);

$providers = $providerModel->findAll();
foreach ($providers as &$p) unset($p['passwordHash']);
unset($p);

if ($categoryFilter !== 'all') {
    $providers = array_values(
        array_filter($providers, fn($p) => $p['category'] === $categoryFilter)
    );
}

// ── EXAMPLE: Category filter tabs ──
// <?php foreach ($providerCategories as $cat): ?>
//   <a href="?category=<?= urlencode($cat) ?>"
//      class="<?= $categoryFilter===$cat ? 'active' : '' ?>">
//     <?= htmlspecialchars($cat) ?>
//   </a>
// <?php endforeach; ?>
//
// ── EXAMPLE: Provider cards ──
// <?php foreach ($providers as $p): ?>
//   <a href="providers-page.php?providerId=<?= $p['_id'] ?>">
//     <h3><?= htmlspecialchars($p['businessName']) ?></h3>
//     <span><?= htmlspecialchars($p['category']) ?></span>
//   </a>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Providers</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
