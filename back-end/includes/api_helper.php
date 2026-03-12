<?php
// back-end/includes/api_helper.php
// ─────────────────────────────────────────────
// Shared helpers used by every API endpoint
// ─────────────────────────────────────────────

// ── Always return JSON ──
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// ── Send a JSON success response and exit ──
function success(array $data = [], string $message = 'OK'): void {
    echo json_encode(['success' => true, 'message' => $message, 'data' => $data]);
    exit;
}

// ── Send a JSON error response and exit ──
function error(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode(['success' => false, 'message' => $message]);
    exit;
}

// ── Parse JSON body from fetch() ──
function getBody(): array {
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

// ── Require a logged-in customer (checks session) ──
function requireCustomer(): string {
    session_start();
    if (empty($_SESSION['customerId'])) {
        error('Unauthorized. Please log in.', 401);
    }
    return $_SESSION['customerId'];
}

// ── Require a logged-in provider (checks session) ──
function requireProvider(): string {
    session_start();
    if (empty($_SESSION['providerId'])) {
        error('Unauthorized. Please log in.', 401);
    }
    return $_SESSION['providerId'];
}

// ── Load all models ──
function loadModels(): void {
    $base = __DIR__ . '/../models/';
    require_once $base . 'BaseModel.php';
    require_once $base . 'Customer.php';
    require_once $base . 'Provider.php';
    require_once $base . 'Category.php';
    require_once $base . 'PickupLocation.php';
    require_once $base . 'Item.php';
    require_once $base . 'Favourite.php';
    require_once $base . 'Cart.php';
    require_once $base . 'Order.php';
    require_once $base . 'OrderItem.php';
    require_once $base . 'Notification.php';
    require_once $base . 'SupportTicket.php';
}
