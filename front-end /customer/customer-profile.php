<?php
// ================================================================
// customer-profile.php — View & Edit Customer Profile
// ================================================================
// VARIABLES:
//   $customer   → current customer object { fullName, email, phoneNumber }
//   $success    → bool — true after successful save
//   $errors     → array of field errors
// POST ACTION (action=update):
//   FORM FIELDS: fullName, phoneNumber, currentPassword,
//                newPassword, confirmPassword
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Customer.php';

if (empty($_SESSION['customerId'])) {
    header('Location: ../shared/login.php');
    exit;
}

$customerId    = $_SESSION['customerId'];
$customerModel = new Customer();
$customer      = $customerModel->findById($customerId);
$errors        = [];
$success       = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName    = trim($_POST['fullName']    ?? '');
    $phoneNumber = trim($_POST['phoneNumber'] ?? '');
    $currentPw   = trim($_POST['currentPassword']  ?? '');
    $newPw       = trim($_POST['newPassword']       ?? '');
    $confirmPw   = trim($_POST['confirmPassword']   ?? '');

    if (!$fullName) $errors['fullName'] = 'Name is required.';

    // Password change is optional — only validate if filled
    if ($newPw) {
        if (!$customerModel->verifyPassword($currentPw, $customer['passwordHash'])) {
            $errors['currentPassword'] = 'Current password is incorrect.';
        }
        if (strlen($newPw) < 8) {
            $errors['newPassword'] = 'New password must be at least 8 characters.';
        }
        if ($newPw !== $confirmPw) {
            $errors['confirmPassword'] = 'Passwords do not match.';
        }
    }

    if (empty($errors)) {
        $updateData = [
            'fullName'    => $fullName,
            'phoneNumber' => $phoneNumber,
        ];
        if ($newPw) $updateData['password'] = $newPw;

        $customerModel->updateById($customerId, $updateData);
        $_SESSION['userName'] = $fullName;
        $customer = $customerModel->findById($customerId); // refresh
        $success  = true;
    }
}

// Logout action
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../shared/login.php');
    exit;
}

// ── EXAMPLE: Profile form ──
// <form method="POST">
//   <input name="fullName"    value="<?= htmlspecialchars($customer['fullName']) ?>" />
//   <input name="phoneNumber" value="<?= htmlspecialchars($customer['phoneNumber'] ?? '') ?>" />
//   <input name="currentPassword" type="password" />
//   <input name="newPassword"     type="password" />
//   <input name="confirmPassword" type="password" />
//   <button type="submit">Save</button>
// </form>
// <?php if ($success): ?><p>Profile updated!</p><?php endif; ?>
// <a href="?logout=1">Log out</a>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – My Profile</title>
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
