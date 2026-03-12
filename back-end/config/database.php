<?php
// config/database.php
// ─────────────────────────────────────────────
// RePlate — MongoDB Connection (PHP Driver)
// ─────────────────────────────────────────────
// Install: composer require mongodb/mongodb
// ─────────────────────────────────────────────

require_once __DIR__ . '/../../vendor/autoload.php';

class Database {
    private static $instance = null;
    private $client;
    private $db;

    // ── Put your Atlas connection string here ──
    private string $uri = 'mongodb+srv://halaabdulrahman01_db_user:GoUOMLzF15w2GGKb@replate.yqd9e0z.mongodb.net/?appName=Replate';
    private string $dbName = 'replate';

    private function __construct() {
        $this->client = new MongoDB\Client($this->uri);
        $this->db     = $this->client->selectDatabase($this->dbName);
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // Returns a MongoDB\Collection for the given collection name
    public function getCollection(string $name): MongoDB\Collection {
        return $this->db->selectCollection($name);
    }
}
