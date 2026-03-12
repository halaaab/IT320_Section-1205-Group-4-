<?php
// back-end/api/auth/signup-provider.php
// Called by: front-end/shared/singup-provider.html
// Method: POST
// Body: { businessName, email, phone, password, businessDescription, category, street, city, lat, lng }

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') error('Method not allowed.', 405);

$body = getBody();

$businessName = trim($body['businessName'] ?? '');
$email        = trim($body['email']        ?? '');
$phone        = trim($body['phone']        ?? '');
$password     = trim($body['password']     ?? '');
$category     = trim($body['category']     ?? '');
$street       = trim($body['street']       ?? '');
$city         = trim($body['city']         ?? '');

if (!$businessName)                              error('Business name is required.');
if (!filter_var($email, FILTER_VALIDATE_EMAIL))  error('Invalid email address.');
if (strlen($password) < 8)                      error('Password must be at least 8 characters.');
if (!in_array($category, Provider::CATEGORIES)) error('Invalid category.');
if (!$street || !$city)                         error('Address is required.');

$providerModel = new Provider();
if ($providerModel->findByEmail($email))         error('This email is already registered.');

// 1. Create provider
$providerId = $providerModel->create([
    'businessName'        => $businessName,
    'email'               => $email,
    'phoneNumber'         => $phone,
    'password'            => $password,
    'businessDescription' => $body['businessDescription'] ?? '',
    'category'            => $category,
]);

// 2. Save default pickup location (from signup address)
$locationModel = new PickupLocation();
$locationModel->create($providerId, [
    'label'     => 'Main Branch',
    'street'    => $street,
    'city'      => $city,
    'lat'       => $body['lat'] ?? 0,
    'lng'       => $body['lng'] ?? 0,
    'isDefault' => true,
]);

success(['providerId' => $providerId], 'Provider account created successfully!');
