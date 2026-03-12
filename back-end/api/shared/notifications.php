<?php
// back-end/api/shared/notifications.php
// Called by: any page that shows the notification bell
// GET  → get all notifications + unread count
// PUT  → mark all as read
// body for PUT: { notifId } (single) or {} (all)

require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../config/database.php';
loadModels();

$customerId  = requireCustomer();
$notifModel  = new Notification();
$method      = $_SERVER['REQUEST_METHOD'];

// ── GET ──
if ($method === 'GET') {
    $notifications = $notifModel->getByCustomer($customerId);
    $unreadCount   = $notifModel->getUnreadCount($customerId);

    foreach ($notifications as &$n) $n['_id'] = (string) $n['_id'];

    success(['notifications' => $notifications, 'unreadCount' => $unreadCount]);
}

// ── PUT mark as read ──
if ($method === 'PUT') {
    $body = getBody();
    if (!empty($body['notifId'])) {
        $notifModel->markRead($body['notifId']);
        success([], 'Marked as read.');
    }
    $notifModel->markAllRead($customerId);
    success([], 'All notifications marked as read.');
}

error('Method not allowed.', 405);
