<?php
// back-end/api/provider/provider-profile.php
// Called by: front-end/provider/provider-profile.html
// GET  → get profile + locations
// PUT  → update profile  body: { businessName, phoneNumber, businessDescription, businessLogo }

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

$providerId    = requireProvider();
$providerModel = new Provider();
$locationModel = new PickupLocation();
$method        = $_SERVER['REQUEST_METHOD'];

// ── GET profile ──
if ($method === 'GET') {
    $provider  = $providerModel->findById($providerId);
    $locations = $locationModel->getByProvider($providerId);

    unset($provider['passwordHash']);
    $provider['_id'] = (string) $provider['_id'];
    foreach ($locations as &$l) $l['_id'] = (string) $l['_id'];

    success(['provider' => $provider, 'locations' => $locations]);
}

// ── PUT update profile ──
if ($method === 'PUT') {
    $body   = getBody();
    $fields = [];

    if (!empty($body['businessName']))        $fields['businessName']        = trim($body['businessName']);
    if (!empty($body['phoneNumber']))         $fields['phoneNumber']         = trim($body['phoneNumber']);
    if (isset($body['businessDescription']))  $fields['businessDescription'] = trim($body['businessDescription']);
    if (!empty($body['businessLogo']))        $fields['businessLogo']        = trim($body['businessLogo']);

    if (empty($fields)) error('Nothing to update.');
    $providerModel->updateById($providerId, $fields);
    success([], 'Profile updated.');
}

error('Method not allowed.', 405);
