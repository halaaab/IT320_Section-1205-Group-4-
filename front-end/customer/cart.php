<?php
// ================================================================
// cart.php — Customer Cart
// ================================================================
// VARIABLES:
//   $cart       → cart document { cartItems: [...] }
//   $total      → float total price
//   $itemCount  → int number of items
// POST ACTIONS:
//   action=update  → body: itemId, quantity  → updates qty
//   action=remove  → body: itemId            → removes item
//   action=clear   → clears entire cart
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Cart.php';

// Redirect to login if not logged in
if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId = $_SESSION['customerId'];
$cartModel  = new Cart();

// ── Handle POST actions ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $itemId = $_POST['itemId'] ?? '';

    if ($action === 'update' && $itemId) {
        $qty = (int) ($_POST['quantity'] ?? 1);
        $qty > 0
            ? $cartModel->updateQuantity($customerId, $itemId, $qty)
            : $cartModel->removeItem($customerId, $itemId);
    }
    if ($action === 'remove' && $itemId) {
        $cartModel->removeItem($customerId, $itemId);
    }
    if ($action === 'clear') {
        $cartModel->clear($customerId);
    }

    header('Location: cart.php');
    exit;
}

// ── Load cart ──
$cart      = $cartModel->getOrCreate($customerId);
$cartItems = $cart['cartItems'] ?? [];
$total     = array_sum(array_map(fn($i) => $i['price'] * $i['quantity'], $cartItems));
$itemCount = count($cartItems);

// ── EXAMPLE: Cart items loop ──
// <?php foreach ($cartItems as $ci): ?>
//   <div>
//     <span><?= htmlspecialchars($ci['itemName']) ?></span>
//     <span><?= $ci['price'] ?> SAR × <?= $ci['quantity'] ?></span>
//     <!-- Update qty form -->
//     <form method="POST">
//       <input type="hidden" name="action"   value="update" />
//       <input type="hidden" name="itemId"   value="<?= $ci['itemId'] ?>" />
//       <input type="number" name="quantity" value="<?= $ci['quantity'] ?>" min="0" />
//       <button type="submit">Update</button>
//     </form>
//     <!-- Remove form -->
//     <form method="POST">
//       <input type="hidden" name="action" value="remove" />
//       <input type="hidden" name="itemId" value="<?= $ci['itemId'] ?>" />
//       <button type="submit">Remove</button>
//     </form>
//   </div>
// <?php endforeach; ?>
// <p>Total: <?= number_format($total, 2) ?> SAR</p>
// <a href="checkout.php">Proceed to Checkout</a>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Cart</title>
  <!-- YOUR HTML HERE -->
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
