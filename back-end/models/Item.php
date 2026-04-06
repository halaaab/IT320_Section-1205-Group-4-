<?php
// models/Item.php
require_once __DIR__ . '/BaseModel.php';

class Item extends BaseModel {
    protected string $collectionName = 'items';

    const LISTING_TYPES = ['donate', 'sell'];

    // ── Quick update (daily availability) ──
    public function updateQuick(string $id, array $data): void {
        $item   = $this->findById($id);
        $update = [];

        if (isset($data['quantity'])) {
            $update['quantity']    = (int)$data['quantity'];
            $update['isAvailable'] = ((int)$data['quantity'] > 0); // auto hide/show
        }

        if (!empty($data['listingType']) && in_array($data['listingType'], self::LISTING_TYPES, true)) {
            $update['listingType'] = $data['listingType'];
        }

        if (($update['listingType'] ?? $item['listingType'] ?? '') === 'donate') {
            $update['price'] = 0;
        } elseif (isset($data['price'])) {
            $update['price'] = (float)$data['price'];
        }

        if (!empty($data['expiryDate'])) {
            $update['expiryDate'] = new MongoDB\BSON\UTCDateTime(
                strtotime($data['expiryDate']) * 1000
            );
        }

        if (!empty($data['pickupDate'])) {
            $update['pickupDate'] = new MongoDB\BSON\UTCDateTime(
                strtotime($data['pickupDate']) * 1000
            );
        }

        if (!empty($data['pickupLocationId'])) {
            $update['pickupLocationId'] = self::toObjectId($data['pickupLocationId']);
        }

        $update['updatedAt'] = new MongoDB\BSON\UTCDateTime();

        $this->collection->updateOne(
            ['_id' => self::toObjectId($id)],
            ['$set' => $update]
        );
    }

    // ── Create a new item ──
    public function create(string $providerId, array $data): string {

        // ── VALIDATION ──
        if (empty($data['description'])) {
            throw new Exception("Description is required.");
        }

        if (empty($data['photoUrl'])) {
            throw new Exception("Photo is required.");
        }

        $doc = [
            'providerId'       => self::toObjectId($providerId),
            'categoryId'       => self::toObjectId($data['categoryId']),
            'pickupLocationId' => self::toObjectId($data['pickupLocationId']),
            'itemName'         => $data['itemName'],
            'description'      => $data['description'],
            'photoUrl'         => $data['photoUrl'],
            'expiryDate'       => new MongoDB\BSON\UTCDateTime(
                                    strtotime($data['expiryDate']) * 1000
                                  ),
            'pickupDate'       => new MongoDB\BSON\UTCDateTime(strtotime($data['pickupDate']) * 1000),
            'listingType'      => $data['listingType'],
            'price'            => $data['listingType'] === 'donate'
                                    ? 0
                                    : (float) $data['price'],
            'quantity'         => (int) $data['quantity'],
            'pickupTimes'      => $data['pickupTimes'],
            'isAvailable'      => true,
        ];

        return $this->insertOne($doc);
    }

    // ── Get all items by provider ──
    public function getByProvider(string $providerId, array $filter = []): array {
        $filter['providerId'] = self::toObjectId($providerId);
        return $this->findAll($filter);
    }

    // ── Get all items by category ──
  public function getByCategory(string $categoryId): array {
    return $this->findAll([
        'categoryId'  => self::toObjectId($categoryId),
        'isAvailable' => true,
        'expiryDate'  => ['$gte' => new MongoDB\BSON\UTCDateTime(time() * 1000)], // ← add this
    ]);
}

   public function getAvailable(array $filter = []): array {
    $options = [];
    if (isset($filter['sort']))  { $options['sort']  = $filter['sort'];  unset($filter['sort']); }
    if (isset($filter['limit'])) { $options['limit'] = $filter['limit']; unset($filter['limit']); }

    $filter['isAvailable'] = true;
    $filter['expiryDate']  = ['$gte' => new MongoDB\BSON\UTCDateTime(time() * 1000)]; // ← add this

    return $this->findAll($filter, $options);
}

    // ── Get items expiring soon (within $hours hours) ──
    public function getExpiringSoon(int $hours = 24): array {
        $now  = new MongoDB\BSON\UTCDateTime();
        $soon = new MongoDB\BSON\UTCDateTime((time() + $hours * 3600) * 1000);
        return $this->findAll([
            'expiryDate'  => ['$gte' => $now, '$lte' => $soon],
            'isAvailable' => true,
        ]);
    }

    // ── Mark as unavailable (soft delete) ──
    public function markUnavailable(string $itemId): bool {
        return $this->updateById($itemId, ['isAvailable' => false]);
    }

    // ── Decrease quantity after order ──
    public function decreaseQuantity(string $itemId, int $qty): void {
        $this->collection->updateOne(
            ['_id' => self::toObjectId($itemId)],
            [
                '$inc' => ['quantity' => -$qty],
                '$set' => ['updatedAt' => new MongoDB\BSON\UTCDateTime()],
            ]
        );

        // ── Auto-hide when stock hits 0 ──
        $item = $this->findById($itemId);
        if ($item && (int)($item['quantity'] ?? 0) <= 0) {
            $this->collection->updateOne(
                ['_id' => self::toObjectId($itemId)],
                ['$set' => ['isAvailable' => false, 'updatedAt' => new MongoDB\BSON\UTCDateTime()]]
            );
        }
    }

    // ── Increase quantity (on order cancellation) ──
    public function increaseQuantity(string $itemId, int $qty): void {
        $this->collection->updateOne(
            ['_id' => self::toObjectId($itemId)],
            [
                '$inc' => ['quantity' => $qty],
                '$set' => ['updatedAt' => new MongoDB\BSON\UTCDateTime()],
            ]
        );

        // ── Re-enable item if it was marked unavailable ──
        $item = $this->findById($itemId);
        if ($item && (int)($item['quantity'] ?? 0) > 0 && empty($item['isAvailable'])) {
            $this->updateById($itemId, ['isAvailable' => true]);
        }
    }

    // ── Indexes ──
    public function createIndexes(): void {
        $this->collection->createIndex(['providerId'  => 1]);
        $this->collection->createIndex(['categoryId'  => 1]);
        $this->collection->createIndex(['expiryDate'  => 1]);
        $this->collection->createIndex(['isAvailable' => 1]);
    }
}

/*
── COLLECTION STRUCTURE ──────────────────────────
  _id               ObjectId    PK
  providerId        ObjectId    FK → providers
  categoryId        ObjectId    FK → categories
  pickupLocationId  ObjectId    FK → pickup_locations
  itemName          String
  description       String
  photoUrl          String      URL
  expiryDate        UTCDateTime
  listingType       String      "donate" | "sell"
  price             Number      0 if donate
  quantity          Number
  pickupTimes       [String]    e.g. ["2:00pm","6:00pm"]
  isAvailable       Boolean
  createdAt         UTCDateTime
  updatedAt         UTCDateTime
─────────────────────────────────────────────────*/