<?php
// ================================================================
// provider-search.php — Provider Dashboard Item Search
// Returns JSON: { items: [...] }
// GET ?q=query
// ================================================================

header('Content-Type: application/json');
session_start();

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['providerId'])) {
    echo json_encode(['items' => []]);
    exit;
}

$q = trim($_GET['q'] ?? '');
if (strlen($q) < 2) {
    echo json_encode(['items' => []]);
    exit;
}

try {
    $providerId = $_SESSION['providerId'];
    $escapedQ = preg_quote($q, '/');

    $itemsCol = Database::getInstance()->getCollection('items');

    $itemResults = $itemsCol->find([
        'providerId' => new MongoDB\BSON\ObjectId($providerId),
        'itemName'   => ['$regex' => $escapedQ, '$options' => 'i'],
    ], [
        'limit'      => 5,
        'projection' => [
            'itemName' => 1,
            'price' => 1,
            'listingType' => 1,
            'photoUrl' => 1,
            'isAvailable' => 1
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
            'available'   => (bool)($item['isAvailable'] ?? false),
        ];
    }

    echo json_encode(['items' => $items]);

} catch (Throwable $e) {
    echo json_encode([
        'items' => [],
        'error' => $e->getMessage()
    ]);
}