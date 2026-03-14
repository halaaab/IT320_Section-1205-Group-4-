<?php
session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/PickupLocation.php';

$errors             = [];
$providerCategories = Provider::CATEGORIES;

$old = [
    'businessName'        => '',
    'businessDescription' => '',
    'category'            => '',
    'email'               => '',
    'password'            => '',
    'confirm'             => '',
    'phone'               => '',
    'street'              => '',
    'apt'                 => '',
    'city'                => '',
    'zip'                 => '',
    'lat'                 => '24.7136',
    'lng'                 => '46.6753',
    'locationMode'        => 'manual',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $old['businessName']        = trim($_POST['businessName'] ?? '');
    $old['businessDescription'] = trim($_POST['businessDescription'] ?? '');
    $old['category']            = trim($_POST['category'] ?? '');
    $old['email']               = trim($_POST['email'] ?? '');
    $old['password']            = $_POST['password'] ?? '';
    $old['confirm']             = $_POST['confirm'] ?? '';
    $old['phone']               = trim($_POST['phone'] ?? '');
    $old['street']              = trim($_POST['street'] ?? '');
    $old['apt']                 = trim($_POST['apt'] ?? '');
    $old['city']                = trim($_POST['city'] ?? '');
    $old['zip']                 = trim($_POST['zip'] ?? '');
    $old['lat']                 = trim($_POST['lat'] ?? '24.7136');
    $old['lng']                 = trim($_POST['lng'] ?? '46.6753');
    $old['locationMode']        = trim($_POST['locationMode'] ?? 'manual');

    if (!$old['businessName']) {
        $errors['businessName'] = 'Business name is required.';
    }

    if (!$old['businessDescription']) {
        $errors['businessDescription'] = 'Business description is required.';
    }

    if (!$old['category'] || !in_array($old['category'], Provider::CATEGORIES)) {
        $errors['category'] = 'Please select a valid category.';
    }

    if (
        !isset($_FILES['logo']) ||
        $_FILES['logo']['error'] === UPLOAD_ERR_NO_FILE
    ) {
        $errors['logo'] = 'Business logo is required.';
    }

    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Please enter a valid email.';
    }

    if (strlen($old['password']) < 8) {
        $errors['password'] = 'Password must be at least 8 characters.';
    }

    if ($old['password'] !== $old['confirm'] || !$old['confirm']) {
        $errors['confirm'] = 'Passwords do not match.';
    }

    if (!$old['phone']) {
        $errors['phone'] = 'Phone number is required.';
    }

    if ($old['locationMode'] === 'manual') {
        if (!$old['street']) {
            $errors['street'] = 'Address is required.';
        }

        if (!$old['city']) {
            $errors['city'] = 'City is required.';
        }
    }

    if ($old['locationMode'] === 'map') {
        if (!is_numeric($old['lat']) || !is_numeric($old['lng'])) {
            $errors['map'] = 'Please choose a valid location on the map.';
        }
    }

    if (empty($errors)) {
        try {
            $model = new Provider();

            if ($model->findByEmail($old['email'])) {
                $errors['email'] = 'This email is already registered.';
            } else {
                // ── Handle logo upload ──
                $logoUrl = '';
                if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../../uploads/logos/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    $ext      = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
                    $allowed  = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
                    if (in_array($ext, $allowed)) {
                        $filename = uniqid('logo_', true) . '.' . $ext;
                        if (move_uploaded_file($_FILES['logo']['tmp_name'], $uploadDir . $filename)) {
                            $logoUrl = '../../uploads/logos/' . $filename;
                        }
                    }
                }

                $providerId = $model->create([
    'businessName'        => $old['businessName'],
    'email'               => $old['email'],
    'password'            => $old['password'],
    'phoneNumber'         => $old['phone'],
    'businessDescription' => $old['businessDescription'],
    'category'            => $old['category'],
    'businessLogo'        => $logoUrl,
]);

$fullStreet = trim($old['street'] . ' ' . $old['apt']);
$cityValue  = $old['city'] ?: 'Riyadh';
$zipValue   = $old['zip'] ?: '';

// If the provider used the map, reverse-geocode the coordinates into a real address
if ($old['locationMode'] === 'map') {
    $lat = (float)$old['lat'];
    $lng = (float)$old['lng'];

    $geocodeUrl = "https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat={$lat}&lon={$lng}&accept-language=en";
    $ctx = stream_context_create(['http' => [
        'header'  => "User-Agent: RePlate/1.0 (replateapp@gmail.com)\r\n",
        'timeout' => 5,
    ]]);
    $json = @file_get_contents($geocodeUrl, false, $ctx);

    if ($json) {
        $geo = json_decode($json, true);
        $addr = $geo['address'] ?? [];

        // Build a street string from available components
        $road    = $addr['road'] ?? $addr['pedestrian'] ?? $addr['footway'] ?? '';
        $house   = $addr['house_number'] ?? '';
        $suburb  = $addr['suburb'] ?? $addr['neighbourhood'] ?? $addr['quarter'] ?? '';
        $parts   = array_filter([$house, $road, $suburb]);
        $fullStreet = $parts ? implode(', ', $parts) : ($geo['display_name'] ?? 'Map Selected Location');

        $cityValue = $addr['city'] ?? $addr['town'] ?? $addr['village'] ?? $addr['county'] ?? 'Riyadh';
        $zipValue  = $addr['postcode'] ?? '';
    }
}

(new PickupLocation())->create($providerId, [
    'label'     => 'Main Branch',
    'street'    => $fullStreet ?: 'Map Selected Location',
    'city'      => $cityValue,
    'zip'       => $zipValue,
    'lat'       => (float)$old['lat'],
    'lng'       => (float)$old['lng'],
    'isDefault' => true,
]);

$_SESSION['providerId'] = (string)$providerId;
$_SESSION['providerName'] = $old['businessName'];

header('Location: ../provider/provider-dashboard.php');
exit;
            }
        } catch (Throwable $e) {
            die('Provider signup error: ' . $e->getMessage());
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
  <title>RePlate – Provider Sign Up</title>

  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>

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
      padding: 48px 58px;
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
      background: transparent;
      padding: 0;
      margin-top: -20px;
    }

    .form-title {
      font-size: 42px;
      color: #1a3a6b;
      margin-bottom: 8px;
      font-weight: 700;
      text-align: center;
    }

    .form-subtitle {
      font-size: 18px;
      color: #5f78a0;
      margin-bottom: 6px;
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

    .step-note {
      font-size: 16px;
      color: #3a5a8a;
      font-style: italic;
      margin-bottom: 22px;
      text-align: center;
    }

    .provider-form {
      width: 100%;
      display: flex;
      flex-direction: column;
      gap: 18px;
    }

    .step {
      display: none;
      width: 100%;
    }

    .step.active {
      display: block;
    }

    .field-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-bottom: 12px;
    }

    .field-label {
      font-size: 15px;
      font-weight: 700;
      color: #1a3a6b;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
    }

    .required-mark {
      font-size: 12px;
      font-weight: 700;
      color: #2255a4;
      opacity: 0.95;
      white-space: nowrap;
    }

    .optional-mark {
      font-size: 12px;
      font-weight: 700;
      color: #7a8fa8;
      white-space: nowrap;
    }

    .field-input,
    .field-select,
    .field-textarea {
      width: 100%;
      padding: 12px 18px;
      border-radius: 50px;
      border: 1.5px solid #c8d8ee;
      background: rgba(255,255,255,0.85);
      font-size: 15px;
      font-family: 'Playfair Display', serif;
      color: #1a3a6b;
      outline: none;
      transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
    }

    .field-textarea {
      border-radius: 20px;
      min-height: 100px;
      resize: vertical;
    }

    .field-select {
      appearance: none;
      cursor: pointer;
    }

    .field-input::placeholder,
    .field-textarea::placeholder {
      color: #b0c4d8;
    }

    .field-input:focus,
    .field-select:focus,
    .field-textarea:focus {
      border-color: #2255a4;
      background: #fff;
      box-shadow: 0 0 0 3px rgba(34,85,164,0.1);
    }

    .field-input.error,
    .field-select.error,
    .field-textarea.error {
      border-color: #c0392b;
      background: rgba(255,248,248,0.9);
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

    .password-wrap {
      position: relative;
    }

    .password-wrap .field-input {
      padding-right: 48px;
    }

    .toggle-pw {
      position: absolute;
      right: 16px;
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

    .row-2 {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 14px;
    }

    .location-choice {
      display: flex;
      gap: 12px;
      justify-content: center;
      margin-bottom: 18px;
    }

    .location-mode-btn {
      padding: 10px 18px;
      border-radius: 50px;
      border: 1.5px solid #1a3a6b;
      background: transparent;
      color: #1a3a6b;
      font-size: 14px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .location-mode-btn.active {
      background: #1a3a6b;
      color: #fff;
    }

    .map-toolbar {
      display: flex;
      gap: 12px;
      margin-bottom: 12px;
      flex-wrap: wrap;
    }

    .map-action-btn {
      padding: 10px 16px;
      border-radius: 50px;
      border: none;
      background: #1a3a6b;
      color: #fff;
      font-size: 14px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      box-shadow: 0 4px 14px rgba(26,58,107,0.2);
    }

    .map-box {
      width: 100%;
      height: 170px;
      border-radius: 18px;
      overflow: hidden;
      border: 1.5px solid #c8d8ee;
      box-shadow: 0 4px 14px rgba(26,58,107,0.08);
    }

    #map {
      width: 100%;
      height: 100%;
    }

    .form-buttons {
      display: flex;
      gap: 16px;
      justify-content: center;
      margin-top: 12px;
    }

    .btn-next,
    .btn-back-step,
    .btn-submit {
      width: 170px;
      height: 50px;
      background: #1a3a6b;
      color: #fff;
      border: none;
      border-radius: 50px;
      font-size: 16px;
      font-weight: 700;
      font-family: 'Playfair Display', serif;
      cursor: pointer;
      box-shadow: 0 6px 20px rgba(26,58,107,0.25);
      transition: background 0.2s, transform 0.15s, box-shadow 0.2s;
    }

    .btn-next:hover,
    .btn-back-step:hover,
    .btn-submit:hover {
      background: #2255a4;
      transform: translateY(-2px);
      box-shadow: 0 10px 28px rgba(26,58,107,0.35);
    }

    .map-modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(12, 22, 45, 0.45);
      z-index: 9999;
      justify-content: center;
      align-items: center;
      padding: 20px;
    }

    .map-modal-content {
      width: 92vw;
      height: 86vh;
      background: #fff;
      border-radius: 24px;
      overflow: hidden;
      position: relative;
      box-shadow: 0 20px 60px rgba(0,0,0,0.2);
    }

    #fullscreenMap {
      width: 100%;
      height: 100%;
    }

    .close-map-btn {
      position: absolute;
      top: 16px;
      right: 16px;
      z-index: 10000;
      width: 42px;
      height: 42px;
      border-radius: 50%;
      border: none;
      background: #1a3a6b;
      color: #fff;
      font-size: 24px;
      cursor: pointer;
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
      text-decoration: none;
    }

    .footer-divider {
      width: 1px;
      height: 22px;
      background: rgba(255,255,255,0.3);
    }

    .footer-brand,
    .footer-email,
    .footer-bottom {
      color: rgba(255,255,255,0.9);
      font-size: 14px;
    }

    .footer-email a {
      color: rgba(255,255,255,0.9);
      text-decoration: none;
    }

    .footer-bottom {
      color: rgba(255,255,255,0.7);
      font-size: 13px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
  </style>
</head>
<body>

  <div class="page">
    <div class="left-panel">
      <a href="landing.php" class="back-btn">&#8249;</a>

      <div class="form-area">
        <h1 class="form-title">Create Your Account</h1>
        <p class="form-subtitle">Already have an account? <a href="login.php">log In</a></p>

        <form class="provider-form" method="POST" action="" id="providerForm" novalidate enctype="multipart/form-data">

          <div class="step active" id="step1">
            <p class="step-note">Tell us about your business.</p>

            <div class="field-group">
              <label class="field-label" for="businessName">
                <span>Business Name</span>
                <span class="required-mark">Required</span>
              </label>
              <input class="field-input <?= isset($errors['businessName']) ? 'error' : '' ?>"
                     id="businessName" name="businessName" type="text"
                     placeholder="Enter your business name ....."
                     value="<?= htmlspecialchars($old['businessName']) ?>">
              <span class="field-error <?= isset($errors['businessName']) ? 'show' : '' ?>" id="businessNameError"><?= $errors['businessName'] ?? '' ?></span>
            </div>

            <div class="field-group">
              <label class="field-label" for="businessDescription">
                <span>Business Description</span>
                <span class="required-mark">Required</span>
              </label>
              <textarea class="field-textarea <?= isset($errors['businessDescription']) ? 'error' : '' ?>"
                        id="businessDescription" name="businessDescription"
                        placeholder="Tell us about your business ....."><?= htmlspecialchars($old['businessDescription']) ?></textarea>
              <span class="field-error <?= isset($errors['businessDescription']) ? 'show' : '' ?>" id="businessDescriptionError"><?= $errors['businessDescription'] ?? '' ?></span>
            </div>

            <div class="field-group">
              <label class="field-label" for="category">
                <span>Business Category</span>
                <span class="required-mark">Required</span>
              </label>
              <select class="field-select <?= isset($errors['category']) ? 'error' : '' ?>" id="category" name="category">
                <option value="">Select a category</option>
                <?php foreach ($providerCategories as $cat): ?>
                  <option value="<?= htmlspecialchars($cat) ?>" <?= $old['category'] === $cat ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cat) ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <span class="field-error <?= isset($errors['category']) ? 'show' : '' ?>" id="categoryError"><?= $errors['category'] ?? '' ?></span>
            </div>

            <div class="field-group">
              <label class="field-label" for="logo">
                <span>Business Logo</span>
                <span class="required-mark">Required</span>
              </label>
              <input class="field-input <?= isset($errors['logo']) ? 'error' : '' ?>" id="logo" name="logo" type="file" accept="image/*">
              <span class="field-error <?= isset($errors['logo']) ? 'show' : '' ?>" id="logoError"><?= $errors['logo'] ?? '' ?></span>
            </div>

            <div class="form-buttons">
            <div class="form-buttons">
  <button type="button" class="btn-back-step" onclick="window.location.href='signup-customer.php'">
    Back
  </button>

  <button type="button" class="btn-next" onclick="validateStep1()">
    Next
  </button>
</div>
            </div>
          </div>

          <div class="step" id="step2">
            <p class="step-note">Set up your account.</p>

            <div class="field-group">
              <label class="field-label" for="email">
                <span>Email address</span>
                <span class="required-mark">Required</span>
              </label>
              <input class="field-input <?= isset($errors['email']) ? 'error' : '' ?>"
                     id="email" name="email" type="email"
                     placeholder="Enter your email ....."
                     value="<?= htmlspecialchars($old['email']) ?>">
              <span class="field-error <?= isset($errors['email']) ? 'show' : '' ?>" id="emailError"><?= $errors['email'] ?? '' ?></span>
            </div>

            <div class="field-group">
              <label class="field-label" for="phone">
                <span>Phone Number</span>
                <span class="required-mark">Required</span>
              </label>
              <input class="field-input <?= isset($errors['phone']) ? 'error' : '' ?>"
                     id="phone" name="phone" type="text"
                     placeholder="Enter your phone number ....."
                     value="<?= htmlspecialchars($old['phone']) ?>">
              <span class="field-error <?= isset($errors['phone']) ? 'show' : '' ?>" id="phoneError"><?= $errors['phone'] ?? '' ?></span>
            </div>

            <div class="field-group">
              <label class="field-label" for="password">
                <span>Password</span>
                <span class="required-mark">Required</span>
              </label>
              <div class="password-wrap">
                <input class="field-input <?= isset($errors['password']) ? 'error' : '' ?>"
                       id="password" name="password" type="password"
                       placeholder="Enter your password .....">
                <button class="toggle-pw" type="button" onclick="togglePw('password')" tabindex="-1">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8a9ab5" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </div>
              <span class="field-error <?= isset($errors['password']) ? 'show' : '' ?>" id="passwordError"><?= $errors['password'] ?? '' ?></span>
            </div>

            <div class="field-group">
              <label class="field-label" for="confirm">
                <span>Confirm your password</span>
                <span class="required-mark">Required</span>
              </label>
              <div class="password-wrap">
                <input class="field-input <?= isset($errors['confirm']) ? 'error' : '' ?>"
                       id="confirm" name="confirm" type="password"
                       placeholder="Confirm your password .....">
                <button class="toggle-pw" type="button" onclick="togglePw('confirm')" tabindex="-1">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#8a9ab5" stroke-width="2">
                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z"/>
                    <circle cx="12" cy="12" r="3"/>
                  </svg>
                </button>
              </div>
              <span class="field-error <?= isset($errors['confirm']) ? 'show' : '' ?>" id="confirmError"><?= $errors['confirm'] ?? '' ?></span>
            </div>

            <div class="form-buttons">
              <button type="button" class="btn-back-step" onclick="goToStep(1)">Back</button>
              <button type="button" class="btn-next" onclick="validateStep2()">Next</button>
            </div>
          </div>

          <div class="step" id="step3">
            <p class="step-note">Help customers find you easily.</p>

            <div class="location-choice">
              <button type="button" class="location-mode-btn active" id="manualBtn" onclick="setLocationMode('manual')">
                Fill manually
              </button>
              <button type="button" class="location-mode-btn" id="mapBtn" onclick="setLocationMode('map')">
                Choose on map
              </button>
            </div>

            <input type="hidden" id="locationMode" name="locationMode" value="<?= htmlspecialchars($old['locationMode']) ?>">

            <div id="manualLocationFields">
              <div class="field-group">
                <label class="field-label" for="street">
                  <span>Address</span>
                  <span class="required-mark">Required</span>
                </label>
                <input class="field-input <?= isset($errors['street']) ? 'error' : '' ?>"
                       id="street" name="street" type="text"
                       placeholder="Enter your address ....."
                       value="<?= htmlspecialchars($old['street']) ?>">
                <span class="field-error <?= isset($errors['street']) ? 'show' : '' ?>" id="streetError"><?= $errors['street'] ?? '' ?></span>
              </div>

              <div class="field-group">
                <label class="field-label" for="apt">
                  <span>Apt., suite, etc</span>
                  <span class="optional-mark">Optional</span>
                </label>
                <input class="field-input" id="apt" name="apt" type="text"
                       placeholder="Enter more details ....."
                       value="<?= htmlspecialchars($old['apt']) ?>">
              </div>

              <div class="row-2">
                <div class="field-group">
                  <label class="field-label" for="city">
                    <span>City</span>
                    <span class="required-mark">Required</span>
                  </label>
                  <input class="field-input <?= isset($errors['city']) ? 'error' : '' ?>"
                         id="city" name="city" type="text"
                         placeholder="Enter city ....."
                         value="<?= htmlspecialchars($old['city']) ?>">
                  <span class="field-error <?= isset($errors['city']) ? 'show' : '' ?>" id="cityError"><?= $errors['city'] ?? '' ?></span>
                </div>

                <div class="field-group">
                  <label class="field-label" for="zip">
                    <span>Zip code</span>
                    <span class="optional-mark">Optional</span>
                  </label>
                  <input class="field-input" id="zip" name="zip" type="text"
                         placeholder="Enter zip code ....."
                         value="<?= htmlspecialchars($old['zip']) ?>">
                </div>
              </div>
            </div>

            <div id="mapLocationFields" style="display:none;">
              <div class="map-toolbar">
                <button type="button" class="map-action-btn" onclick="openMapFullscreen()">Open full screen map</button>
              </div>

              <div class="map-box">
                <div id="map"></div>
              </div>

              <span class="field-error <?= isset($errors['map']) ? 'show' : '' ?>" id="mapError" style="padding-left:0; margin-top:8px;"><?= $errors['map'] ?? '' ?></span>
            </div>

            <input type="hidden" id="lat" name="lat" value="<?= htmlspecialchars($old['lat']) ?>">
            <input type="hidden" id="lng" name="lng" value="<?= htmlspecialchars($old['lng']) ?>">

            <div class="form-buttons">
              <button type="button" class="btn-back-step" onclick="goToStep(2)">Back</button>
              <button type="submit" class="btn-submit">Sign up</button>
            </div>
          </div>
        </form>
      </div>
    </div>

    <div class="right-panel"></div>
  </div>

  <div class="map-modal" id="mapModal">
    <div class="map-modal-content">
      <button type="button" class="close-map-btn" onclick="closeMapFullscreen()">×</button>
      <div id="fullscreenMap"></div>
    </div>
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
        <a href="mailto:Replate@gmail.com">Replate@gmail.com</a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>© 2026</span>
      <img src="../../images/Replate-white.png" alt="Replate" style="height:30px;object-fit:contain;opacity:1;" />
      <span>All rights reserved.</span>
    </div>
  </footer>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script>
    const phpHasErrors = <?= $hasErrors ? 'true' : 'false' ?>;
    const savedLocationMode = "<?= htmlspecialchars($old['locationMode']) ?>";

    function togglePw(inputId) {
      const input = document.getElementById(inputId);
      input.type = input.type === 'password' ? 'text' : 'password';
    }

    function goToStep(stepNumber) {
      document.querySelectorAll('.step').forEach(step => step.classList.remove('active'));
      document.getElementById('step' + stepNumber).classList.add('active');

      if (stepNumber === 3) {
        setTimeout(() => map.invalidateSize(), 200);
      }
    }

    function setLocationMode(mode) {
      const manual = document.getElementById('manualLocationFields');
      const mapOnly = document.getElementById('mapLocationFields');
      const manualBtn = document.getElementById('manualBtn');
      const mapBtn = document.getElementById('mapBtn');
      const hiddenMode = document.getElementById('locationMode');

      hiddenMode.value = mode;

      if (mode === 'manual') {
        manual.style.display = 'block';
        mapOnly.style.display = 'none';
        manualBtn.classList.add('active');
        mapBtn.classList.remove('active');
      } else {
        manual.style.display = 'none';
        mapOnly.style.display = 'block';
        manualBtn.classList.remove('active');
        mapBtn.classList.add('active');
        setTimeout(() => map.invalidateSize(), 200);
      }
    }

    function showFieldError(inputId, errorId, message) {
      const input = document.getElementById(inputId);
      const error = document.getElementById(errorId);

      if (input) input.classList.add('error');
      if (error) {
        error.textContent = message;
        error.classList.add('show');
      }
    }

    function clearFieldError(inputId, errorId) {
      const input = document.getElementById(inputId);
      const error = document.getElementById(errorId);

      if (input) input.classList.remove('error');
      if (error) error.classList.remove('show');
    }

    function validateStep1() {
      let valid = true;

      clearFieldError('businessName', 'businessNameError');
      clearFieldError('businessDescription', 'businessDescriptionError');
      clearFieldError('category', 'categoryError');
      clearFieldError('logo', 'logoError');

      const businessName = document.getElementById('businessName').value.trim();
      const businessDescription = document.getElementById('businessDescription').value.trim();
      const category = document.getElementById('category').value.trim();
      const logo = document.getElementById('logo').files.length;

      if (!businessName) {
        showFieldError('businessName', 'businessNameError', 'Business name is required.');
        valid = false;
      }

      if (!businessDescription) {
        showFieldError('businessDescription', 'businessDescriptionError', 'Business description is required.');
        valid = false;
      }

      if (!category) {
        showFieldError('category', 'categoryError', 'Please select a category.');
        valid = false;
      }

      if (!logo) {
        showFieldError('logo', 'logoError', 'Business logo is required.');
        valid = false;
      }

      if (valid) goToStep(2);
    }

    function validateStep2() {
      let valid = true;

      clearFieldError('email', 'emailError');
      clearFieldError('phone', 'phoneError');
      clearFieldError('password', 'passwordError');
      clearFieldError('confirm', 'confirmError');

      const email = document.getElementById('email').value.trim();
      const phone = document.getElementById('phone').value.trim();
      const password = document.getElementById('password').value;
      const confirm = document.getElementById('confirm').value;

      const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

      if (!emailRegex.test(email)) {
        showFieldError('email', 'emailError', 'Please enter a valid email.');
        valid = false;
      }

      if (!phone) {
        showFieldError('phone', 'phoneError', 'Phone number is required.');
        valid = false;
      }

      if (password.length < 8) {
        showFieldError('password', 'passwordError', 'Password must be at least 8 characters.');
        valid = false;
      }

      if (!confirm || confirm !== password) {
        showFieldError('confirm', 'confirmError', 'Passwords do not match.');
        valid = false;
      }

      if (valid) goToStep(3);
    }

    if (phpHasErrors) {
      if (
        document.querySelector('#street.error') ||
        document.querySelector('#city.error') ||
        document.querySelector('#mapError.show')
      ) {
        goToStep(3);
      } else if (
        document.querySelector('#email.error') ||
        document.querySelector('#password.error') ||
        document.querySelector('#confirm.error') ||
        document.querySelector('#phone.error')
      ) {
        goToStep(2);
      } else {
        goToStep(1);
      }
    }

    const startLat = parseFloat(document.getElementById('lat').value);
    const startLng = parseFloat(document.getElementById('lng').value);

    const map = L.map('map').setView([startLat, startLng], 12);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors'
    }).addTo(map);

    const marker = L.marker([startLat, startLng], { draggable: true }).addTo(map);

    function syncLatLng(lat, lng) {
      document.getElementById('lat').value = lat.toFixed(6);
      document.getElementById('lng').value = lng.toFixed(6);
    }

    marker.on('dragend', function () {
      const latlng = marker.getLatLng();
      syncLatLng(latlng.lat, latlng.lng);
      if (fullscreenMarker) fullscreenMarker.setLatLng(latlng);
    });

    map.on('click', function (e) {
      marker.setLatLng(e.latlng);
      syncLatLng(e.latlng.lat, e.latlng.lng);
      if (fullscreenMarker) fullscreenMarker.setLatLng(e.latlng);
    });

    let fullscreenMapInstance = null;
    let fullscreenMarker = null;

    function openMapFullscreen() {
      const modal = document.getElementById('mapModal');
      modal.style.display = 'flex';

      if (!fullscreenMapInstance) {
        fullscreenMapInstance = L.map('fullscreenMap').setView(marker.getLatLng(), 14);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
          attribution: '&copy; OpenStreetMap contributors'
        }).addTo(fullscreenMapInstance);

        fullscreenMarker = L.marker(marker.getLatLng(), { draggable: true }).addTo(fullscreenMapInstance);

        fullscreenMapInstance.on('click', function(e) {
          fullscreenMarker.setLatLng(e.latlng);
          marker.setLatLng(e.latlng);
          syncLatLng(e.latlng.lat, e.latlng.lng);
        });

        fullscreenMarker.on('dragend', function() {
          const latlng = fullscreenMarker.getLatLng();
          marker.setLatLng(latlng);
          syncLatLng(latlng.lat, latlng.lng);
        });
      } else {
        fullscreenMapInstance.setView(marker.getLatLng(), 14);
        fullscreenMarker.setLatLng(marker.getLatLng());
      }

      setTimeout(() => fullscreenMapInstance.invalidateSize(), 200);
    }

    function closeMapFullscreen() {
      document.getElementById('mapModal').style.display = 'none';
    }

    setLocationMode(savedLocationMode);
  </script>
</body>
</html>