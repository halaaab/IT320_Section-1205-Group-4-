<?php
// ================================================================
// search.php — Landing Page Live Search API
// Returns JSON: { items: [...], providers: [...] }
// GET ?q=query
// ================================================================

header('Content-Type: application/json');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/models/BaseModel.php';
require_once __DIR__ . '/models/Item.php';
require_once __DIR__ . '/models/Provider.php';

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode(['items' => [], 'providers' => []]);
    exit;
}

try {
    $escapedQ = preg_quote($q, '/');

    $itemsCol = Database::getInstance()->getCollection('items');

    $itemResults = $itemsCol->find([
        'itemName'    => ['$regex' => $escapedQ, '$options' => 'i'],
        'isAvailable' => true,
    ], [
        'limit'      => 6,
        'projection' => [
            'itemName' => 1,
            'price' => 1,
            'listingType' => 1,
            'photoUrl' => 1,
            'providerId' => 1
        ],
    ])->toArray();

    $items = [];
    foreach ($itemResults as $item) {
        $items[] = [
            'id'          => (string)$item['_id'],
            'name'        => $item['itemName'] ?? '',
            'price'       => (($item['listingType'] ?? '') === 'donate')
                ? 'Free'
                : number_format((float)($item['price'] ?? 0), 2) . ' SAR',
            'listingType' => $item['listingType'] ?? 'sell',
            'photoUrl'    => $item['photoUrl'] ?? '',
            'providerId'  => isset($item['providerId']) ? (string)$item['providerId'] : '',
        ];
    }

    $providersCol = Database::getInstance()->getCollection('providers');

    $provResults = $providersCol->find([
        'businessName' => ['$regex' => $escapedQ, '$options' => 'i'],
    ], [
        'limit'      => 4,
        'projection' => [
            'businessName' => 1,
            'category' => 1,
            'businessLogo' => 1
        ],
    ])->toArray();

    $providers = [];
    foreach ($provResults as $prov) {
        $providers[] = [
            'id'           => (string)$prov['_id'],
            'businessName' => $prov['businessName'] ?? '',
            'category'     => $prov['category'] ?? '',
            'businessLogo' => $prov['businessLogo'] ?? '',
        ];
    }

    echo json_encode([
        'items' => $items,
        'providers' => $providers
    ]);

} catch (Throwable $e) {
    echo json_encode([
        'items' => [],
        'providers' => [],
        'error' => $e->getMessage()
    ]);
}