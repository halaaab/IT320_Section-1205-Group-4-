<?php
require_once __DIR__ . '/../config/database.php';

abstract class BaseModel {
    protected MongoDB\Collection $collection;
    protected string $collectionName;

    public function __construct() {
        $db = Database::getInstance();
        $this->collection = $db->getCollection($this->collectionName);
    }

    public function findById(string $id): ?array {
        $result = $this->collection->findOne([
            '_id' => new MongoDB\BSON\ObjectId($id)
        ]);
        return $result ? (array) $result : null;
    }

    public function findAll(array $filter = [], array $options = []): array {
        $cursor = $this->collection->find($filter, $options);
        return $cursor->toArray();
    }

    public function insertOne(array $data): string {
        $data['createdAt'] = new MongoDB\BSON\UTCDateTime();
        $data['updatedAt'] = new MongoDB\BSON\UTCDateTime();
        $result = $this->collection->insertOne($data);
        return (string) $result->getInsertedId();
    }

    public function updateById(string $id, array $fields): bool {
        $fields['updatedAt'] = new MongoDB\BSON\UTCDateTime();
        $result = $this->collection->updateOne(
            ['_id' => new MongoDB\BSON\ObjectId($id)],
            ['$set' => $fields]
        );
        return $result->getModifiedCount() > 0;
    }

    public function deleteById(string $id): bool {
        $result = $this->collection->deleteOne([
            '_id' => new MongoDB\BSON\ObjectId($id)
        ]);
        return $result->getDeletedCount() > 0;
    }

    public static function toObjectId(string $id): MongoDB\BSON\ObjectId {
        return new MongoDB\BSON\ObjectId($id);
    }

    public static function toDate(?\DateTime $dt = null): MongoDB\BSON\UTCDateTime {
        return new MongoDB\BSON\UTCDateTime($dt ?? new \DateTime());
    }
}