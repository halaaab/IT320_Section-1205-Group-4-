<?php
// ================================================================
// singup-provider.php — Provider Registration
// ================================================================
// FORM FIELDS EXPECTED:
//   businessName, email, password, confirm, phone,
//   businessDescription, category, street, city, lat, lng
// ON SUCCESS:
//   $success = true  → redirect to login.php
// ON FAILURE:
//   $errors[field]   → error message per field
// AVAILABLE:
//   $providerCategories → list of valid categories for dropdown
// ================================================================

session_start();
require_once '../../back-end/config/database.php';
require_once '../../back-end/models/BaseModel.php';
require_once '../../back-end/models/Provider.php';
require_once '../../back-end/models/PickupLocation.php';

$errors             = [];
$success            = false;
$providerCategories = Provider::CATEGORIES; // ['Bakery','Coffee shop','Super Market','Restaurant']

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $businessName = trim($_POST['businessName'] ?? '');
    $email        = trim($_POST['email']        ?? '');
    $password     = trim($_POST['password']     ?? '');
    $confirm      = trim($_POST['confirm']      ?? '');
    $phone        = trim($_POST['phone']        ?? '');
    $category     = trim($_POST['category']     ?? '');
    $street       = trim($_POST['street']       ?? '');
    $city         = trim($_POST['city']         ?? '');

    if (!$businessName)                                    $errors['businessName'] = 'Business name is required.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))        $errors['email']        = 'Please enter a valid email.';
    if (strlen($password) < 8)                             $errors['password']     = 'Password must be at least 8 characters.';
    if ($password !== $confirm || !$confirm)               $errors['confirm']      = 'Passwords do not match.';
    if (!in_array($category, Provider::CATEGORIES))        $errors['category']     = 'Please select a valid category.';
    if (!$street || !$city)                                $errors['address']      = 'Address is required.';

    if (empty($errors)) {
        $model = new Provider();
        if ($model->findByEmail($email)) {
            $errors['email'] = 'This email is already registered.';
        } else {
            $providerId = $model->create([
                'businessName'        => $businessName,
                'email'               => $email,
                'password'            => $password,
                'phoneNumber'         => $phone,
                'businessDescription' => trim($_POST['businessDescription'] ?? ''),
                'category'            => $category,
            ]);
            // Save default pickup location from signup address
            (new PickupLocation())->create($providerId, [
                'label'     => 'Main Branch',
                'street'    => $street,
                'city'      => $city,
                'lat'       => (float) ($_POST['lat'] ?? 0),
                'lng'       => (float) ($_POST['lng'] ?? 0),
                'isDefault' => true,
            ]);
            $success = true;
        }
    }
}

// ── EXAMPLE: Category dropdown in your HTML ──
// <select name="category">
//   <?php foreach ($providerCategories as $cat): ?>
//     <option value="<?= $cat ?>" <?= ($_POST['category'] ?? '') === $cat ? 'selected' : '' ?>>
//       <?= $cat ?>
//     </option>
//   <?php endforeach; ?>
// </select>
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <title>RePlate – Provider Sign Up</title>
  <!-- YOUR HTML HERE -->
</head>
<body>
  <!-- YOUR HTML HERE -->
</body>
</html>
