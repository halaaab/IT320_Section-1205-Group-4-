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
//   <input type="number" name="quantity" value="1" min="1" max="<?= $item['quantity'] ?>" />
//   <button type="submit"><?= $inCart ? 'Update Cart' : 'Add to Cart' ?></button>
// </form>
//
// ── EXAMPLE: Favourite toggle ──
// <form method="POST">
//   <input type="hidden" name="action" value="toggle_fav" />
//   <button type="submit"><?= $isSaved ? '❤️ Saved' : '🤍 Save' ?></button>
// </form>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – <?= htmlspecialchars($item['itemName'] ?? 'Item') ?></title>
  <!-- YOUR HTML HERE -->
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
