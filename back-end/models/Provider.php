<?php
// models/Provider.php
require_once __DIR__ . '/BaseModel.php';

class Provider extends BaseModel {
    protected string $collectionName = 'providers';

    const CATEGORIES = ['Bakery', 'Coffee shop', 'Super Market', 'Restaurant'];

    // ── Create a new provider ──
    public function create(array $data): string {
        $doc = [
            'businessName'        => $data['businessName'],
            'email'               => strtolower(trim($data['email'])),
            'passwordHash'        => password_hash($data['password'], PASSWORD_BCRYPT),
            'phoneNumber'         => $data['phoneNumber'],
            'businessDescription' => $data['businessDescription'] ?? '',
            'businessLogo'        => $data['businessLogo'] ?? '',
            'category'            => $data['category'],   // one of CATEGORIES
            'role'                => 'provider',
        ];
        return $this->insertOne($doc);
    }

    // ── Find by email (for login) ──
    public function findByEmail(string $email): ?array {
        $result = $this->collection->findOne([
            'email' => strtolower(trim($email))
        ]);
        return $result ? (array) $result : null;
    }

    // ── Verify password ──
    public function verifyPassword(string $plainPassword, string $hash): bool {
        return password_verify($plainPassword, $hash);
    }

    // ── Ensure unique email index (run once during setup) ──
    public function createIndexes(): void {
        $this->collection->createIndex(['email' => 1], ['unique' => true]);
    }
}

/*
── COLLECTION STRUCTURE ──────────────────────────
  _id                 ObjectId   PK
  businessName        String
  email               String     unique
  passwordHash        String     bcrypt hashed
  phoneNumber         String
  businessDescription String
  businessLogo        String     URL
  category            String     enum: CATEGORIES
  role                String     always "provider"
  createdAt           UTCDateTime
  updatedAt           UTCDateTime

  NOTE: pickup locations are stored in the
  pickup_locations collection (not embedded here)
─────────────────────────────────────────────────*/
