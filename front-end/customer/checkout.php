<?php
// ================================================================
// checkout.php — Place Order
// ================================================================
// VARIABLES:
//   $cartItems  → array of cart items to review
//   $total      → float total
//   $locations  → array of pickup locations (per item)
//   $error      → string error if something is wrong
// POST ACTION:
//   Submits order → creates order + order_items → clears cart
//   → redirects to order-details.php?orderId=xxx
// FORM FIELD EXPECTED:
//   selectedPickupTime (string)
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Cart.php';
require_once '../../back-end/models/Item.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/PickupLocation.php';
require_once '../../back-end/models/Order.php';
require_once '../../back-end/models/OrderItem.php';
require_once '../../back-end/models/Notification.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId = $_SESSION['customerId'];
$cartModel  = new Cart();
$cart       = $cartModel->getOrCreate($customerId);
$cartItems  = $cart['cartItems'] ?? [];
$error      = '';
$total      = 0;

// Enrich cart items with location info for display
$enriched = [];
foreach ($cartItems as $ci) {
    $item     = (new Item())->findById((string) $ci['itemId']);
    $location = $item ? (new PickupLocation())->findById((string) $item['pickupLocationId']) : null;
    $lineTotal = ($ci['price'] ?? 0) * ($ci['quantity'] ?? 1);
    $total    += $lineTotal;
    $enriched[] = [
        'cartItem'     => $ci,
        'item'         => $item,
        'location'     => $location,
        'locationStr'  => $location ? ($location['street'] . ', ' . $location['city']) : '',
        'pickupTimes'  => $item['pickupTimes'] ?? [],
        'lineTotal'    => $lineTotal,
    ];
}

// ── Handle POST: place order ──
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($cartItems)) {
        $error = 'Your cart is empty.';
    } else {
        $selectedPickupTime = trim($_POST['selectedPickupTime'] ?? '');
        $orderItems = [];

        foreach ($enriched as $e) {
            $ci   = $e['cartItem'];
            $item = $e['item'];
            $provider = (new Provider())->findById((string) $ci['providerId']);

            if (!$item || !$item['isAvailable']) {
                $error = "Item \"{$ci['itemName']}\" is no longer available.";
                break;
            }
            if ($item['quantity'] < $ci['quantity']) {
                $error = "Not enough stock for \"{$ci['itemName']}\".";
                break;
            }
            $orderItems[] = [
                'itemId'             => (string) $ci['itemId'],
                'providerId'         => (string) $ci['providerId'],
                'itemName'           => $ci['itemName'],
                'providerName'       => $provider['businessName'] ?? '',
                'photoUrl'           => $item['photoUrl'] ?? '',
                'price'              => $ci['price'],
                'quantity'           => $ci['quantity'],
                'pickupLocation'     => $e['locationStr'],
                'selectedPickupTime' => $selectedPickupTime ?: ($item['pickupTimes'][0] ?? ''),
            ];
        }

        if (!$error) {
            $orderId = (new Order())->create($customerId, ['totalAmount' => $total]);
            (new OrderItem())->createFromCart($orderId, $orderItems);

            // Decrease stock
            foreach ($orderItems as $oi) {
                (new Item())->decreaseQuantity($oi['itemId'], $oi['quantity']);
            }

            $cartModel->clear($customerId);
            (new Notification())->notifyOrderPlaced($customerId, $orderId);

            header("Location: order-details.php?orderId=$orderId&new=1");
            exit;
        }
    }
}

// ── EXAMPLE: Checkout form ──
// <form method="POST">
//   <?php foreach ($enriched as $e): ?>
//     <p><?= htmlspecialchars($e['cartItem']['itemName']) ?> × <?= $e['cartItem']['quantity'] ?></p>
//     <p>Pickup: <?= htmlspecialchars($e['locationStr']) ?></p>
//     <select name="selectedPickupTime">
//       <?php foreach ($e['pickupTimes'] as $t): ?>
//         <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
//       <?php endforeach; ?>
//     </select>
//   <?php endforeach; ?>
//   <p>Total: <?= number_format($total, 2) ?> SAR</p>
//   <button type="submit">Place Order</button>
// </form>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Checkout</title>
  <!-- YOUR HTML HERE -->
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
