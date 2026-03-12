<?php
// back-end/api/customer/providers.php
// Called by: front-end/customer/providers-list.html  → GET (all providers)
//            front-end/customer/providers-page.html  → GET ?providerId=xxx (single provider + items)
// Method: GET

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') error('Method not allowed.', 405);

$providerModel = new Provider();
$itemModel     = new Item();

// ── Single provider page ──
if (!empty($_GET['providerId'])) {
    $providerId = $_GET['providerId'];
    $type       = $_GET['type'] ?? 'all'; // all | donate | sell

    $provider = $providerModel->findById($providerId);
    if (!$provider) error('Provider not found.', 404);

    $items = $itemModel->getByProvider($providerId, ['isAvailable' => true]);
    if ($type !== 'all') {
        $items = array_filter($items, fn($i) => $i['listingType'] === $type);
        $items = array_values($items);
    }

    foreach ($items as &$item) {
        $item['_id'] = (string) $item['_id'];
    }

    $provider['_id'] = (string) $provider['_id'];
    unset($provider['passwordHash']); // never expose password

    success(['provider' => $provider, 'items' => $items]);

// ── All providers list ──
} else {
    $providers = $providerModel->findAll([], ['sort' => ['businessName' => 1]]);
    foreach ($providers as &$p) {
        $p['_id'] = (string) $p['_id'];
        unset($p['passwordHash']);
    }
    success(['providers' => $providers]);
}
