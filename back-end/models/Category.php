<?php
// models/Category.php
require_once __DIR__ . '/BaseModel.php';

class Category extends BaseModel {
    protected string $collectionName = 'categories';

    // ── Seed default categories (run once during setup) ──
    public function seed(): void {
        $defaults = [
            ['name' => 'Bakery',    'icon' => '/icons/bakery.png'],
            ['name' => 'Groceries', 'icon' => '/icons/groceries.png'],
            ['name' => 'Meals',     'icon' => '/icons/meals.png'],
            ['name' => 'Dairy',     'icon' => '/icons/dairy.png'],
            ['name' => 'Sweets',    'icon' => '/icons/sweets.png'],
        ];
        foreach ($defaults as $cat) {
            // Only insert if not already present
            $this->collection->updateOne(
                ['name' => $cat['name']],
                ['$setOnInsert' => array_merge($cat, [
                    'createdAt' => new MongoDB\BSON\UTCDateTime(),
                ])],
                ['upsert' => true]
            );
        }
    }

    // ── Get all categories ──
    public function getAll(): array {
        return $this->findAll([], ['sort' => ['name' => 1]]);
    }

    // ── Find by name ──
    public function findByName(string $name): ?array {
        $result = $this->collection->findOne(['name' => $name]);
        return $result ? (array) $result : null;
    }

    // ── Ensure unique name index (run once during setup) ──
    public function createIndexes(): void {
        $this->collection->createIndex(['name' => 1], ['unique' => true]);
    }
}

/*
── COLLECTION STRUCTURE ──────────────────────────
  _id       ObjectId   PK
  name      String     unique — "Bakery" | "Groceries"
                               "Meals"  | "Dairy" | "Sweets"
  icon      String     URL to category image
  createdAt UTCDateTime
─────────────────────────────────────────────────*/
