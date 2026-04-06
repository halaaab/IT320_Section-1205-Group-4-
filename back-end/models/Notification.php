<?php
// models/Notification.php
require_once __DIR__ . '/BaseModel.php';

class Notification extends BaseModel {
    protected string $collectionName = 'notifications';

    const TYPES = [
        'expiry_alert',
        'pickup_reminder',
        'order_placed',
        'order_cancelled',
        'order_completed',
    ];

    // ── Create a notification for a customer ──
    public function create(string $customerId, string $type, string $message, array $refs = []): string {
        $doc = [
            'customerId'     => self::toObjectId($customerId),
            'type'           => $type,
            'message'        => $message,
            'isRead'         => false,
            'relatedOrderId' => isset($refs['orderId'])
                                  ? self::toObjectId($refs['orderId'])
                                  : null,
            'relatedItemId'  => isset($refs['itemId'])
                                  ? self::toObjectId($refs['itemId'])
                                  : null,
        ];
        return $this->insertOne($doc);
    }

    // ── Get all notifications for a customer (newest first) ──
    public function getByCustomer(string $customerId): array {
        return $this->findAll(
            ['customerId' => self::toObjectId($customerId)],
            ['sort' => ['createdAt' => -1]]
        );
    }

    // ── Get unread count ──
    public function getUnreadCount(string $customerId): int {
        return $this->collection->countDocuments([
            'customerId' => self::toObjectId($customerId),
            'isRead'     => false,
        ]);
    }

    // ── Mark one notification as read ──
    public function markRead(string $notifId): bool {
        return $this->updateById($notifId, ['isRead' => true]);
    }

    // ── Mark all as read for a customer ──
    public function markAllRead(string $customerId): void {
        $this->collection->updateMany(
            ['customerId' => self::toObjectId($customerId), 'isRead' => false],
            ['$set' => ['isRead' => true, 'updatedAt' => new MongoDB\BSON\UTCDateTime()]]
        );
    }

    // ── Helper: send order_placed notification ──
    public function notifyOrderPlaced(string $customerId, string $orderId): string {
        return $this->create($customerId, 'order_placed',
            'Your order has been placed successfully!',
            ['orderId' => $orderId]
        );
    }

    // ── Helper: send expiry_alert notification ──
    public function notifyExpiryAlert(string $customerId, string $itemId, string $itemName): string {
        return $this->create($customerId, 'expiry_alert',
            "\"$itemName\" in your favourites is expiring soon!",
            ['itemId' => $itemId]
        );
    }

    // ── Helper: send pickup_reminder notification (fires at checkout, same-day) ──
    public function notifyPickupReminder(string $customerId, string $orderId, string $orderNumber, string $pickupTime, string $pickupLocation): string {
        return $this->create($customerId, 'pickup_reminder',
            "📍 [pickup] Today is pickup day for order $orderNumber! $pickupTime · $pickupLocation",
            ['orderId' => $orderId]
        );
    }

    // ── Helper: send order_completed notification ──
    public function notifyOrderCompleted(string $customerId, string $orderId, string $orderNumber): string {
        return $this->create($customerId, 'order_completed',
            "[completed] Order $orderNumber has been completed — thanks for reducing food waste! 🌱",
            ['orderId' => $orderId]
        );
    }

    // ── Helper: send order_cancelled notification ──
    public function notifyOrderCancelled(string $customerId, string $orderId, string $orderNumber): string {
        return $this->create($customerId, 'order_cancelled',
            "[cancelled] Order $orderNumber has been cancelled.",
            ['orderId' => $orderId]
        );
    }

    // ── Indexes ──
    public function createIndexes(): void {
        $this->collection->createIndex(['customerId' => 1]);
        $this->collection->createIndex(['isRead'     => 1]);
        $this->collection->createIndex(['createdAt'  => -1]);
    }
}

/*
── COLLECTION STRUCTURE ──────────────────────────
  _id            ObjectId    PK
  customerId     ObjectId    FK → customers (customer only)
  type           String      enum: TYPES
  message        String
  isRead         Boolean     default: false
  relatedOrderId ObjectId?   FK → orders (nullable)
  relatedItemId  ObjectId?   FK → items  (nullable)
  createdAt      UTCDateTime
  updatedAt      UTCDateTime
─────────────────────────────────────────────────*/