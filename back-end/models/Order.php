<?php
// models/Order.php
require_once __DIR__ . '/BaseModel.php';

class Order extends BaseModel {
    protected string $collectionName = 'orders';

    const STATUSES = ['pending', 'completed', 'cancelled'];

    // ── Place a new order from a customer's cart ──
    public function create(string $customerId, array $data): string {
        $doc = [
            'customerId'   => self::toObjectId($customerId),
            'orderNumber'  => $this->generateOrderNumber(),
            'totalAmount'  => (float) $data['totalAmount'],
            'paymentMethod'=> 'cash',
            'orderStatus'  => 'pending',
            'placedAt'     => new MongoDB\BSON\UTCDateTime(),
            'completedAt'  => null,
        ];
        return $this->insertOne($doc);
    }

    // ── Get all orders for a customer ──
    public function getByCustomer(string $customerId): array {
        return $this->findAll(
            ['customerId' => self::toObjectId($customerId)],
            ['sort' => ['placedAt' => -1]]
        );
    }

    // ── Get current (pending) orders for a customer ──
    public function getPending(string $customerId): array {
        return $this->findAll([
            'customerId'  => self::toObjectId($customerId),
            'orderStatus' => 'pending',
        ]);
    }

    // ── Update order status ──
    public function updateStatus(string $orderId, string $status): bool {
        $fields = ['orderStatus' => $status];
        if ($status === 'completed') {
            $fields['completedAt'] = new MongoDB\BSON\UTCDateTime();
        }
        return $this->updateById($orderId, $fields);
    }

    // ── Cancel an order ──
    public function cancel(string $orderId): bool {
        return $this->updateStatus($orderId, 'cancelled');
    }

    // ── Auto-generate a readable order number ──
    private function generateOrderNumber(): string {
        return 'RP-' . strtoupper(substr(uniqid(), -6));
    }

    // ── Indexes ──
    public function createIndexes(): void {
        $this->collection->createIndex(['customerId'  => 1]);
        $this->collection->createIndex(['orderNumber' => 1], ['unique' => true]);
        $this->collection->createIndex(['orderStatus' => 1]);
    }
}

/*
── COLLECTION STRUCTURE ──────────────────────────
  _id            ObjectId    PK
  customerId     ObjectId    FK → customers
  orderNumber    String      unique, e.g. "RP-A1B2C3"
  totalAmount    Number
  paymentMethod  String      "cash"
  orderStatus    String      "pending"|"completed"|"cancelled"
  placedAt       UTCDateTime
  completedAt    UTCDateTime nullable

  NOTE: pickupLocation + pickupTime are stored
  per item inside order_items (not here), because
  each item can have a different location/time.
─────────────────────────────────────────────────*/
