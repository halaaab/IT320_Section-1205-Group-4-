<?php
// models/SupportTicket.php
require_once __DIR__ . '/BaseModel.php';

class SupportTicket extends BaseModel {
    protected string $collectionName = 'support_tickets';

    const REASONS  = ['Missing item', 'Damaged items', 'Others'];
    const STATUSES = ['open', 'resolved'];

    // ── Submit a new support ticket ──
    public function create(string $customerId, array $data): string {
        $doc = [
            'customerId'  => self::toObjectId($customerId),
            'reason'      => $data['reason'],       // one of REASONS
            'description' => $data['description'],
            'status'      => 'open',
            'submittedAt' => new MongoDB\BSON\UTCDateTime(),
        ];
        return $this->insertOne($doc);
    }

    // ── Get all tickets for a customer ──
    public function getByCustomer(string $customerId): array {
        return $this->findAll(
            ['customerId' => self::toObjectId($customerId)],
            ['sort' => ['submittedAt' => -1]]
        );
    }

    // ── Mark ticket as resolved ──
    public function resolve(string $ticketId): bool {
        return $this->updateById($ticketId, ['status' => 'resolved']);
    }

    // ── Index ──
    public function createIndexes(): void {
        $this->collection->createIndex(['customerId' => 1]);
        $this->collection->createIndex(['status'     => 1]);
    }
}

/*
── COLLECTION STRUCTURE ──────────────────────────
  _id         ObjectId    PK
  customerId  ObjectId    FK → customers
  reason      String      "Missing item"|"Damaged items"|"Others"
  description String
  status      String      "open" | "resolved"
  submittedAt UTCDateTime
  createdAt   UTCDateTime
  updatedAt   UTCDateTime
─────────────────────────────────────────────────*/
