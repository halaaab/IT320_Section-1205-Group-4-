<?php
// ================================================================
// favorites.php — Customer Saved Items
// ================================================================
// VARIABLES:
//   $favourites → array of saved items (full item objects)
// POST ACTION:
//   action=remove & itemId=xxx → removes item from favourites
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Favourite.php';
require_once '../../back-end/models/Item.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId = $_SESSION['customerId'];
$favModel   = new Favourite();

// ── Handle remove ──
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
    $itemId = $_POST['itemId'] ?? '';
    if ($itemId) $favModel->remove($customerId, $itemId);
    header('Location: favorites.php');
    exit;
}

// Load saved item IDs, then fetch full item objects
$savedRefs  = $favModel->getByCustomer($customerId);  // array of { itemId }
$itemModel  = new Item();
$favourites = [];
foreach ($savedRefs as $ref) {
    $item = $itemModel->findById((string) $ref['itemId']);
    if ($item) $favourites[] = $item;
}

// ── EXAMPLE: Favourites loop ──
// <?php foreach ($favourites as $item): ?>
//   <a href="item-details.php?itemId=<?= $item['_id'] ?>">
//     <h3><?= htmlspecialchars($item['itemName']) ?></h3>
//     <p><?= $item['listingType']==='donate' ? 'Free' : $item['price'].' SAR' ?></p>
//   </a>
//   <form method="POST">
//     <input type="hidden" name="action" value="remove" />
//     <input type="hidden" name="itemId" value="<?= $item['_id'] ?>" />
//     <button type="submit">Remove</button>
//   </form>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Favourites</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
