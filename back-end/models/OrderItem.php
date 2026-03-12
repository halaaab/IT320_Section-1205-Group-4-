<?php
// models/OrderItem.php
require_once __DIR__ . '/BaseModel.php';

class OrderItem extends BaseModel {
    protected string $collectionName = 'order_items';

    // ── Create order items from cart items ──
    // Call this after Order::create(), passing the new orderId
    public function createFromCart(string $orderId, array $cartItems): void {
        foreach ($cartItems as $cartItem) {
            $doc = [
                'orderId'            => self::toObjectId($orderId),
                'itemId'             => self::toObjectId((string) $cartItem['itemId']),
                'providerId'         => self::toObjectId((string) $cartItem['providerId']),
                // ── Snapshots (preserved even if item/provider is edited later) ──
                'itemName'           => $cartItem['itemName'],
                'providerName'       => $cartItem['providerName'],
                'photoUrl'           => $cartItem['photoUrl'],
                'price'              => (float) $cartItem['price'],
                'quantity'           => (int) $cartItem['quantity'],
                'pickupLocation'     => $cartItem['pickupLocation'],  // address string snapshot
                'selectedPickupTime' => $cartItem['selectedPickupTime'],
            ];
            $this->insertOne($doc);
        }
    }

    // ── Get all items for a specific order ──
    public function getByOrder(string $orderId): array {
        return $this->findAll(
            ['orderId' => self::toObjectId($orderId)],
            ['sort'    => ['createdAt' => 1]]
        );
    }

    // ── Get all order items for a provider (for provider orders page) ──
    public function getByProvider(string $providerId): array {
        return $this->findAll([
            'providerId' => self::toObjectId($providerId),
        ]);
    }

    // ── Indexes ──
    public function createIndexes(): void {
        $this->collection->createIndex(['orderId'    => 1]);
        $this->collection->createIndex(['providerId' => 1]);
        $this->collection->createIndex(['itemId'     => 1]);
    }
}

/*
── COLLECTION STRUCTURE ──────────────────────────
  _id                ObjectId    PK
  orderId            ObjectId    FK → orders
  itemId             ObjectId    FK → items
  providerId         ObjectId    FK → providers
  itemName           String      snapshot
  providerName       String      snapshot
  photoUrl           String      snapshot
  price              Number      snapshot
  quantity           Number
  pickupLocation     String      snapshot of location address
  selectedPickupTime String      snapshot of chosen time slot
  createdAt          UTCDateTime
  updatedAt          UTCDateTime
─────────────────────────────────────────────────*/
