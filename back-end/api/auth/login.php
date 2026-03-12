<?php
// back-end/api/auth/login.php
// Called by: front-end/shared/login.html
// Method: POST
// Body: { email, password, role }  role = "customer" | "provider"

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('Method not allowed.', 405);

session_start();

$body     = getBody();
$email    = trim($body['email']    ?? '');
$password = trim($body['password'] ?? '');
$role     = trim($body['role']     ?? 'customer');

if (!$email || !$password) error('Email and password are required.');

if ($role === 'customer') {
    $model = new Customer();
    $user  = $model->findByEmail($email);
    if (!$user || !$model->verifyPassword($password, $user['passwordHash'])) {
        error('Invalid email or password.', 401);
    }
    // Save session
    $_SESSION['customerId'] = (string) $user['_id'];
    $_SESSION['role']       = 'customer';

    success([
        'customerId' => (string) $user['_id'],
        'fullName'   => $user['fullName'],
        'role'       => 'customer',
    ], 'Login successful!');

} elseif ($role === 'provider') {
    $model = new Provider();
    $user  = $model->findByEmail($email);
    if (!$user || !$model->verifyPassword($password, $user['passwordHash'])) {
        error('Invalid email or password.', 401);
    }
    $_SESSION['providerId'] = (string) $user['_id'];
    $_SESSION['role']       = 'provider';

    success([
        'providerId'   => (string) $user['_id'],
        'businessName' => $user['businessName'],
        'role'         => 'provider',
    ], 'Login successful!');

} else {
    error('Invalid role.');
}
