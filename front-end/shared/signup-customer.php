<?php
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Customer.php';

$errors = [];
$old    = ['name' => '', 'email' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm'] ?? '';

    $old['name']  = htmlspecialchars($name);
    $old['email'] = htmlspecialchars($email);

    if (!$name) {
        $errors['name'] = 'Please enter your name.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email address.';
    }

    if (strlen($password) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }

    if ($password !== $confirm || !$confirm) {
        $errors['confirm'] = 'Passwords do not match.';
    }

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

                $_SESSION['customerId'] = (string)$newId;
                $_SESSION['userName']   = $name;

                header('Location: landing.php');
                exit;
            }
        } catch (Throwable $e) {
            die('Signup error: ' . $e->getMessage());
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

    /* ── LEFT PANEL ── */
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
    .back-btn:hover { background: #fff; border-color: #1a3a6b; }

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
  font-family: 'Playfair Display', serif;
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
  font-family: 'Playfair Display', serif;
  text-align: center;
}
    .form-subtitle a {
      color: #2255a4;
      text-decoration: none;
      font-weight: 700;
    }
    .form-subtitle a:hover { text-decoration: underline; }

    /* ── ROLE SELECTOR (step 1) ── */
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

    /* ── FORM FIELDS (step 2) ── */
   .signup-form {
  display: flex;
  flex-direction: column;
  gap: 24px;
}

    .field-group { display: flex; flex-direction: column; gap: 6px; }

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
  padding: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

    .toggle-pw:hover { color: #1a3a6b; }

    


.form-buttons {
  display: flex;
  gap: 16px;
  justify-content: center;
  margin-top: 10px;
}

.btn-submit,
.btn-back {
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

.btn-submit:hover,
.btn-back:hover {
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

  

    /* ── STEP TRANSITION ── */
#step1 {
  display: flex;
  flex-direction: column;
  align-items: center;
  text-align: center;
  width: 100%;
}
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
    @media (max-width: 768px) {

  .page {
    display: flex;
    flex-direction: column;
    min-height: auto;
    background-image: none;
  }

  .right-panel {
    display: none;
  }


  /* Keep panel normal */
  .left-panel {
    justify-content: flex-start;
    align-items: center;
    padding-top: 150px;
  }

  /* 🔥 Center ONLY step 1 */
  #step1 {
    min-height: 60vh;
    display: flex;
    justify-content: center;
    align-items: center;
  }

  /* 🔥 Keep form higher */
  #step2 .form-area {
    margin-top: 30px;
  }



  .form-title {
    font-size: 24px;
    margin-bottom: 10px;
  }

  .form-subtitle {
    font-size: 16px;
    margin-bottom: 22px;
  }

  .signup-form {
    gap: 20px;
    width: 100%;
  }

  .field-label {
    font-size: 16px;
    margin-bottom: 4px;
  }

  .field-input {
    width: 100%;
    padding: 10px 26px;
    font-size: 18px;
    border-radius: 999px;
  }
.form-area {
  max-width: 300%;
  padding: 0 0px; /* small side spacing */
}

  .password-wrap .field-input {
    padding-right: 58px;
  }

  .toggle-pw {
    right: 20px;
  }

  .form-buttons {
    display: flex;
    flex-direction: row;
    gap: 12px;
    width: 100%;
    margin-top: 6px;
  }

  .btn-back,
  .btn-submit {
    width: 50%;
    min-width: 0;
    padding: 15px 20px;
    font-size: 16px;
  }
footer {
  padding: 20px 16px;
}

.footer-top {
  flex-wrap: wrap;
  justify-content: center;
  gap: 10px;
}

.footer-divider {
  display: none;
}

.footer-bottom {
  font-size: 11px;
  flex-wrap: wrap;
  justify-content: center;
  gap: 4px;
}

.footer-bottom img {
  height: 24px !important;
}

.social-icon {
  width: 36px;
  height: 36px;
  font-size: 13px;
}
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
 <button class="role-btn" onclick="window.location.href='signup-provider.php'">Provider</button>
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
                <button class="toggle-pw" type="button" onclick="togglePw('passwordInput')"  tabindex="-1">
<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8a9ab5" stroke-width="2">
<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/>
<circle cx="12" cy="12" r="3"/>
</svg>
</button>
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
                <button class="toggle-pw" type="button" onclick="togglePw('confirmInput')" tabindex="-1">
  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8a9ab5" stroke-width="2">
    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/>
    <circle cx="12" cy="12" r="3"/>
  </svg>
</button>
              </div>
              <span class="field-error <?= isset($errors['confirm']) ? 'show' : '' ?>" id="confirmError">
                <?= isset($errors['confirm']) ? htmlspecialchars($errors['confirm']) : 'Passwords do not match.' ?>
              </span>
            </div>

            <div class="form-buttons">
  <button class="btn-back" type="button" onclick="backToStep1()">
    Back
  </button>

  <button class="btn-submit" type="submit">
    Sign up
  </button>
</div>

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
    // ── Password visibility toggle ────────────────────────────────────────
  function togglePw(inputId) {
  const input = document.getElementById(inputId);

  if (input.type === 'password') {
    input.type = 'text';
  } else {
    input.type = 'password';
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