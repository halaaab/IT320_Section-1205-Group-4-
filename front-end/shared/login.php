<?php
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Customer.php';
require_once '../../back-end/models/Provider.php';

$errors = [];
$old = [
    'email' => '',
    'role'  => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $role     = trim($_POST['role'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $old['email'] = htmlspecialchars($email);
    $old['role']  = $role;

    if (!in_array($role, ['customer', 'provider'])) {
        $errors['role'] = 'Please choose a valid account type.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (!$password) {
        $errors['password'] = 'Please enter your password.';
    }

    if (empty($errors)) {
        try {
            if ($role === 'customer') {
                $model = new Customer();
                $user = $model->findByEmail($email);

                if (!$user) {
                    $errors['email'] = 'No customer account was found with this email.';
                } else {
                    $storedHash = $user['passwordHash'] ?? '';

                    if (!password_verify($password, $storedHash)) {
                        $errors['password'] = 'Incorrect password.';
                    } else {
                        $_SESSION['customerId'] = (string)($user['_id'] ?? $user['id'] ?? '');
                        $_SESSION['userName']   = $user['fullName'] ?? '';

                        header('Location: landing.php');
                        exit;
                    }
                }
            }

            if ($role === 'provider') {
                $model = new Provider();
                $user = $model->findByEmail($email);

                if (!$user) {
                    $errors['email'] = 'No provider account was found with this email.';
                } else {
                    $storedHash = $user['passwordHash'] ?? '';

                    if (!password_verify($password, $storedHash)) {
                        $errors['password'] = 'Incorrect password.';
                    } else {
                        $_SESSION['providerId']   = (string)($user['_id'] ?? $user['id'] ?? '');
                        $_SESSION['providerName'] = $user['businessName'] ?? '';

                        header('Location: ../provider/provider-dashboard.php');
                        exit;
                    }
                }
            }
        } catch (Throwable $e) {
            die('Login error: ' . $e->getMessage());
        }
    }
}

$hasErrors = !empty($errors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>RePlate – Log In</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;1,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>

  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Playfair Display', serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background: #f8fbff;
    }

    .page {
      flex: 1;
      display: grid;
      grid-template-columns: 1fr 1fr;
      min-height: calc(100vh - 120px);
      background-image: url('../../images/signup banner.png');
      background-size: 110%;
      background-position: right top;
      background-repeat: no-repeat;
    }

    .left-panel {
      display: flex;
      flex-direction: column;
      justify-content: center;
      padding: 48px 64px;
      background: transparent;
      position: relative;
    }

    .back-btn {
      position: absolute;
      top: 28px;
      left: 24px;
      display: flex;
      align-items: center;
      justify-content: center;
      width: 42px;
      height: 42px;
      border-radius: 50%;
      border: 2px solid #1a3a6b;
      background: transparent;
      cursor: pointer;
      color: #1a3a6b;
      font-size: 24px;
      text-decoration: none;
      transition: background 0.2s, border-color 0.2s;
      line-height: 1;
    }

    .back-btn:hover {
      background: #fff;
      border-color: #1a3a6b;
    }

    .form-area {
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: center;
      width: 100%;
      max-width: 620px;
      min-height: auto;
      background: transparent;
      border-radius: 0;
      padding: 0;
      backdrop-filter: none;
      box-shadow: none;
      margin-top: -40px;
    }

    .form-title {
      font-size: 42px;
      color: #1a3a6b;
      margin-bottom: 14px;
      font-weight: 700;
      text-align: center;
    }

    .form-subtitle {
      font-size: 18px;
      color: #5f78a0;
      margin-bottom: 34px;
      text-align: center;
    }

    .form-subtitle a {
      color: #2255a4;
      text-decoration: none;
      font-weight: 700;
    }

    .form-subtitle a:hover {
      text-decoration: underline;
    }

    #step1 {
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      width: 100%;
    }

    #step1, #step2 {
      transition: opacity 0.3s;
      width: 100%;
    }

    #step2 {
      display: none;
      opacity: 0;
    }

    .role-selector {
      display: flex;
      gap: 14px;
      margin-top: 10px;
      justify-content: center;
      width: 100%;
      max-width: 430px;
    }

    .role-btn {
      flex: 1;
      padding: 16px 24px;
      border-radius: 50px;
      border: 2px solid #102c8f;
      background: #102c8f;
      color: #fff;
      font-size: 16px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
      box-shadow: 0 4px 16px rgba(26,58,107,0.18);
    }

    .role-btn:hover {
      background: #2255a4;
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(26,58,107,0.3);
    }

    .login-form {
      display: flex;
      flex-direction: column;
      gap: 24px;
      width: 100%;
    }

    .field-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .field-label {
      font-size: 15px;
      font-weight: 700;
      color: #1a3a6b;
      font-family: 'Playfair Display', serif;
    }

    .field-input {
      padding: 18px 24px;
      border-radius: 50px;
      border: 1.5px solid #c8d8ee;
      background: rgba(255,255,255,0.85);
      font-size: 16px;
      font-family: 'Playfair Display', serif;
      color: #1a3a6b;
      outline: none;
      transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
      width: 100%;
    }

    .field-input::placeholder {
      color: #b0c4d8;
    }

    .field-input:focus {
      border-color: #2255a4;
      background: #fff;
      box-shadow: 0 0 0 3px rgba(34,85,164,0.1);
    }

    .field-input.error {
      border-color: #c0392b;
      background: rgba(255,248,248,0.9);
    }

    .password-wrap {
      position: relative;
    }

    .password-wrap .field-input {
      padding-right: 50px;
    }

    .toggle-pw {
      position: absolute;
      right: 18px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      padding: 0;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .field-error {
      font-size: 12px;
      color: #c0392b;
      margin-top: 2px;
      padding-left: 8px;
      display: none;
    }

    .field-error.show {
      display: block;
    }

    .login-role-note {
      text-align: center;
      color: #1a3a6b;
      font-size: 15px;
      margin-top: -10px;
    }

    .form-buttons {
      display: flex;
      gap: 16px;
      justify-content: center;
      margin-top: 10px;
    }

    .btn-back,
    .btn-submit {
      background: #1a3a6b;
      color: #fff;
      border: none;
      border-radius: 50px;
      padding: 15px 32px;
      font-size: 16px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      box-shadow: 0 6px 20px rgba(26,58,107,0.25);
      transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
      min-width: 150px;
    }

    .btn-back:hover,
    .btn-submit:hover {
      background: #2255a4;
      transform: translateY(-2px);
      box-shadow: 0 10px 28px rgba(26,58,107,0.35);
    }

    footer {
      background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%);
      padding: 28px 48px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 14px;
    }

    .footer-top {
      display: flex;
      align-items: center;
      gap: 18px;
    }

    .social-icon {
      width: 42px;
      height: 42px;
      border-radius: 50%;
      border: 1.5px solid rgba(255,255,255,0.5);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #fff;
      font-size: 16px;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      font-family: 'Playfair Display', serif;
      transition: background 0.2s;
    }

    .social-icon:hover {
      background: rgba(255,255,255,0.15);
    }

    .footer-divider {
      width: 1px;
      height: 22px;
      background: rgba(255,255,255,0.3);
    }

    .footer-brand {
      display: flex;
      align-items: center;
      gap: 8px;
      color: #fff;
      font-size: 16px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
    }

    .footer-email {
      display: flex;
      align-items: center;
      gap: 6px;
      color: rgba(255,255,255,0.9);
      font-size: 14px;
      font-family: 'Playfair Display', serif;
    }

    .footer-email a {
      color: rgba(255,255,255,0.9);
      text-decoration: none;
    }

    .footer-bottom {
      display: flex;
      align-items: center;
      gap: 8px;
      color: rgba(255,255,255,0.7);
      font-size: 13px;
      font-family: 'Playfair Display', serif;
    }
  </style>
</head>
<body>

  <div class="page">
    <div class="left-panel">
      <a href="landing.php" class="back-btn">&#8249;</a>

      <div class="form-area">
        <div id="step1">
          <h1 class="form-title">Log In</h1>
          <p class="form-subtitle">Don’t have an account? <a href="signup-customer.php">Sign up</a></p>

          <div class="role-selector">
            <button class="role-btn" onclick="showLoginForm('customer')">Customer</button>
            <button class="role-btn" onclick="showLoginForm('provider')">Provider</button>
          </div>
        </div>

        <div id="step2">
          <h1 class="form-title">Log In</h1>
          <p class="form-subtitle">Don’t have an account? <a href="signup-customer.php">Sign up</a></p>
          <p class="login-role-note">Logging in as <strong id="roleText">Customer</strong></p>

          <form class="login-form" method="POST" action="" id="loginForm" novalidate>
            <input type="hidden" name="role" id="roleInput" value="<?= htmlspecialchars($old['role']) ?>">

            <div class="field-group">
              <label class="field-label" for="emailInput">Email address</label>
              <input
                class="field-input <?= isset($errors['email']) ? 'error' : '' ?>"
                id="emailInput"
                name="email"
                type="email"
                placeholder="Enter your email ....."
                value="<?= $old['email'] ?>"
              />
              <span class="field-error <?= isset($errors['email']) ? 'show' : '' ?>" id="emailError">
                <?= isset($errors['email']) ? htmlspecialchars($errors['email']) : 'Please enter a valid email address.' ?>
              </span>
            </div>

            <div class="field-group">
              <label class="field-label" for="passwordInput">Password</label>
              <div class="password-wrap">
                <input
                  class="field-input <?= isset($errors['password']) ? 'error' : '' ?>"
                  id="passwordInput"
                  name="password"
                  type="password"
                  placeholder="Enter your password ....."
                />
                <button class="toggle-pw" type="button" onclick="togglePw('passwordInput')" tabindex="-1">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8a9ab5" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </div>
              <span class="field-error <?= isset($errors['password']) ? 'show' : '' ?>" id="passwordError">
                <?= isset($errors['password']) ? htmlspecialchars($errors['password']) : 'Please enter your password.' ?>
              </span>
            </div>

            <div class="form-buttons">
              <button class="btn-back" type="button" onclick="backToStep1()">Back</button>
              <button class="btn-submit" type="submit">Log in</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="right-panel"></div>
  </div>

  <footer>
    <div class="footer-top">
      <div style="display:flex;align-items:center;gap:10px;">
        <a class="social-icon" href="#">in</a>
        <a class="social-icon" href="#">&#120143;</a>
        <a class="social-icon" href="#">&#9834;</a>
      </div>
      <div class="footer-divider"></div>
      <div class="footer-brand">
        <img src="../../images/Replate-white.png" alt="Replate" style="height:50px;object-fit:contain;" />
      </div>
      <div class="footer-divider"></div>
      <div class="footer-email">
        <svg width="16" height="16" fill="none" stroke="rgba(255,255,255,0.85)" stroke-width="2" viewBox="0 0 24 24">
          <rect x="2" y="4" width="20" height="16" rx="2"/>
          <path d="M2 7l10 7 10-7"/>
        </svg>
        <a href="mailto:Replate@gmail.com">Replate@gmail.com</a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© 2026</span>
      <img src="../../images/Replate-white.png" alt="Replate" style="height:30px;object-fit:contain;opacity:1;" />
      <span>All rights reserved.</span>
    </div>
  </footer>

  <script>
    const phpHasErrors = <?= $hasErrors ? 'true' : 'false' ?>;
    const oldRole = "<?= htmlspecialchars($old['role']) ?>";

    if (phpHasErrors && oldRole) {
      showLoginForm(oldRole);
    }

    function showLoginForm(role) {
      const s1 = document.getElementById('step1');
      const s2 = document.getElementById('step2');
      const roleInput = document.getElementById('roleInput');
      const roleText = document.getElementById('roleText');

      roleInput.value = role;
      roleText.textContent = role.charAt(0).toUpperCase() + role.slice(1);

      s1.style.opacity = '0';
      setTimeout(() => {
        s1.style.display = 'none';
        s2.style.display = 'block';
        setTimeout(() => s2.style.opacity = '1', 10);
      }, 250);
    }

    function backToStep1() {
      const s1 = document.getElementById('step1');
      const s2 = document.getElementById('step2');

      s2.style.opacity = '0';
      setTimeout(() => {
        s2.style.display = 'none';
        s1.style.display = 'flex';
        setTimeout(() => s1.style.opacity = '1', 10);
      }, 250);
    }

    function togglePw(inputId) {
      const input = document.getElementById(inputId);
      input.type = input.type === 'password' ? 'text' : 'password';
    }

    document.getElementById('loginForm').addEventListener('submit', function(e) {
      const email = document.getElementById('emailInput');
      const password = document.getElementById('passwordInput');
      const role = document.getElementById('roleInput');
      let valid = true;

      email.classList.remove('error');
      password.classList.remove('error');
      document.getElementById('emailError').classList.remove('show');
      document.getElementById('passwordError').classList.remove('show');

      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

      if (!role.value) {
        valid = false;
        backToStep1();
      }

      if (!emailRegex.test(email.value.trim())) {
        email.classList.add('error');
        document.getElementById('emailError').textContent = 'Please enter a valid email address.';
        document.getElementById('emailError').classList.add('show');
        valid = false;
      }

      if (!password.value) {
        password.classList.add('error');
        document.getElementById('passwordError').textContent = 'Please enter your password.';
        document.getElementById('passwordError').classList.add('show');
        valid = false;
      }

      if (!valid) e.preventDefault();
    });
  </script>

</body>
</html>