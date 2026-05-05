<?php

namespace Nraa\Workers\Transports;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Nraa\Workers\Contracts\QueueTransportInterface;
use Nraa\Workers\Documents\JobDocument;
use Nraa\Workers\Documents\RecurringJobDocument;
use Nraa\Workers\Documents\ScheduledJobDocument;
use Nraa\Workers\JobTypeControlService;
use Nraa\Workers\Transports\Concerns\EnforcesJobIdempotency;

final class MongoQueueTransport implements QueueTransportInterface
{
    use EnforcesJobIdempotency;

    private static bool $indexesEnsured = false;

    public function __construct(bool $ensureIndexes = false)
    {
        if (($ensureIndexes || $this->shouldEnsureIndexes()) && !self::$indexesEnsured) {
            (new JobDocument())->ensureIndexes();
            (new ScheduledJobDocument())->ensureIndexes();
            (new RecurringJobDocument())->ensureIndexes();
            self::$indexesEnsured = true;
        }
    }

    public function enqueue(array|object $jobData, bool $preventDuplicates = true): ?JobDocument
    {
        if ((new JobTypeControlService())->isJobDataDisabled($jobData)) {
            return null;
        }

        $jobData = is_array($jobData) ? $jobData : (array)$jobData;
        return $this->createQueuedJobDocument($jobData, $preventDuplicates);
    }

    public function claimNextJob(string $workerId, string $poolName, ?array $workerConfig = null): ?JobDocument
    {
        $result = (new JobDocument())->findOneAndUpdate(
            [
                'status' => 'assigned',
                'assignee' => $workerId,
            ],
            [
                '$set' => [
                    'status' => 'in_progress',
                    'startedAt' => new UTCDateTime(),
                    'updatedAt' => new UTCDateTime(),
                ],
            ],
            [
                'sort' => [
                    'priority' => -1,
                    'createdAt' => 1,
                ],
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ]
        );

        return $this->hydrateJobDocument($result);
    }

    public function supportsDispatcherAssignments(): bool
    {
        return true;
    }

    public function releaseDueJobs(?array $poolNames = null, ?\DateTimeImmutable $now = null): array
    {
        return [
            'released_jobs' => 0,
            'reconciled_jobs' => 0,
            'reclaimed_messages' => 0,
        ];
    }

    public function reconcileJobs(array $jobIds, ?\DateTimeImmutable $now = null): int
    {
        return 0;
    }

    public function fetchPendingForPool(string $poolName, int $limit, ?\DateTimeImmutable $now = null): array
    {
        $poolName = trim($poolName) !== '' ? trim($poolName) : 'general';
        $now = $now ?? new \DateTimeImmutable();

        $filter = [
            'status' => 'pending',
            'nextRunAt' => ['$lte' => new UTCDateTime($now)],
        ];

        if ($poolName === 'general') {
            $filter['$or'] = [
                ['pool' => 'general'],
                ['pool' => null],
                ['pool' => ''],
                ['pool' => ['$exists' => false]],
            ];
        } else {
            $filter['pool'] = $poolName;
        }

        $jobs = JobDocument::find(
            $filter,
            [
                'limit' => max(1, $limit),
                'sort' => [
                    'priority' => -1,
                    'nextRunAt' => 1,
                    'createdAt' => 1,
                ],
            ]
        )->toArray();

        return $this->applyPendingFairnessOrdering($jobs, max(1, $limit));
    }

    public function markAssigned(string $jobId, string $workerId, ?array $instructions = null): bool
    {
        $setData = [
            'status' => 'assigned',
            'assignee' => $workerId,
            'assignedAt' => new UTCDateTime(new \DateTimeImmutable()),
            'updatedAt' => new UTCDateTime(new \DateTimeImmutable()),
        ];

        if ($instructions !== null) {
            $setData['instructions'] = $instructions;
        }

        $result = (new JobDocument())->findOneAndUpdate(
            [
                '_id' => new ObjectId($jobId),
                'status' => 'pending',
            ],
            ['$set' => $setData],
            [
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ]
        );

        return $result !== null;
    }

    public function getActiveWorkerLoadMap(array $workerIds): array
    {
        $workerIds = array_values(array_unique(array_filter(array_map(
            static fn($workerId): string => trim((string)$workerId),
            $workerIds
        ), static fn(string $workerId): bool => $workerId !== '')));

        if ($workerIds === []) {
            return [];
        }

        $rows = (new JobDocument())->getCollection()->aggregate([
            [
                '$match' => [
                    'status' => ['$in' => ['assigned', 'in_progress']],
                    'assignee' => ['$in' => $workerIds],
                ],
            ],
            [
                '$group' => [
                    '_id' => '$assignee',
                    'count' => ['$sum' => 1],
                ],
            ],
        ])->toArray();

        $map = array_fill_keys($workerIds, 0);
        foreach ($rows as $row) {
            if ($row instanceof \MongoDB\Model\BSONDocument) {
                $row = $row->getArrayCopy();
            } elseif ($row instanceof \stdClass) {
                $row = (array)$row;
            }

            if (!is_array($row)) {
                continue;
            }

            $workerId = trim((string)($row['_id'] ?? ''));
            if ($workerId === '') {
                continue;
            }
            $map[$workerId] = (int)($row['count'] ?? 0);
        }

        return $map;
    }

    public function getActiveJobClassLoadMap(array $jobClasses): array
    {
        $jobClasses = array_values(array_unique(array_filter(array_map(
            static fn($jobClass): string => trim((string)$jobClass),
            $jobClasses
        ), static fn(string $jobClass): bool => $jobClass !== '')));

        if ($jobClasses === []) {
            return [];
        }

        $rows = (new JobDocument())->getCollection()->aggregate([
            [
                '$match' => [
                    'status' => ['$in' => ['assigned', 'in_progress']],
                    'task.class' => ['$in' => $jobClasses],
                ],
            ],
            [
                '$group' => [
                    '_id' => '$task.class',
                    'count' => ['$sum' => 1],
                ],
            ],
        ])->toArray();

        $map = array_fill_keys($jobClasses, 0);
        foreach ($rows as $row) {
            if ($row instanceof \MongoDB\Model\BSONDocument) {
                $row = $row->getArrayCopy();
            } elseif ($row instanceof \stdClass) {
                $row = (array)$row;
            }

            if (!is_array($row)) {
                continue;
            }

            $jobClass = trim((string)($row['_id'] ?? ''));
            if ($jobClass === '') {
                continue;
            }
            $map[$jobClass] = (int)($row['count'] ?? 0);
        }

        return $map;
    }

    public function afterJobCompleted(JobDocument $job, string $workerId): void
    {
    }

    public function afterJobRequeued(JobDocument $job, string $workerId, int $delaySeconds, string $errorMessage): void
    {
    }

    public function afterJobTerminal(JobDocument $job, string $workerId, string $status, ?string $errorMessage = null): void
    {
    }

    /**
     * @param array<int, mixed> $jobs
     * @return array<int, JobDocument>
     */
    private function applyPendingFairnessOrdering(array $jobs, int $limit): array
    {
        $ordered = [];
        $backfillQueues = [];
        $backfillOrder = [];

        foreach ($jobs as $job) {
            if (!$job instanceof JobDocument) {
                continue;
            }

            $isBackfill = strtolower(trim((string)($job->lane ?? ''))) === 'backfill';
            $fairnessKey = trim((string)($job->fairness_key ?? ''));
            if (!$isBackfill || $fairnessKey === '') {
                $ordered[] = $job;
                continue;
            }

            if (!isset($backfillQueues[$fairnessKey])) {
                $backfillQueues[$fairnessKey] = [];
                $backfillOrder[] = $fairnessKey;
            }

            $backfillQueues[$fairnessKey][] = $job;
        }

        while ($backfillQueues !== [] && count($ordered) < $limit) {
            $nextOrder = [];
            foreach ($backfillOrder as $fairnessKey) {
                if (!isset($backfillQueues[$fairnessKey]) || $backfillQueues[$fairnessKey] === []) {
                    continue;
                }

                /** @var JobDocument $job */
                $job = array_shift($backfillQueues[$fairnessKey]);
                $ordered[] = $job;

                if ($backfillQueues[$fairnessKey] !== []) {
                    $nextOrder[] = $fairnessKey;
                } else {
                    unset($backfillQueues[$fairnessKey]);
                }

                if (count($ordered) >= $limit) {
                    break;
                }
            }

            $backfillOrder = $nextOrder;
        }

        return array_slice($ordered, 0, $limit);
    }

    private function hydrateJobDocument(mixed $result): ?JobDocument
    {
        if ($result instanceof JobDocument) {
            return $result;
        }

        if ($result instanceof \MongoDB\Model\BSONDocument) {
            $result = $result->getArrayCopy();
        } elseif ($result instanceof \stdClass) {
            $result = (array)$result;
        }

        if (!is_array($result) || $result === []) {
            return null;
        }

        $job = new JobDocument();
        $job->bsonUnserialize($result);
        if (isset($result['_id']) && $result['_id'] instanceof ObjectId) {
            $job->id = $result['_id'];
        }

        return $job;
    }

    private function shouldEnsureIndexes(): bool
    {
        $raw = strtolower(trim((string)($_ENV['JOB_QUEUE_AUTO_ENSURE_INDEXES'] ?? getenv('JOB_QUEUE_AUTO_ENSURE_INDEXES') ?: '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }
}
