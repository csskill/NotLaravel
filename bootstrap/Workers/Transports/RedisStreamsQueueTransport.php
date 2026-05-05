<?php

namespace Nraa\Workers\Transports;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Driver\Exception\BulkWriteException;
use Nraa\Pillars\Log;
use Nraa\Services\Infrastructure\RedisClientProxy;
use Nraa\Services\Infrastructure\RedisConnectionResolver;
use Nraa\Workers\Contracts\QueueTransportInterface;
use Nraa\Workers\Documents\JobDocument;
use Nraa\Workers\Documents\RecurringJobDocument;
use Nraa\Workers\Documents\ScheduledJobDocument;
use Nraa\Workers\JobTypeControlService;
use Nraa\Workers\PoolManager;
use Nraa\Workers\Transports\Concerns\EnforcesJobIdempotency;

final class RedisStreamsQueueTransport implements QueueTransportInterface
{
    use EnforcesJobIdempotency;

    /**
     * Backfill jobs should share pool access across fairness keys once multiple
     * users are active, while realtime jobs continue to flow unthrottled.
     *
     * @var array<string,array{single:int,shared:int}>
     */
    private const BACKFILL_FAIRNESS_LIMITS = [
        'metadata' => ['single' => 2, 'shared' => 2],
        'download' => ['single' => 6, 'shared' => 3],
        'parse' => ['single' => 4, 'shared' => 2],
    ];

    private static bool $indexesEnsured = false;

    private ?RedisClientProxy $client = null;
    /** @var array<string,mixed> */
    private array $connection = [];
    private string $prefix = 'jobs';
    private int $streamMaxLen = 50000;
    private int $claimBlockMs = 25;
    private int $releaseBatchSize = 500;
    private int $reconcileBatchSize = 250;
    private int $deliveryTtlSeconds = 21600;
    private int $queuedTtlSeconds = 21600;
    private int $reclaimBatchSize = 100;
    private int $consumerCleanupIntervalSeconds = 300;
    private int $consumerMaxIdleSeconds = 900;
    private int $consumerCleanupBatchSize = 250;
    private PoolManager $poolManager;
    private bool $connected = false;
    private float $lastIdleHealthCheckAt = 0.0;

    public function __construct(bool $ensureIndexes = false)
    {
        if (($ensureIndexes || $this->shouldEnsureIndexes()) && !self::$indexesEnsured) {
            (new JobDocument())->ensureIndexes();
            (new ScheduledJobDocument())->ensureIndexes();
            (new RecurringJobDocument())->ensureIndexes();
            self::$indexesEnsured = true;
        }

        $this->poolManager = new PoolManager();
        $this->prefix = trim((string)($this->envString('JOB_REDIS_PREFIX') ?? 'jobs')) ?: 'jobs';
        $this->streamMaxLen = max(1000, (int)($this->envInt('JOB_STREAM_MAXLEN') ?? 50000));
        $this->claimBlockMs = max(0, (int)($this->envInt('JOB_STREAM_CLAIM_BLOCK_MS') ?? 25));
        $this->releaseBatchSize = max(10, (int)($this->envInt('JOB_STREAM_RELEASE_BATCH_SIZE') ?? 500));
        $this->reconcileBatchSize = max(10, (int)($this->envInt('JOB_STREAM_RECONCILE_BATCH_SIZE') ?? 250));
        $this->reclaimBatchSize = max(10, (int)($this->envInt('JOB_STREAM_RECLAIM_BATCH_SIZE') ?? 100));
        $this->consumerCleanupIntervalSeconds = max(30, (int)($this->envInt('JOB_STREAM_CONSUMER_CLEANUP_INTERVAL_SECONDS') ?? 300));
        $this->consumerMaxIdleSeconds = max(60, (int)($this->envInt('JOB_STREAM_CONSUMER_MAX_IDLE_SECONDS') ?? 900));
        $this->consumerCleanupBatchSize = max(10, (int)($this->envInt('JOB_STREAM_CONSUMER_CLEANUP_BATCH_SIZE') ?? 250));
        $this->deliveryTtlSeconds = max(300, (int)($this->envInt('JOB_STREAM_DELIVERY_TTL_SECONDS') ?? 21600));
        $this->queuedTtlSeconds = max(300, (int)($this->envInt('JOB_STREAM_QUEUED_TTL_SECONDS') ?? 21600));

        $this->connection = RedisConnectionResolver::fromEnvPrefixes(
            ['JOB_REDIS', 'REDIS'],
            [
                'label' => 'RedisStreamsQueueTransport',
                'database' => 9,
                'timeout_seconds' => 0.5,
            ]
        );

        if (!$this->connect()) {
            throw new \RuntimeException('Redis Streams transport selected but Redis connection could not be established.');
        }
    }

    public function enqueue(array|object $jobData, bool $preventDuplicates = true): ?JobDocument
    {
        if ((new JobTypeControlService())->isJobDataDisabled($jobData)) {
            return null;
        }

        $jobData = is_array($jobData) ? $jobData : (array)$jobData;
        $job = $this->createQueuedJobDocument($jobData, $preventDuplicates);

        $this->routeJobDocument($job, new \DateTimeImmutable());

        return $job;
    }

    public function claimNextJob(string $workerId, string $poolName, ?array $workerConfig = null): ?JobDocument
    {
        $poolName = $this->normalizePool($poolName);
        $streamKey = $this->streamKey($poolName);
        $groupName = $this->groupName($poolName);
        $this->ensureConsumerGroup($poolName);

        $messages = $this->client->xReadGroup(
            $groupName,
            $workerId,
            [$streamKey => '>'],
            1,
            $this->claimBlockMs
        );

        if (!is_array($messages) || $messages === [] || !isset($messages[$streamKey]) || !is_array($messages[$streamKey])) {
            $this->probeIdleRedisConnection();
            return null;
        }

        foreach ($messages[$streamKey] as $entryId => $fields) {
            $jobId = trim((string)($fields['job_id'] ?? ''));
            if ($jobId === '') {
                $this->ackAndDelete($streamKey, $groupName, (string)$entryId);
                continue;
            }

            $job = $this->claimPendingJobDocument($jobId, $workerId, $workerConfig);
            if (!$job instanceof JobDocument) {
                $this->ackAndDelete($streamKey, $groupName, (string)$entryId);
                $this->clearQueuedState($jobId);
                continue;
            }

            $this->clearQueuedState($jobId);
            $this->writeDeliveryState($jobId, [
                'stream' => $streamKey,
                'group' => $groupName,
                'message_id' => (string)$entryId,
                'consumer' => $workerId,
                'pool' => $poolName,
                'claimed_at' => gmdate('c'),
            ]);

            return $job;
        }

        return null;
    }

    public function supportsDispatcherAssignments(): bool
    {
        return false;
    }

    public function releaseDueJobs(?array $poolNames = null, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable();
        $poolNames = $this->normalizePoolNames($poolNames);
        $released = 0;
        $reconciled = 0;
        $reclaimed = 0;
        $prunedConsumers = 0;

        $dueMembers = $this->client->zRangeByScore(
            $this->delayedKey(),
            '-inf',
            (string)$now->getTimestamp(),
            ['limit' => [0, $this->releaseBatchSize]]
        );
        if (is_array($dueMembers) && $dueMembers !== []) {
            foreach ($dueMembers as $jobId) {
                $jobId = trim((string)$jobId);
                if ($jobId === '') {
                    continue;
                }

                $this->client->zRem($this->delayedKey(), $jobId);
                $released++;
            }
        }

        foreach ($poolNames as $poolName) {
            $reconciled += $this->backfillReadyPendingJobs($poolName, $now);
            $reclaimed += $this->reclaimStaleStreamMessages($poolName, $now);
            $prunedConsumers += $this->pruneIdleConsumers($poolName, $now);
        }

        return [
            'released_jobs' => $released,
            'reconciled_jobs' => $reconciled,
            'reclaimed_messages' => $reclaimed,
            'pruned_consumers' => $prunedConsumers,
        ];
    }

    public function reconcileJobs(array $jobIds, ?\DateTimeImmutable $now = null): int
    {
        $now = $now ?? new \DateTimeImmutable();
        $reconciled = 0;

        foreach ($jobIds as $jobId) {
            $jobId = trim((string)$jobId);
            if ($jobId === '') {
                continue;
            }

            $job = $this->findJobById($jobId);
            if (!$job instanceof JobDocument) {
                $this->clearDeliveryState($jobId);
                $this->clearQueuedState($jobId);
                continue;
            }

            if ($job->status !== 'pending') {
                $this->clearDeliveryState($jobId);
                if (!JobDocument::isActiveStatus($job->status)) {
                    $this->clearQueuedState($jobId);
                }
                continue;
            }

            $this->repairQueuedStateIfOrphaned($job);
            if ($this->hasDeliveryState($jobId)) {
                if (!$this->releasePendingDeliveryState($job)) {
                    continue;
                }
            }

            if ($this->hasQueuedState($jobId)) {
                continue;
            }

            $this->routeJobDocument($job, $now);
            $reconciled++;
        }

        return $reconciled;
    }

    private function releasePendingDeliveryState(JobDocument $job): bool
    {
        $jobId = trim((string)($job->id ?? ''));
        if ($jobId === '') {
            return false;
        }

        $delivery = $this->readJson($this->deliveryKey($jobId));
        if (!is_array($delivery)) {
            $this->clearDeliveryState($jobId);
            return true;
        }

        $streamKey = trim((string)($delivery['stream'] ?? ''));
        $groupName = trim((string)($delivery['group'] ?? ''));
        $messageId = trim((string)($delivery['message_id'] ?? ''));
        $consumer = trim((string)($delivery['consumer'] ?? ''));

        if ($streamKey !== '' && $groupName !== '' && $messageId !== '') {
            $this->ackAndDelete($streamKey, $groupName, $messageId);
        }

        $this->clearDeliveryState($jobId);
        $this->clearQueuedState($jobId);

        Log::warning('RedisStreamsQueueTransport: released stale delivery state for pending job', [
            'job_id' => $jobId,
            'pool' => $job->pool ?? null,
            'consumer' => $consumer,
            'message_id' => $messageId,
        ]);

        return true;
    }

    public function fetchPendingForPool(string $poolName, int $limit, ?\DateTimeImmutable $now = null): array
    {
        $poolName = $this->normalizePool($poolName);
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
        return false;
    }

    public function getActiveWorkerLoadMap(array $workerIds): array
    {
        return [];
    }

    public function getActiveJobClassLoadMap(array $jobClasses): array
    {
        return [];
    }

    public function afterJobCompleted(JobDocument $job, string $workerId): void
    {
        $this->ackDeliveryForJob((string)($job->id ?? ''));
    }

    public function afterJobRequeued(JobDocument $job, string $workerId, int $delaySeconds, string $errorMessage): void
    {
        $jobId = (string)($job->id ?? '');
        $this->ackDeliveryForJob($jobId);
        $this->scheduleDelayed($job);
    }

    public function afterJobTerminal(JobDocument $job, string $workerId, string $status, ?string $errorMessage = null): void
    {
        $this->ackDeliveryForJob((string)($job->id ?? ''));
    }

    private function routeJobDocument(JobDocument $job, \DateTimeImmutable $now): void
    {
        $jobId = trim((string)($job->id ?? ''));
        if ($jobId === '' || $job->status !== 'pending') {
            return;
        }

        $nextRunAt = $job->nextRunAt instanceof UTCDateTime
            ? $job->nextRunAt->toDateTime()
            : null;

        if ($nextRunAt instanceof \DateTimeInterface && $nextRunAt->getTimestamp() > $now->getTimestamp()) {
            $this->scheduleDelayed($job, $nextRunAt);
            return;
        }

        $this->publishReadyJob($job);
    }

    private function publishReadyJob(JobDocument $job): bool
    {
        $jobId = trim((string)($job->id ?? ''));
        if ($jobId === '') {
            return false;
        }

        $this->repairQueuedStateIfOrphaned($job);

        if ($this->hasDeliveryState($jobId)) {
            return false;
        }

        $poolName = $this->normalizePool($job->pool ?? null);
        $streamKey = $this->streamKey($poolName);
        $queuedKey = $this->queuedKey($jobId);

        $messageId = $this->client->eval(
            <<<'LUA'
local queuedKey = KEYS[1]
local streamKey = KEYS[2]
if redis.call('EXISTS', queuedKey) == 1 then
    return ''
end
local entryId = redis.call('XADD', streamKey, 'MAXLEN', '~', ARGV[1], '*',
    'job_id', ARGV[2],
    'pool', ARGV[3],
    'priority', ARGV[4],
    'queued_at', ARGV[5]
)
redis.call('SET', queuedKey, entryId, 'EX', ARGV[6])
return entryId
LUA,
            [
                $queuedKey,
                $streamKey,
                (string)$this->streamMaxLen,
                $jobId,
                $poolName,
                (string)((int)($job->priority ?? 1)),
                gmdate('c'),
                (string)$this->queuedTtlSeconds,
            ],
            2
        );

        return is_string($messageId) && $messageId !== '';
    }

    private function scheduleDelayed(JobDocument $job, ?\DateTimeInterface $runAt = null): void
    {
        $jobId = trim((string)($job->id ?? ''));
        if ($jobId === '') {
            return;
        }

        $runAt = $runAt ?? ($job->nextRunAt instanceof UTCDateTime ? $job->nextRunAt->toDateTime() : null);
        if (!$runAt instanceof \DateTimeInterface) {
            $runAt = new \DateTimeImmutable();
        }

        $this->clearQueuedState($jobId);
        $this->client->zAdd($this->delayedKey(), (float)$runAt->getTimestamp(), $jobId);
    }

    private function claimPendingJobDocument(string $jobId, string $workerId, ?array $workerConfig = null): ?JobDocument
    {
        $instructions = null;
        $job = $this->findJobById($jobId);
        if ($job instanceof JobDocument && is_array($workerConfig) && $workerConfig !== []) {
            $instructions = $job->instructions ?? [];
            if ($instructions instanceof \MongoDB\Model\BSONDocument) {
                $instructions = $instructions->getArrayCopy();
            } elseif ($instructions instanceof \MongoDB\Model\BSONArray) {
                $instructions = $instructions->getArrayCopy();
            } elseif ($instructions instanceof \stdClass) {
                $instructions = (array)$instructions;
            }

            if (!is_array($instructions)) {
                $instructions = [];
            }

            $instructions['worker_config'] = $workerConfig;
        }

        $now = new \DateTimeImmutable();
        $setData = [
            'status' => 'in_progress',
            'assignee' => $workerId,
            'assignedAt' => new UTCDateTime($now),
            'startedAt' => new UTCDateTime($now),
            'lastHeartbeat' => new UTCDateTime($now),
            'updatedAt' => new UTCDateTime($now),
        ];
        if ($instructions !== null) {
            $setData['instructions'] = $instructions;
        }

        $result = (new JobDocument())->findOneAndUpdate(
            [
                '_id' => new ObjectId($jobId),
                'status' => 'pending',
                'nextRunAt' => ['$lte' => new UTCDateTime($now)],
            ],
            [
                '$set' => $setData,
            ],
            [
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER,
            ]
        );

        return $this->hydrateJobDocument($result);
    }

    private function backfillReadyPendingJobs(string $poolName, \DateTimeImmutable $now): int
    {
        $published = 0;
        $limit = max($this->reconcileBatchSize * 4, $this->reconcileBatchSize);
        foreach ($this->fetchPendingForPool($poolName, $limit, $now) as $job) {
            $jobId = trim((string)($job->id ?? ''));
            if ($jobId === '') {
                continue;
            }

            $this->repairQueuedStateIfOrphaned($job);

            if ($this->hasQueuedState($jobId) || $this->hasDeliveryState($jobId)) {
                continue;
            }

            if ($this->publishReadyJob($job)) {
                $published++;
            }

            if ($published >= $this->reconcileBatchSize) {
                break;
            }
        }

        return $published;
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
        $backfillBudgets = [];

        foreach ($jobs as $job) {
            if (!$job instanceof JobDocument) {
                continue;
            }

            $fairnessKey = trim((string)($job->fairness_key ?? ''));
            if (
                strtolower(trim((string)($job->lane ?? ''))) !== 'backfill'
                || $fairnessKey === ''
                || !isset(self::BACKFILL_FAIRNESS_LIMITS[$this->normalizePool($job->pool ?? null)])
            ) {
                $ordered[] = $job;
                continue;
            }

            if (!array_key_exists($fairnessKey, $backfillBudgets)) {
                $poolName = $this->normalizePool($job->pool ?? null);
                $readyLimit = $this->resolveReadyBackfillLimit($poolName, $fairnessKey);
                $activeCount = $this->countActiveBackfillJobsForFairnessKey($poolName, $fairnessKey);
                $backfillBudgets[$fairnessKey] = max(0, $readyLimit - $activeCount);
            }

            if ($backfillBudgets[$fairnessKey] < 1) {
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
                $backfillBudgets[$fairnessKey] = max(0, (int)($backfillBudgets[$fairnessKey] ?? 0) - 1);

                if ($backfillQueues[$fairnessKey] !== [] && (int)($backfillBudgets[$fairnessKey] ?? 0) > 0) {
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

    private function resolveReadyBackfillLimit(string $poolName, string $fairnessKey): int
    {
        $config = self::BACKFILL_FAIRNESS_LIMITS[$poolName] ?? null;
        if (!is_array($config)) {
            return 0;
        }

        return $this->countBackfillFairnessGroups($poolName, $fairnessKey) > 1
            ? max(1, (int)($config['shared'] ?? 1))
            : max(1, (int)($config['single'] ?? 1));
    }

    private function countActiveBackfillJobsForFairnessKey(string $poolName, string $fairnessKey): int
    {
        return (int)(new JobDocument())->getCollection()->countDocuments([
            'pool' => $poolName,
            'lane' => 'backfill',
            'fairness_key' => $fairnessKey,
            'status' => ['$in' => ['assigned', 'in_progress']],
        ]);
    }

    private function countBackfillFairnessGroups(string $poolName, string $currentFairnessKey): int
    {
        $keys = (new JobDocument())->getCollection()->distinct('fairness_key', [
            'pool' => $poolName,
            'lane' => 'backfill',
            'status' => ['$in' => ['pending', 'assigned', 'in_progress']],
            'fairness_key' => ['$exists' => true, '$nin' => ['', null]],
        ]);

        $unique = [];
        foreach ((array)$keys as $key) {
            $normalized = trim((string)$key);
            if ($normalized !== '') {
                $unique[$normalized] = true;
            }
        }

        $currentFairnessKey = trim($currentFairnessKey);
        if ($currentFairnessKey !== '') {
            $unique[$currentFairnessKey] = true;
        }

        return max(1, count($unique));
    }

    private function reclaimStaleStreamMessages(string $poolName, \DateTimeImmutable $now): int
    {
        $poolName = $this->normalizePool($poolName);
        $streamKey = $this->streamKey($poolName);
        $groupName = $this->groupName($poolName);
        $this->ensureConsumerGroup($poolName);

        $processed = 0;
        $startId = '0-0';
        $consumer = $this->reclaimerConsumerName();
        $idleMs = max(1000, $this->resolveStaleThresholdSeconds($poolName) * 1000);

        while ($processed < $this->reclaimBatchSize) {
            $response = $this->client->xAutoClaim(
                $streamKey,
                $groupName,
                $consumer,
                $idleMs,
                $startId,
                min(25, $this->reclaimBatchSize - $processed)
            );

            if (!is_array($response) || count($response) < 2) {
                break;
            }

            $startId = (string)($response[0] ?? '0-0');
            $entries = $response[1] ?? [];
            if (!is_array($entries) || $entries === []) {
                break;
            }

            foreach ($entries as $entryId => $fields) {
                $processed++;
                $jobId = trim((string)($fields['job_id'] ?? ''));
                if ($jobId === '') {
                    $this->ackAndDelete($streamKey, $groupName, (string)$entryId);
                    continue;
                }

                $job = $this->findJobById($jobId);
                if (!$job instanceof JobDocument) {
                    $this->clearDeliveryState($jobId);
                    $this->clearQueuedState($jobId);
                    $this->ackAndDelete($streamKey, $groupName, (string)$entryId);
                    continue;
                }

                if ($job->status === 'pending') {
                    $this->clearDeliveryState($jobId);
                    $this->ackAndDelete($streamKey, $groupName, (string)$entryId);
                    $this->clearQueuedState($jobId);
                    $this->routeJobDocument($job, $now);
                    continue;
                }

                if (!JobDocument::isActiveStatus($job->status)) {
                    $this->clearDeliveryState($jobId);
                    $this->clearQueuedState($jobId);
                    $this->ackAndDelete($streamKey, $groupName, (string)$entryId);
                    continue;
                }

                $this->writeDeliveryState($jobId, [
                    'stream' => $streamKey,
                    'group' => $groupName,
                    'message_id' => (string)$entryId,
                    'consumer' => $consumer,
                    'pool' => $poolName,
                    'claimed_at' => gmdate('c'),
                ]);
            }
        }

        return $processed;
    }

    private function pruneIdleConsumers(string $poolName, \DateTimeImmutable $now): int
    {
        $poolName = $this->normalizePool($poolName);
        if (!$this->shouldRunConsumerCleanup($poolName, $now)) {
            return 0;
        }

        $removed = 0;
        $streamKey = $this->streamKey($poolName);
        $groupName = $this->groupName($poolName);
        $activeWorkers = $this->getActiveWorkerIds($now);
        $idleThresholdMs = max(1000, $this->consumerMaxIdleSeconds * 1000);

        try {
            $rows = $this->client->rawCommand('XINFO', 'CONSUMERS', $streamKey, $groupName);
        } catch (\Throwable $e) {
            $this->markConsumerCleanupRun($poolName, $now);
            Log::warning('RedisStreamsQueueTransport: failed fetching stream consumers', [
                'stream' => $streamKey,
                'group' => $groupName,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }

        foreach ($this->normalizeConsumerInfoRows($rows) as $consumer) {
            $name = trim((string)($consumer['name'] ?? ''));
            if ($name === '' || $name === $this->reclaimerConsumerName()) {
                continue;
            }

            if (isset($activeWorkers[$name])) {
                continue;
            }

            $pending = max(0, (int)($consumer['pending'] ?? 0));
            $idleMs = max(0, (int)($consumer['idle'] ?? 0));
            if ($pending > 0 || $idleMs < $idleThresholdMs) {
                continue;
            }

            try {
                $result = $this->client->xGroup('DELCONSUMER', $streamKey, $groupName, $name);
                if ($result !== false) {
                    $removed++;
                }
            } catch (\Throwable $e) {
                Log::warning('RedisStreamsQueueTransport: failed pruning idle consumer', [
                    'stream' => $streamKey,
                    'group' => $groupName,
                    'consumer' => $name,
                    'error' => $e->getMessage(),
                ]);
            }

            if ($removed >= $this->consumerCleanupBatchSize) {
                break;
            }
        }

        $this->markConsumerCleanupRun($poolName, $now);

        if ($removed > 0) {
            Log::info('RedisStreamsQueueTransport: pruned idle consumers', [
                'pool' => $poolName,
                'removed' => $removed,
            ]);
        }

        return $removed;
    }

    private function ackDeliveryForJob(string $jobId): void
    {
        $jobId = trim($jobId);
        if ($jobId === '') {
            return;
        }

        $delivery = $this->readJson($this->deliveryKey($jobId));
        if (is_array($delivery)) {
            $streamKey = trim((string)($delivery['stream'] ?? ''));
            $groupName = trim((string)($delivery['group'] ?? ''));
            $messageId = trim((string)($delivery['message_id'] ?? ''));
            if ($streamKey !== '' && $groupName !== '' && $messageId !== '') {
                $this->ackAndDelete($streamKey, $groupName, $messageId);
            }
        }

        $this->clearDeliveryState($jobId);
        $this->clearQueuedState($jobId);
    }

    private function repairQueuedStateIfOrphaned(JobDocument $job): bool
    {
        $jobId = trim((string)($job->id ?? ''));
        if ($jobId === '' || !$this->hasQueuedState($jobId) || $this->hasDeliveryState($jobId)) {
            return false;
        }

        $queuedEntryId = trim((string)$this->client->get($this->queuedKey($jobId)));
        if ($queuedEntryId === '') {
            $this->clearQueuedState($jobId);
            return true;
        }

        $streamKey = $this->streamKey($this->normalizePool($job->pool ?? null));
        if ($this->streamEntryExists($streamKey, $queuedEntryId)) {
            return false;
        }

        $this->clearQueuedState($jobId);
        Log::warning('RedisStreamsQueueTransport: cleared orphaned queued marker', [
            'job_id' => $jobId,
            'stream' => $streamKey,
            'message_id' => $queuedEntryId,
        ]);

        return true;
    }

    private function streamEntryExists(string $streamKey, string $entryId): bool
    {
        $entryId = trim($entryId);
        if ($entryId === '') {
            return false;
        }

        try {
            $rows = $this->client->xRange($streamKey, $entryId, $entryId, 1);
            return is_array($rows) && $rows !== [];
        } catch (\Throwable $e) {
            Log::warning('RedisStreamsQueueTransport: failed checking stream entry', [
                'stream' => $streamKey,
                'message_id' => $entryId,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }

    private function ackAndDelete(string $streamKey, string $groupName, string $messageId): void
    {
        try {
            $this->client->xAck($streamKey, $groupName, [$messageId]);
            $this->client->xDel($streamKey, [$messageId]);
        } catch (\Throwable $e) {
            Log::warning('RedisStreamsQueueTransport: failed to ack/delete message', [
                'stream' => $streamKey,
                'group' => $groupName,
                'message_id' => $messageId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function ensureConsumerGroup(string $poolName): void
    {
        $streamKey = $this->streamKey($poolName);
        $groupName = $this->groupName($poolName);

        try {
            $this->client->xGroup('CREATE', $streamKey, $groupName, '0', true);
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'BUSYGROUP') !== false) {
                return;
            }
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function normalizeConsumerInfoRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }

            if (array_is_list($row)) {
                $entry = [];
                $count = count($row);
                for ($i = 0; $i < $count; $i += 2) {
                    $key = isset($row[$i]) ? trim((string)$row[$i]) : '';
                    if ($key === '') {
                        continue;
                    }

                    $entry[$key] = $row[$i + 1] ?? null;
                }
            } else {
                $entry = $row;
            }

            if ($entry !== []) {
                $normalized[] = $entry;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, bool>
     */
    private function getActiveWorkerIds(\DateTimeImmutable $now): array
    {
        try {
            $rows = $this->client->hGetAll($this->workersKey());
        } catch (\Throwable) {
            return [];
        }

        if (!is_array($rows) || $rows === []) {
            return [];
        }

        $active = [];
        $cutoffTs = $now->getTimestamp() - $this->resolveWorkerStateStaleThresholdSeconds();
        foreach ($rows as $workerId => $encoded) {
            $payload = json_decode((string)$encoded, true);
            if (!is_array($payload)) {
                continue;
            }

            $status = trim((string)($payload['status'] ?? ''));
            $updatedAt = trim((string)($payload['updated_at'] ?? ''));
            $updatedTs = $updatedAt !== '' ? strtotime($updatedAt) : false;
            if ($status !== 'running' || $updatedTs === false || $updatedTs < $cutoffTs) {
                continue;
            }

            $resolvedWorkerId = trim((string)($payload['worker_id'] ?? $workerId));
            if ($resolvedWorkerId !== '') {
                $active[$resolvedWorkerId] = true;
            }
        }

        return $active;
    }

    private function resolveWorkerStateStaleThresholdSeconds(): int
    {
        $explicit = $this->envInt('JOB_WORKER_STATE_STALE_SECONDS');
        if ($explicit !== null && $explicit > 0) {
            return $explicit;
        }

        $heartbeat = $this->envInt('JOB_WORKER_STATE_HEARTBEAT_SECONDS') ?? 10;
        return max(15, $heartbeat * 3);
    }

    private function shouldRunConsumerCleanup(string $poolName, \DateTimeImmutable $now): bool
    {
        $lastRunRaw = trim((string)$this->client->get($this->consumerCleanupKey($poolName)));
        $lastRunTs = ctype_digit($lastRunRaw) ? (int)$lastRunRaw : 0;
        if ($lastRunTs > 0 && ($now->getTimestamp() - $lastRunTs) < $this->consumerCleanupIntervalSeconds) {
            return false;
        }

        return true;
    }

    private function markConsumerCleanupRun(string $poolName, \DateTimeImmutable $now): void
    {
        $ttl = max(300, $this->consumerCleanupIntervalSeconds * 4);
        $this->client->setEx($this->consumerCleanupKey($poolName), $ttl, (string)$now->getTimestamp());
    }

    private function normalizePoolNames(?array $poolNames): array
    {
        $resolved = [];
        foreach (($poolNames ?? array_keys($this->poolManager->getAllPools())) as $poolName) {
            $normalized = $this->normalizePool((string)$poolName);
            if (!$this->poolManager->poolExists($normalized)) {
                continue;
            }
            $resolved[] = $normalized;
        }

        return array_values(array_unique($resolved));
    }

    private function normalizePool(?string $poolName): string
    {
        $normalized = strtolower(trim((string)$poolName));
        if ($normalized === '' || !$this->poolManager->poolExists($normalized)) {
            return 'general';
        }

        return $normalized;
    }

    private function resolveStaleThresholdSeconds(string $poolName): int
    {
        $envThreshold = (int)($this->envInt('STALE_JOB_THRESHOLD') ?? 0);
        $heartbeatInterval = max(5, (int)($this->envInt('HEARTBEAT_INTERVAL') ?? 10));
        $minimumFromHeartbeat = $heartbeatInterval * 6;

        // Active long-running work keeps heartbeating; stopped heartbeats should be
        // reclaimed on the heartbeat window, independent of the pool timeout.
        if ($envThreshold > 0) {
            return max($envThreshold, $minimumFromHeartbeat);
        }

        return $minimumFromHeartbeat;
    }

    private function writeDeliveryState(string $jobId, array $payload): void
    {
        $this->client->setex(
            $this->deliveryKey($jobId),
            $this->deliveryTtlSeconds,
            json_encode($payload, JSON_UNESCAPED_SLASHES)
        );
    }

    private function hasDeliveryState(string $jobId): bool
    {
        return $this->client->exists($this->deliveryKey($jobId)) > 0;
    }

    private function clearDeliveryState(string $jobId): void
    {
        $this->client->del($this->deliveryKey($jobId));
    }

    private function hasQueuedState(string $jobId): bool
    {
        return $this->client->exists($this->queuedKey($jobId)) > 0;
    }

    private function clearQueuedState(string $jobId): void
    {
        $this->client->del($this->queuedKey($jobId));
    }

    private function findJobById(string $jobId): ?JobDocument
    {
        if (!preg_match('/^[a-f0-9]{24}$/i', $jobId)) {
            return null;
        }

        return JobDocument::findOne(['_id' => new ObjectId($jobId)]);
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

    private function streamKey(string $poolName): string
    {
        return "{$this->prefix}:stream:{$poolName}";
    }

    private function groupName(string $poolName): string
    {
        return "{$this->prefix}:workers:{$poolName}";
    }

    private function delayedKey(): string
    {
        return "{$this->prefix}:delayed";
    }

    private function workersKey(): string
    {
        return "{$this->prefix}:workers";
    }

    private function queuedKey(string $jobId): string
    {
        return "{$this->prefix}:queued:{$jobId}";
    }

    private function deliveryKey(string $jobId): string
    {
        return "{$this->prefix}:delivery:{$jobId}";
    }

    private function reclaimerConsumerName(): string
    {
        return "{$this->prefix}:dispatcher-reclaimer";
    }

    private function consumerCleanupKey(string $poolName): string
    {
        return "{$this->prefix}:consumer-cleanup:{$poolName}";
    }

    private function connect(): bool
    {
        try {
            $readTimeoutSeconds = max(1.0, ((float)$this->claimBlockMs / 1000.0) + 1.0);
            $client = new RedisClientProxy(
                $this->connection,
                static function (\Redis $redis) use ($readTimeoutSeconds): void {
                    $redis->setOption(\Redis::OPT_READ_TIMEOUT, $readTimeoutSeconds);
                },
                'RedisStreamsQueueTransport'
            );
            $client->ping();
            $this->client = $client;
            $this->connected = true;

            return true;
        } catch (\Throwable $e) {
            Log::warning('RedisStreamsQueueTransport: failed to connect to Redis', [
                'error' => $e->getMessage(),
                'label' => (string)($this->connection['label'] ?? 'RedisStreamsQueueTransport'),
            ]);

            return false;
        }
    }

    private function probeIdleRedisConnection(): void
    {
        if (!$this->client instanceof RedisClientProxy) {
            return;
        }

        $now = microtime(true);
        if (($now - $this->lastIdleHealthCheckAt) < 1.0) {
            return;
        }

        $this->lastIdleHealthCheckAt = $now;

        try {
            $this->client->ping();
        } catch (\Throwable) {
        }
    }

    private function shouldEnsureIndexes(): bool
    {
        $raw = strtolower(trim((string)($this->envString('JOB_QUEUE_AUTO_ENSURE_INDEXES') ?? '0')));
        return in_array($raw, ['1', 'true', 'yes', 'on'], true);
    }

    private function readJson(string $key): ?array
    {
        $raw = (string)$this->client->get($key);
        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function envString(string $key): ?string
    {
        $value = $_ENV[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return null;
        }

        return (string)$value;
    }

    private function envInt(string $key): ?int
    {
        $value = $this->envString($key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    private function envFloat(string $key): ?float
    {
        $value = $this->envString($key);
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (float)$value;
    }
}
