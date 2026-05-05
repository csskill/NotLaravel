<?php

namespace Nraa\Workers\Transports\Concerns;

use MongoDB\Driver\Exception\BulkWriteException;
use Nraa\Workers\DistributedLock;
use Nraa\Workers\Documents\JobDocument;

trait EnforcesJobIdempotency
{
    private const IDEMPOTENCY_LOCK_TTL_SECONDS = 15;
    private const IDEMPOTENCY_LOCK_ATTEMPTS = 80;
    private const IDEMPOTENCY_LOCK_RETRY_MICROSECONDS = 25_000;
    private const IDEMPOTENCY_LOCK_EXISTING_CHECK_INTERVAL = 10;

    protected function createQueuedJobDocument(array $jobData, bool $preventDuplicates = true): ?JobDocument
    {
        $idempotencyKey = trim((string)($jobData['idempotency_key'] ?? ''));
        if (!$preventDuplicates || $idempotencyKey === '') {
            return JobDocument::create($jobData);
        }

        $existing = JobDocument::findActiveByIdempotencyKey($idempotencyKey);
        if ($existing instanceof JobDocument) {
            return $existing;
        }

        $lock = new DistributedLock(null, 'worker_locks');
        $lockKey = 'job-idempotency:' . $idempotencyKey;
        $lockAcquired = $this->acquireIdempotencyLock($lock, $lockKey, $idempotencyKey);

        if (!$lockAcquired) {
            $existing = JobDocument::findActiveByIdempotencyKey($idempotencyKey);
            if ($existing instanceof JobDocument) {
                return $existing;
            }

            throw new \RuntimeException('Timed out waiting for job idempotency lock');
        }

        try {
            $existing = JobDocument::findActiveByIdempotencyKey($idempotencyKey);
            if ($existing instanceof JobDocument) {
                return $existing;
            }

            return JobDocument::create($jobData);
        } catch (BulkWriteException $e) {
            $existing = JobDocument::findActiveByIdempotencyKey($idempotencyKey);
            if ($existing instanceof JobDocument) {
                return $existing;
            }

            throw $e;
        } finally {
            $lock->release($lockKey);
        }
    }

    private function acquireIdempotencyLock(DistributedLock $lock, string $lockKey, string $idempotencyKey): bool
    {
        for ($attempt = 0; $attempt < self::IDEMPOTENCY_LOCK_ATTEMPTS; $attempt++) {
            if ($lock->acquire($lockKey, self::IDEMPOTENCY_LOCK_TTL_SECONDS)) {
                return true;
            }

            $shouldCheckExisting = $attempt === 0
                || (($attempt + 1) % self::IDEMPOTENCY_LOCK_EXISTING_CHECK_INTERVAL) === 0
                || $attempt === (self::IDEMPOTENCY_LOCK_ATTEMPTS - 1);

            if ($shouldCheckExisting) {
                $existing = JobDocument::findActiveByIdempotencyKey($idempotencyKey);
                if ($existing instanceof JobDocument) {
                    return false;
                }
            }

            usleep(self::IDEMPOTENCY_LOCK_RETRY_MICROSECONDS);
        }

        return false;
    }
}
