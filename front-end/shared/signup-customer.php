<?php
// ================================================================
// signup-customer.php — Customer Registration
// ================================================================
// FORM FIELDS EXPECTED:
//   name, email, password, confirm
// ON SUCCESS:
//   redirect to login.php?registered=1
// ON FAILURE:
//   $errors[field]   → error message per field
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Customer.php';

$errors  = [];
$success = false;
$old     = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $password =      $_POST['password'] ?? '';
    $confirm  =      $_POST['confirm']  ?? '';

    $old['name']  = htmlspecialchars($name);
    $old['email'] = htmlspecialchars($email);

    if (!$name)                                         $errors['name']     = 'Please enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))     $errors['email']    = 'Please enter a valid email address.';
    if (strlen($password) < 8)                          $errors['password'] = 'Password must be at least 8 characters.';
    if ($password !== $confirm || !$confirm)            $errors['confirm']  = 'Passwords do not match.';

    if (empty($errors)) {
      $model = new Customer();
if (empty($errors)) {
    try {
        $model = new Customer();

        if ($model->findByEmail($email)) {
            $errors['email'] = 'This email is already registered.';
        } else {
            $newId = $model->create([
                'fullName'    => $name,
                'email'       => $email,
                'password'    => $password,
                'phoneNumber' => '',
            ]);

            echo "Created user successfully: " . $newId;
            exit;
        }
    } catch (Throwable $e) {
        die('Signup error: ' . $e->getMessage());
    }
} else {
    $model->create([
        'fullName'    => $name,
        'email'       => $email,
        'password'    => $password,
        'phoneNumber' => '',
    ]);
    header('Location: login.php?registered=1');
    exit;
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
  <title>RePlate – Sign Up</title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,700;1,700&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet"/>

  <style>
    * { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'Playfair Display', serif;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      background-image: url('../../images/signup banner.png');
      background-size: cover;
      background-position: top center;
      background-repeat: no-repeat;
      background-attachment: scroll;
    }

    /* ── PAGE LAYOUT ── */
    .page {
      flex: 1;
      display: grid;
      grid-template-columns: 1fr 1fr;
      min-height: calc(100vh - 80px);
    }

    /* ── LEFT PANEL ── */
    .left-panel {
      display: flex;
      flex-direction: column;
      padding: 48px 64px;
      background: transparent;
    }

    .back-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      border: 2px solid #c8d8ee;
      background: rgba(255,255,255,0.7);
      cursor: pointer;
      color: #1a3a6b;
      font-size: 22px;
      text-decoration: none;
      transition: background 0.2s, border-color 0.2s;
      margin-bottom: 40px;
      flex-shrink: 0;
      line-height: 1;
    }
    .back-btn:hover { background: #fff; border-color: #1a3a6b; }

    .form-area {
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: center;
      max-width: 460px;
      background: rgba(255,255,255,0.88);
      border-radius: 24px;
      padding: 40px 44px;
      backdrop-filter: blur(6px);
      box-shadow: 0 8px 40px rgba(26,58,107,0.10);
    }

    .form-title {
      font-family: 'Playfair Display', serif;
      font-size: 32px;
      color: #1a3a6b;
      margin-bottom: 6px;
      font-weight: 700;
    }

    .form-subtitle {
      font-size: 14px;
      color: #7a8fa8;
      margin-bottom: 36px;
      font-family: 'Playfair Display', serif;
    }
    .form-subtitle a {
      color: #2255a4;
      text-decoration: none;
      font-weight: 700;
    }
    .form-subtitle a:hover { text-decoration: underline; }

    /* ── ROLE SELECTOR (step 1) ── */
    .role-selector { display: flex; gap: 16px; margin-top: 8px; }

    .role-btn {
      flex: 1;
      padding: 14px 20px;
      border-radius: 50px;
      border: 2px solid #1a3a6b;
      background: #1a3a6b;
      color: #fff;
      font-size: 16px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
      box-shadow: 0 4px 16px rgba(26,58,107,0.2);
    }
    .role-btn:hover {
      background: #2255a4;
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(26,58,107,0.3);
    }

    /* ── FORM FIELDS (step 2) ── */
    .signup-form { display: flex; flex-direction: column; gap: 20px; }

    .field-group { display: flex; flex-direction: column; gap: 6px; }

    .field-label {
      font-size: 14px;
      font-weight: 700;
      color: #1a3a6b;
      font-family: 'Playfair Display', serif;
    }

    .field-input {
      padding: 14px 20px;
      border-radius: 50px;
      border: 1.5px solid #c8d8ee;
      background: rgba(255,255,255,0.85);
      font-size: 14px;
      font-family: 'Playfair Display', serif;
      color: #1a3a6b;
      outline: none;
      transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
      width: 100%;
    }
    .field-input::placeholder { color: #b0c4d8; }
    .field-input:focus {
      border-color: #2255a4;
      background: #fff;
      box-shadow: 0 0 0 3px rgba(34,85,164,0.1);
    }
    .field-input.error { border-color: #c0392b; background: rgba(255,248,248,0.9); }

    .password-wrap { position: relative; }
    .password-wrap .field-input { padding-right: 50px; }
    .toggle-pw {
      position: absolute;
      right: 18px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      color: #8a9ab5;
      font-size: 16px;
      padding: 0;
      line-height: 1;
      transition: color 0.2s;
    }
    .toggle-pw:hover { color: #1a3a6b; }

    .btn-submit {
      background: #1a3a6b;
      color: #fff;
      border: none;
      border-radius: 50px;
      padding: 15px 0;
      font-size: 16px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      width: 55%;
      align-self: center;
      box-shadow: 0 6px 20px rgba(26,58,107,0.25);
      transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
      margin-top: 6px;
    }
    .btn-submit:hover {
      background: #2255a4;
      transform: translateY(-2px);
      box-shadow: 0 10px 28px rgba(26,58,107,0.35);
    }

    .field-error {
      font-size: 12px;
      color: #c0392b;
      margin-top: 2px;
      padding-left: 8px;
      font-family: 'Playfair Display', serif;
      display: none;
    }
    .field-error.show { display: block; }

    /* ── RIGHT PANEL ── */
    .right-panel { /* background image shows through */ }

    /* ── STEP TRANSITION ── */
    #step1, #step2 { transition: opacity 0.3s; }
    #step2 { display: none; opacity: 0; }

    /* ── FOOTER ── */
    footer {
      background: linear-gradient(90deg, #1a3a6b 0%, #2255a4 60%, #3a7bd5 100%);
      padding: 28px 48px;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 14px;
    }
    .footer-top { display: flex; align-items: center; gap: 18px; }
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
    .social-icon:hover { background: rgba(255,255,255,0.15); }
    .footer-divider { width: 1px; height: 22px; background: rgba(255,255,255,0.3); }
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
    .footer-email a { color: rgba(255,255,255,0.9); text-decoration: none; }
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

    <!-- LEFT PANEL -->
    <div class="left-panel">
      <a href="landing.php" class="back-btn">&#8249;</a>

      <div class="form-area">

        <!-- STEP 1: Choose role (hidden when PHP returned errors) -->
        <div id="step1">
          <h1 class="form-title">Create Your Account</h1>
          <p class="form-subtitle">Already have an account? <a href="login.php">log In</a></p>
          <div class="role-selector">
            <button class="role-btn" onclick="showForm()">Customer</button>
            <button class="role-btn" onclick="window.location.href='singup-provider.php'">Provider</button>
          </div>
        </div>

        <!-- STEP 2: Customer form -->
        <div id="step2">
          <h1 class="form-title">Create Your Account</h1>
          <p class="form-subtitle">Already have an account? <a href="login.php">log In</a></p>

          <form class="signup-form" method="POST" action="" id="signupForm" novalidate>

            <!-- Name -->
            <div class="field-group">
              <label class="field-label" for="nameInput">Name</label>
              <input
                class="field-input <?= isset($errors['name']) ? 'error' : '' ?>"
                id="nameInput"
                name="name"
                type="text"
                placeholder="Enter your name ...."
                value="<?= $old['name'] ?>"
              />
              <span class="field-error <?= isset($errors['name']) ? 'show' : '' ?>" id="nameError">
                <?= isset($errors['name']) ? htmlspecialchars($errors['name']) : 'Please enter your name.' ?>
              </span>
            </div>

            <!-- Email -->
            <div class="field-group">
              <label class="field-label" for="emailInput">Email address</label>
              <input
                class="field-input <?= isset($errors['email']) ? 'error' : '' ?>"
                id="emailInput"
                name="email"
                type="email"
                placeholder="Enter your email ...."
                value="<?= $old['email'] ?>"
              />
              <span class="field-error <?= isset($errors['email']) ? 'show' : '' ?>" id="emailError">
                <?= isset($errors['email']) ? htmlspecialchars($errors['email']) : 'Please enter a valid email.' ?>
              </span>
            </div>

            <!-- Password -->
            <div class="field-group">
              <label class="field-label" for="passwordInput">Password</label>
              <div class="password-wrap">
                <input
                  class="field-input <?= isset($errors['password']) ? 'error' : '' ?>"
                  id="passwordInput"
                  name="password"
                  type="password"
                  placeholder="Enter your password ...."
                />
                <button class="toggle-pw" type="button" onclick="togglePw('passwordInput', this)" tabindex="-1">👁</button>
              </div>
              <span class="field-error <?= isset($errors['password']) ? 'show' : '' ?>" id="passwordError">
                <?= isset($errors['password']) ? htmlspecialchars($errors['password']) : 'Password must be at least 8 characters.' ?>
              </span>
            </div>

            <!-- Confirm Password -->
            <div class="field-group">
              <label class="field-label" for="confirmInput">Confirm your password</label>
              <div class="password-wrap">
                <input
                  class="field-input <?= isset($errors['confirm']) ? 'error' : '' ?>"
                  id="confirmInput"
                  name="confirm"
                  type="password"
                  placeholder="Enter your password ...."
                />
                <button class="toggle-pw" type="button" onclick="togglePw('confirmInput', this)" tabindex="-1">👁</button>
              </div>
              <span class="field-error <?= isset($errors['confirm']) ? 'show' : '' ?>" id="confirmError">
                <?= isset($errors['confirm']) ? htmlspecialchars($errors['confirm']) : 'Passwords do not match.' ?>
              </span>
            </div>

            <button class="btn-submit" type="submit">Sign up</button>

          </form>
        </div>

      </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="right-panel"></div>

  </div>

  <!-- FOOTER -->
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
    // ── If PHP returned errors, skip Step 1 and show Step 2 immediately ──
    const phpHasErrors = <?= $hasErrors ? 'true' : 'false' ?>;

    if (phpHasErrors) {
      document.getElementById('step1').style.display = 'none';
      document.getElementById('step2').style.display = 'block';
      document.getElementById('step2').style.opacity  = '1';
    }

    // ── Animated transition: Customer button → step 2 ─────────────────────
    function showForm() {
      const s1 = document.getElementById('step1');
      const s2 = document.getElementById('step2');
      s1.style.opacity = '0';
      setTimeout(() => {
        s1.style.display = 'none';
        s2.style.display = 'block';
        setTimeout(() => s2.style.opacity = '1', 10);
      }, 250);
    }

    // ── Password visibility toggle ────────────────────────────────────────
    function togglePw(inputId, btn) {
      const input = document.getElementById(inputId);
      if (input.type === 'password') {
        input.type = 'text';
        btn.textContent = '🙈';
      } else {
        input.type = 'password';
        btn.textContent = '👁';
      }
    }

    // ── Client-side validation (fires before PHP sees the POST) ───────────
    document.getElementById('signupForm').addEventListener('submit', function(e) {
      const name     = document.getElementById('nameInput');
      const email    = document.getElementById('emailInput');
      const password = document.getElementById('passwordInput');
      const confirm  = document.getElementById('confirmInput');
      let valid = true;

      // Reset previous JS-triggered states (PHP errors already rendered via class)
      [name, email, password, confirm].forEach(i => i.classList.remove('error'));
      ['nameError','emailError','passwordError','confirmError'].forEach(id => {
        document.getElementById(id).classList.remove('show');
      });

      if (!name.value.trim()) {
        name.classList.add('error');
        document.getElementById('nameError').textContent = 'Please enter your name.';
        document.getElementById('nameError').classList.add('show');
        valid = false;
      }

      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      if (!emailRegex.test(email.value.trim())) {
        email.classList.add('error');
        document.getElementById('emailError').textContent = 'Please enter a valid email address.';
        document.getElementById('emailError').classList.add('show');
        valid = false;
      }

      if (password.value.length < 8) {
        password.classList.add('error');
        document.getElementById('passwordError').textContent = 'Password must be at least 8 characters.';
        document.getElementById('passwordError').classList.add('show');
        valid = false;
      }

      if (!confirm.value || confirm.value !== password.value) {
        confirm.classList.add('error');
        document.getElementById('confirmError').textContent = 'Passwords do not match.';
        document.getElementById('confirmError').classList.add('show');
        valid = false;
      }

      if (!valid) e.preventDefault();
    });
  </script>

</body>
</html>