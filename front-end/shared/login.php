<?php
// ================================================================
// login.php — Login Page (Customer + Provider)
// ================================================================
// FORM FIELDS EXPECTED:  email, password, role (customer|provider)
// ON SUCCESS:
//   customer  → redirects to ../customer/category.php
//   provider  → redirects to ../provider/provider-dashboard.php
// ON FAILURE:
//   $error → string error message to show in your HTML
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Customer.php';
require_once '../../back-end/models/Provider.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $role     = trim($_POST['role']     ?? 'customer');

    if (!$email || !$password) {
        $error = 'Email and password are required.';
    } else {
        if ($role === 'customer') {
            $model = new Customer();
            $user  = $model->findByEmail($email);
            if (!$user || !$model->verifyPassword($password, $user['passwordHash'])) {
                $error = 'Invalid email or password.';
            } else {
                $_SESSION['customerId'] = (string) $user['_id'];
                $_SESSION['userName']   = $user['fullName'];
                $_SESSION['role']       = 'customer';
                header('Location: ../customer/category.php');
                exit;
            }
        } elseif ($role === 'provider') {
            $model = new Provider();
            $user  = $model->findByEmail($email);
            if (!$user || !$model->verifyPassword($password, $user['passwordHash'])) {
                $error = 'Invalid email or password.';
            } else {
                $_SESSION['providerId']    = (string) $user['_id'];
                $_SESSION['providerName']  = $user['businessName'];
                $_SESSION['role']          = 'provider';
                header('Location: ../provider/provider-dashboard.php');
                exit;
            }
        }
    }
}

// ── EXAMPLE: How to show error in your HTML ──
// <?php if ($error): ?>
//   <span class="error"><?= htmlspecialchars($error) ?></span>
// <?php endif; ?>

// ── EXAMPLE: Your form must have these fields ──
// <form method="POST" action="">
//   <input name="email"    type="email"    />
//   <input name="password" type="password" />
//   <input name="role"     type="hidden" value="customer" />  ← or "provider"
//   <button type="submit">Log in</button>
// </form>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Login</title>
  <!-- YOUR HTML HERE -->
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
