<?php
// models/Favourite.php
require_once __DIR__ . '/BaseModel.php';

class Favourite extends BaseModel {
    protected string $collectionName = 'favourites';

    // ── Add item to favourites ──
    public function add(string $customerId, string $itemId): string|false {
        // Check if already saved
        if ($this->isSaved($customerId, $itemId)) {
            return false; // already exists
        }
        $doc = [
            'customerId' => self::toObjectId($customerId),
            'itemId'     => self::toObjectId($itemId),
            'savedAt'    => new MongoDB\BSON\UTCDateTime(),
        ];
        return $this->insertOne($doc);
    }

    // ── Remove item from favourites ──
    public function remove(string $customerId, string $itemId): bool {
        $result = $this->collection->deleteOne([
            'customerId' => self::toObjectId($customerId),
            'itemId'     => self::toObjectId($itemId),
        ]);
        return $result->getDeletedCount() > 0;
    }

    // ── Check if item is saved by customer ──
    public function isSaved(string $customerId, string $itemId): bool {
        $result = $this->collection->findOne([
            'customerId' => self::toObjectId($customerId),
            'itemId'     => self::toObjectId($itemId),
        ]);
        return $result !== null;
    }

    // ── Get all favourites for a customer ──
    public function getByCustomer(string $customerId): array {
        return $this->findAll(
            ['customerId' => self::toObjectId($customerId)],
            ['sort' => ['savedAt' => -1]]
        );
    }

    // ── Indexes ──
    public function createIndexes(): void {
        // Compound unique index prevents duplicates
        $this->collection->createIndex(
            ['customerId' => 1, 'itemId' => 1],
            ['unique' => true]
        );
    }
}

/*
── COLLECTION STRUCTURE ──────────────────────────
  _id        ObjectId    PK
  customerId ObjectId    FK → customers
  itemId     ObjectId    FK → items
  savedAt    UTCDateTime
  unique: (customerId, itemId)
─────────────────────────────────────────────────*/
