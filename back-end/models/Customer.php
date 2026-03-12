<?php
// models/Customer.php
require_once __DIR__ . '/BaseModel.php';

class Customer extends BaseModel {
    protected string $collectionName = 'customers';

    // ── Create a new customer ──
    public function create(array $data): string {
        $doc = [
            'fullName'     => $data['fullName'],
            'email'        => strtolower(trim($data['email'])),
            'passwordHash' => password_hash($data['password'], PASSWORD_BCRYPT),
            'phoneNumber'  => $data['phoneNumber'],
            'role'         => 'customer',
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
  _id          ObjectId   PK  (auto-generated)
  fullName     String
  email        String     unique
  passwordHash String     bcrypt hashed
  phoneNumber  String
  role         String     always "customer"
  createdAt    UTCDateTime
  updatedAt    UTCDateTime
─────────────────────────────────────────────────*/
