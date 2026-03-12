<?php
// back-end/api/customer/customer-profile.php
// Called by: front-end/customer/customer-profile.html
// GET  → get profile
// PUT  → update profile  body: { fullName, phoneNumber }

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

$customerId    = requireCustomer();
$customerModel = new Customer();
$method        = $_SERVER['REQUEST_METHOD'];

// ── GET profile ──
if ($method === 'GET') {
    $customer = $customerModel->findById($customerId);
    if (!$customer) error('Customer not found.', 404);

    unset($customer['passwordHash']);
    $customer['_id'] = (string) $customer['_id'];

    success(['customer' => $customer]);
}

// ── PUT update profile ──
if ($method === 'PUT') {
    $body   = getBody();
    $fields = [];

    if (!empty($body['fullName']))    $fields['fullName']    = trim($body['fullName']);
    if (!empty($body['phoneNumber'])) $fields['phoneNumber'] = trim($body['phoneNumber']);

    if (empty($fields)) error('Nothing to update.');

    $customerModel->updateById($customerId, $fields);
    success([], 'Profile updated.');
}

error('Method not allowed.', 405);
