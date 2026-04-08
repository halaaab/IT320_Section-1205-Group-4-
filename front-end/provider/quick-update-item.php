<?php
require_once '../../back-end/models/Item.php';
require_once '../../back-end/config/database.php';

session_start(); 

header('Content-Type: application/json');

if (empty($_SESSION['providerId'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$data = json_decode(file_get_contents("php://input"), true);

if (!$data || empty($data['id'])) {
    echo json_encode(['success' => false, 'message' => 'Missing item data']);
    exit;
}

$itemModel = new Item();

try {
    $listingType = $data['listingType'] ?? 'donate';

    $itemModel->updateQuick($data['id'], [
        'listingType'      => $listingType,
        'quantity'         => (int)($data['quantity'] ?? 0),
        'price'            => ($listingType === 'sell') ? (float)($data['price'] ?? 0) : 0,
        'expiryDate'       => $data['expiryDate'] ?? '',
        'pickupDate'       => $data['pickupDate'] ?? '',
        'pickupLocationId' => $data['pickupLocationId'] ?? ''
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}