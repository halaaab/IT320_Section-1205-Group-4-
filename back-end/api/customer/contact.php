<?php
// back-end/api/customer/contact.php
// Called by: front-end/customer/contact.html
// GET  → get customer's tickets
// POST → submit new ticket  body: { reason, description }

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

$customerId   = requireCustomer();
$ticketModel  = new SupportTicket();
$method       = $_SERVER['REQUEST_METHOD'];

// ── GET all tickets for customer ──
if ($method === 'GET') {
    $tickets = $ticketModel->getByCustomer($customerId);
    foreach ($tickets as &$t) $t['_id'] = (string) $t['_id'];
    success(['tickets' => $tickets]);
}

// ── POST submit ticket ──
if ($method === 'POST') {
    $body   = getBody();
    $reason = trim($body['reason']      ?? '');
    $desc   = trim($body['description'] ?? '');

    if (!in_array($reason, SupportTicket::REASONS)) error('Invalid reason.');
    if (!$desc) error('Description is required.');

    $id = $ticketModel->create($customerId, [
        'reason'      => $reason,
        'description' => $desc,
    ]);

    success(['ticketId' => $id], 'Support ticket submitted.');
}

error('Method not allowed.', 405);
