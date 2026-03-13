<?php
// ================================================================
// contact.php — Customer Support Tickets
// ================================================================
// VARIABLES:
//   $tickets    → array of this customer's support tickets
//   $success    → bool — true after submitting a ticket
//   $errors     → array of field errors
//   $reasons    → valid reason options for dropdown
// POST ACTION:
//   FORM FIELDS: reason, description
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/SupportTicket.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId   = $_SESSION['customerId'];
$ticketModel  = new SupportTicket();
$errors       = [];
$success      = false;
$reasons      = SupportTicket::REASONS; // ['Missing item','Damaged items','Others']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason      = trim($_POST['reason']      ?? '');
    $description = trim($_POST['description'] ?? '');

    if (!in_array($reason, SupportTicket::REASONS)) $errors['reason']      = 'Please select a reason.';
    if (strlen($description) < 10)                  $errors['description'] = 'Please describe your issue (min 10 characters).';

    if (empty($errors)) {
        $ticketModel->create($customerId, [
            'reason'      => $reason,
            'description' => $description,
        ]);
        $success = true;
    }
}

$tickets = $ticketModel->getByCustomer($customerId);

// ── EXAMPLE: Submit form ──
// <form method="POST">
//   <select name="reason">
//     <?php foreach ($reasons as $r): ?>
//       <option value="<?= $r ?>"><?= $r ?></option>
//     <?php endforeach; ?>
//   </select>
//   <textarea name="description"></textarea>
//   <button type="submit">Submit</button>
// </form>
//
// ── EXAMPLE: Ticket history ──
// <?php foreach ($tickets as $t): ?>
//   <p><?= htmlspecialchars($t['reason']) ?> — <?= htmlspecialchars($t['status']) ?></p>
//   <p><?= htmlspecialchars($t['description']) ?></p>
// <?php endforeach; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Contact Support</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
