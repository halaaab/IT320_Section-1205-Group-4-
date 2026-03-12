<?php
// ================================================================
// signup-customer.php — Customer Registration
// ================================================================
// FORM FIELDS EXPECTED:  name, email, password, confirm
// ON SUCCESS:
//   $success = true  → show success message, redirect to login.php
// ON FAILURE:
//   $errors['name']     → name error message
//   $errors['email']    → email error message
//   $errors['password'] → password error message
//   $errors['confirm']  → confirm password error message
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Customer.php';

$errors  = [];
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirm  = trim($_POST['confirm']  ?? '');

    if (!$name)                                     $errors['name']     = 'Please enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email']    = 'Please enter a valid email.';
    if (strlen($password) < 8)                      $errors['password'] = 'Password must be at least 8 characters.';
    if ($password !== $confirm || !$confirm)        $errors['confirm']  = 'Passwords do not match.';

    if (empty($errors)) {
        $model = new Customer();
        if ($model->findByEmail($email)) {
            $errors['email'] = 'This email is already registered.';
        } else {
            $model->create([
                'fullName'    => $name,
                'email'       => $email,
                'password'    => $password,
                'phoneNumber' => '',
            ]);
            $success = true;
        }
    }
}

// ── EXAMPLE: Form in your HTML ──
// <form method="POST" action="">
//   <input name="name"     value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" />
//   <?php if (isset($errors['name'])): ?>
//     <span class="error"><?= $errors['name'] ?></span>
//   <?php endif; ?>
//
//   <input name="email"    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" />
//   <?php if (isset($errors['email'])): ?>
//     <span class="error"><?= $errors['email'] ?></span>
//   <?php endif; ?>
//
//   <input name="password" type="password" />
//   <input name="confirm"  type="password" />
//   <button type="submit">Sign up</button>
// </form>
//
// <?php if ($success): ?>
//   <script>setTimeout(() => location.href='login.php', 2000);</script>
//   <p>Account created! Redirecting...</p>
// <?php endif; ?>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Sign Up</title>
  <!-- YOUR HTML HERE -->
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
