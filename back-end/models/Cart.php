<?php
// models/Cart.php
require_once __DIR__ . '/BaseModel.php';

class Cart extends BaseModel {
    protected string $collectionName = 'carts';

    // ── Get or create the active cart for a customer ──
    public function getOrCreate(string $customerId): array {
        $cart = $this->collection->findOne([
            'customerId' => self::toObjectId($customerId)
        ]);
        if ($cart) {
            return (array) $cart;
        }
        // Create empty cart
        $id = $this->insertOne([
            'customerId' => self::toObjectId($customerId),
            'cartItems'  => [],
        ]);
        return $this->findById($id);
    }

    // ── Add or update an item in the cart ──
    public function addItem(string $customerId, array $item): void {
        $itemObjId = self::toObjectId($item['itemId']);

        // Check if item already in cart
        $existing = $this->collection->findOne([
            'customerId'       => self::toObjectId($customerId),
            'cartItems.itemId' => $itemObjId,
        ]);

        if ($existing) {
            // Increment quantity
            $this->collection->updateOne(
                [
                    'customerId'       => self::toObjectId($customerId),
                    'cartItems.itemId' => $itemObjId,
                ],
                [
                    '$inc' => ['cartItems.$.quantity' => (int) $item['quantity']],
                    '$set' => [
                        'cartItems.$.selectedPickupTime' => $item['selectedPickupTime'] ?? '',
                        'updatedAt' => new MongoDB\BSON\UTCDateTime(),
                    ],
                ]
            );
        } else {
            // Push new item into cartItems array
            $this->collection->updateOne(
                ['customerId' => self::toObjectId($customerId)],
                [
                    '$push' => [
                        'cartItems' => [
                            'itemId'     => $itemObjId,
                            'providerId' => self::toObjectId($item['providerId']),
                            'quantity'   => (int) $item['quantity'],
                            'itemName'   => $item['itemName'],    // snapshot
                            'price'      => (float) $item['price'], // snapshot
                        ]
                    ],
                    '$set' => ['updatedAt' => new MongoDB\BSON\UTCDateTime()],
                ],
                ['upsert' => true]
            );
        }
    }

    // ── Remove an item from the cart ──
    public function removeItem(string $customerId, string $itemId): void {
        $this->collection->updateOne(
            ['customerId' => self::toObjectId($customerId)],
            [
                '$pull' => ['cartItems' => ['itemId' => self::toObjectId($itemId)]],
                '$set'  => ['updatedAt' => new MongoDB\BSON\UTCDateTime()],
            ]
        );
    }

    // ── Update quantity of a specific item ──
    public function updateQuantity(string $customerId, string $itemId, int $qty): void {
        $this->collection->updateOne(
            [
                'customerId'       => self::toObjectId($customerId),
                'cartItems.itemId' => self::toObjectId($itemId),
            ],
            [
                '$set' => [
                    'cartItems.$.quantity' => $qty,
                    'updatedAt'            => new MongoDB\BSON\UTCDateTime(),
                ]
            ]
        );
    }

    // ── Clear the entire cart (after order placed) ──
    public function clear(string $customerId): void {
        $this->collection->updateOne(
            ['customerId' => self::toObjectId($customerId)],
            [
                '$set' => [
                    'cartItems' => [],
                    'updatedAt' => new MongoDB\BSON\UTCDateTime(),
                ]
            ]
        );
    }

    // ── Update a single field on a specific cart item ──
    public function updateItemField(string $customerId, string $itemId, string $field, $value): void {
        $this->collection->updateOne(
            [
                'customerId'       => self::toObjectId($customerId),
                'cartItems.itemId' => self::toObjectId($itemId),
            ],
            [
                '$set' => [
                    "cartItems.$.{$field}" => $value,
                    'updatedAt'            => new MongoDB\BSON\UTCDateTime(),
                ]
            ]
        );
    }

    // ── Index ──
    public function createIndexes(): void {
        $this->collection->createIndex(['customerId' => 1], ['unique' => true]);
    }
}

/*
── COLLECTION STRUCTURE ──────────────────────────
  _id        ObjectId   PK
  customerId ObjectId   FK → customers  (unique: 1 cart per customer)
  cartItems  [ Object ] embedded array:
    itemId     ObjectId  ref → items
    providerId ObjectId  ref → providers
    quantity   Number
    itemName   String    snapshot (for fast display)
    price      Number    snapshot (for fast display)
  updatedAt  UTCDateTime
  createdAt  UTCDateTime
─────────────────────────────────────────────────*/