<?php
// models/PickupLocation.php
require_once __DIR__ . '/BaseModel.php';

class PickupLocation extends BaseModel {
    protected string $collectionName = 'pickup_locations';

    // ── Add a pickup location for a provider ──
    public function create(string $providerId, array $data): string {
        // If this is marked as default, unset any existing default first
        if (!empty($data['isDefault'])) {
            $this->clearDefault($providerId);
        }

        $doc = [
            'providerId'  => self::toObjectId($providerId),
            'label'       => $data['label'],              // e.g. "Main Branch"
            'street'      => $data['street'],
            'city'        => $data['city'],
            'zip'         => $data['zip'] ?? '',
            'coordinates' => [
                'lat' => (float) $data['lat'],
                'lng' => (float) $data['lng'],
            ],
            'isDefault'   => (bool) ($data['isDefault'] ?? false),
        ];
        return $this->insertOne($doc);
    }

    // ── Get all locations for a provider ──
    public function getByProvider(string $providerId): array {
        return $this->findAll(
            ['providerId' => self::toObjectId($providerId)],
            ['sort' => ['isDefault' => -1]] // default first
        );
    }

    // ── Get the default location for a provider ──
    public function getDefault(string $providerId): ?array {
        $result = $this->collection->findOne([
            'providerId' => self::toObjectId($providerId),
            'isDefault'  => true,
        ]);
        return $result ? (array) $result : null;
    }

    // ── Set a location as default (clears others first) ──
    public function setAsDefault(string $locationId, string $providerId): void {
        $this->clearDefault($providerId);
        $this->updateById($locationId, ['isDefault' => true]);
    }

    // ── Remove default flag from all provider locations ──
    private function clearDefault(string $providerId): void {
        $this->collection->updateMany(
            ['providerId' => self::toObjectId($providerId)],
            ['$set' => ['isDefault' => false]]
        );
    }

    // ── Index on providerId for fast lookup ──
    public function createIndexes(): void {
        $this->collection->createIndex(['providerId' => 1]);
    }
}

/*
── COLLECTION STRUCTURE ──────────────────────────
  _id         ObjectId   PK
  providerId  ObjectId   FK → providers
  label       String     e.g. "Main Branch", "Branch 2"
  street      String
  city        String
  zip         String     postal code (optional)
  coordinates Object     { lat: Float, lng: Float }
  isDefault   Boolean    true = signup address (can be changed)
  createdAt   UTCDateTime
  updatedAt   UTCDateTime
─────────────────────────────────────────────────*/