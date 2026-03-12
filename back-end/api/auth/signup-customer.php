<?php
// back-end/api/auth/signup-customer.php
// Called by: front-end/shared/signup-customer.html
// Method: POST
// Body: { name, email, phone, password }

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('Method not allowed.', 405);

$body = getBody();

$name     = trim($body['name']     ?? '');
$email    = trim($body['email']    ?? '');
$phone    = trim($body['phone']    ?? '');
$password = trim($body['password'] ?? '');

if (!$name)                               error('Full name is required.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) error('Invalid email address.');
if (strlen($password) < 8)               error('Password must be at least 8 characters.');

$model = new Customer();
if ($model->findByEmail($email))          error('This email is already registered.');

$id = $model->create([
    'fullName'    => $name,
    'email'       => $email,
    'phoneNumber' => $phone,
    'password'    => $password,
]);

success(['customerId' => $id], 'Account created successfully!');
