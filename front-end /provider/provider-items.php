<?php
// ================================================================
// provider-items.php — Provider's Item Listings
// ================================================================
// VARIABLES:
//   $items      → all items by this provider
//   $filter     → 'all' | 'available' | 'unavailable'
// POST ACTIONS:
//   action=toggle  & itemId=xxx → toggles isAvailable
//   action=delete  & itemId=xxx → deletes item
// ================================================================

session_start();
require_once '../../../back-end/config/database.php';
require_once '../../../back-end/models/BaseModel.php';
require_once '../../../back-end/models/Item.php';

if (empty($_SESSION['providerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$providerId = $_SESSION['providerId'];
$itemModel  = new Item();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $itemId = $_POST['itemId'] ?? '';

    if ($itemId) {
        $item = $itemModel->findById($itemId);
        // Security: only owner can edit
        if ($item && (string) $item['providerId'] === $providerId) {
            if ($action === 'toggle') {
                $itemModel->updateById($itemId, ['isAvailable' => !$item['isAvailable']]);
            }
            if ($action === 'delete') {
                $itemModel->deleteById($itemId);
            }
        }
    }
    header('Location: provider-items.php');
    exit;
}

$filter = $_GET['filter'] ?? 'all';
$items  = $itemModel->getByProvider($providerId);

if ($filter === 'available')   $items = array_values(array_filter($items, fn($i) =>  $i['isAvailable']));
if ($filter === 'unavailable') $items = array_values(array_filter($items, fn($i) => !$i['isAvailable']));

// ── EXAMPLE: Items table ──
// <?php foreach ($items as $item): ?>
//   <tr>
//     <td><?= htmlspecialchars($item['itemName']) ?></td>
//     <td><?= $item['isAvailable'] ? 'Active' : 'Hidden' ?></td>
//     <td>
//       <form method="POST" style="display:inline">
//         <input type="hidden" name="action" value="toggle" />
//         <input type="hidden" name="itemId" value="<?= $item['_id'] ?>" />
//         <button type="submit"><?= $item['isAvailable'] ? 'Hide' : 'Show' ?></button>
//       </form>
//       <a href="provider-item-details.php?itemId=<?= $item['_id'] ?>">Edit</a>
//       <form method="POST" style="display:inline">
//         <input type="hidden" name="action" value="delete" />
//         <input type="hidden" name="itemId" value="<?= $item['_id'] ?>" />
//         <button type="submit">Delete</button>
//       </form>
//     </td>
//   </tr>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – My Items</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
