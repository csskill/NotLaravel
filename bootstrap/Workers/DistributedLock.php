<?php

namespace Nraa\Workers;

use MongoDB\BSON\UTCDateTime;
use Nraa\Database\Drivers\MongoDBDriver;

/**
 * Lightweight MongoDB-backed distributed lease lock.
 *
 * The lock is lease-based (expires_at), so process crashes self-heal
 * after TTL without requiring explicit unlock.
 */
final class DistributedLock
{
    private \MongoDB\Collection $collection;
    private string $owner;
    private static bool $indexesEnsured = false;

    public function __construct(?string $owner = null, string $collection = 'worker_locks')
    {
        $this->owner = $owner ?: $this->buildDefaultOwner();
        $this->collection = MongoDBDriver::getInstance()->getCollection($collection);

        if (!self::$indexesEnsured) {
            // Speeds up expiry checks for lock acquisition.
            $this->collection->createIndex(['expires_at' => 1], ['name' => 'expires_at_1']);
            self::$indexesEnsured = true;
        }
    }

    public function getOwner(): string
    {
        return $this->owner;
    }

    /**
     * Acquire or renew a lock for this owner.
     *
     * @param string $key Lock key.
     * @param int $ttlSeconds Lease duration.
     */
    public function acquire(string $key, int $ttlSeconds): bool
    {
        $now = new \DateTimeImmutable();
        $nowUtc = new UTCDateTime($now);
        $expiresAt = new UTCDateTime($now->modify('+' . max(1, $ttlSeconds) . ' seconds'));

        // 1) Try to acquire/renew an existing lock document.
        $result = $this->collection->findOneAndUpdate(
            [
                '_id' => $key,
                '$or' => [
                    ['owner' => $this->owner], // lock renewal
                    ['expires_at' => ['$exists' => false]],
                    ['expires_at' => null],
                    ['expires_at' => ['$lt' => $nowUtc]],
                ],
            ],
            [
                '$set' => [
                    'owner' => $this->owner,
                    'expires_at' => $expiresAt,
                    'updated_at' => $nowUtc,
                ],
            ],
            [
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ]
        );

        $owner = $this->extractField($result, 'owner');
        if (is_string($owner) && $owner === $this->owner) {
            return true;
        }

        // 2) No lock doc existed. Try to create it optimistically.
        try {
            $this->collection->insertOne([
                '_id' => $key,
                'owner' => $this->owner,
                'expires_at' => $expiresAt,
                'created_at' => $nowUtc,
                'updated_at' => $nowUtc,
            ]);
            return true;
        } catch (\Throwable $e) {
            // Common race: another process inserted first.
            return false;
        }
    }

    /**
     * Best-effort release. Non-owners cannot release another owner's lock.
     */
    public function release(string $key): void
    {
        $expired = new UTCDateTime((new \DateTimeImmutable())->modify('-1 second'));
        $this->collection->updateOne(
            [
                '_id' => $key,
                'owner' => $this->owner,
            ],
            [
                '$set' => [
                    'expires_at' => $expired,
                    'updated_at' => new UTCDateTime(),
                ],
            ]
        );
    }

    private function buildDefaultOwner(): string
    {
        $host = gethostname() ?: 'unknown-host';
        $pid = getmypid() ?: 0;
        $nonce = function_exists('random_bytes') ? bin2hex(random_bytes(4)) : uniqid('', true);
        return $host . ':' . $pid . ':' . $nonce;
    }

    private function extractField(mixed $document, string $field): mixed
    {
        if ($document instanceof \MongoDB\Model\BSONDocument) {
            return $document[$field] ?? null;
        }
        if (is_array($document)) {
            return $document[$field] ?? null;
        }
        if ($document instanceof \stdClass) {
            return $document->{$field} ?? null;
        }
        return null;
    }
}
