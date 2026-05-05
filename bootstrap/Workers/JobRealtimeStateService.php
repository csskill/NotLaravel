<?php

namespace Nraa\Workers;

use Nraa\Database\Drivers\MongoDBDriver;
use Nraa\Pillars\Log;
use Nraa\Services\Infrastructure\RedisClientProxy;
use Nraa\Services\Infrastructure\RedisConnectionResolver;
use Nraa\Workers\Documents\JobDocument;

final class JobRealtimeStateService
{
    private ?RedisClientProxy $client = null;
    private bool $enabled = false;
    /** @var array<string,mixed> */
    private array $connection = [];
    private string $prefix = 'jobs';
    private int $eventStreamMaxLen = 10000;
    private int $recentFailureListMaxLen = 100;
    private int $recentActivityListMaxLen = 200;
    private int $timelineRetentionMinutes = 1440;
    private static ?self $instance = null;

    public function __construct()
    {
        $this->prefix = trim((string)($this->envString('JOB_REDIS_PREFIX') ?? 'jobs')) ?: 'jobs';
        $this->connection = RedisConnectionResolver::fromEnvPrefixes(
            ['JOB_REDIS', 'REDIS'],
            [
                'label' => 'JobRealtimeStateService',
                'database' => 9,
                'timeout_seconds' => 0.5,
            ]
        );
        $this->eventStreamMaxLen = max(1000, (int)($this->envInt('JOB_EVENT_STREAM_MAXLEN') ?? 10000));
        $this->recentFailureListMaxLen = max(10, (int)($this->envInt('JOB_RECENT_FAILURE_LIST_MAXLEN') ?? 100));
        $this->recentActivityListMaxLen = max(20, (int)($this->envInt('JOB_RECENT_ACTIVITY_LIST_MAXLEN') ?? 200));
        $this->timelineRetentionMinutes = max(180, (int)($this->envInt('JOB_TIMELINE_RETENTION_MINUTES') ?? 1440));
        $this->enabled = $this->connect();
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->client instanceof RedisClientProxy;
    }

    public function registerWorker(string $workerId, string $poolName, array $metadata = []): void
    {
        $this->writeWorkerState($workerId, $poolName, 'running', $metadata);
        $this->appendEvent('worker_started', [
            'worker_id' => $workerId,
            'pool' => $poolName,
        ]);
    }

    public function heartbeatWorker(string $workerId, string $poolName, array $metadata = []): void
    {
        $this->writeWorkerState($workerId, $poolName, 'running', $metadata);
    }

    public function unregisterWorker(string $workerId, string $poolName, array $metadata = []): void
    {
        $this->writeWorkerState($workerId, $poolName, 'stopped', $metadata);
        $this->appendEvent('worker_stopped', [
            'worker_id' => $workerId,
            'pool' => $poolName,
        ]);
    }

    public function recordQueued(array $jobData, ?string $jobId = null): void
    {
        $pool = $this->normalizePool($jobData['pool'] ?? null);
        $task = is_array($jobData['task'] ?? null) ? $jobData['task'] : [];
        $this->appendEvent('job_queued', [
            'job_id' => $jobId ?? '',
            'pool' => $pool,
            'status' => 'pending',
            'priority' => (string)((int)($jobData['priority'] ?? 1)),
            'job_class' => trim((string)($task['class'] ?? '')),
            'job_method' => trim((string)($task['method'] ?? '')),
            'employer' => trim((string)($jobData['employer'] ?? '')),
            'attempts' => (string)((int)($jobData['attempts'] ?? 0)),
            'max_attempts' => (string)((int)($jobData['maxAttempts'] ?? $jobData['maxRetries'] ?? 3)),
        ]);
    }

    public function recordAssigned(JobDocument $job, string $workerId): void
    {
        $this->writeInflightState($job, $workerId, 'assigned');
        $this->appendEvent('job_assigned', [
            'job_id' => (string)($job->id ?? ''),
            'worker_id' => $workerId,
            'pool' => $this->normalizePool($job->pool ?? null),
            'status' => 'assigned',
            'job_class' => trim((string)($job->task['class'] ?? '')),
        ]);
    }

    public function recordStarted(JobDocument $job, string $workerId): void
    {
        $this->writeInflightState($job, $workerId, 'in_progress');
        $this->appendEvent('job_started', [
            'job_id' => (string)($job->id ?? ''),
            'worker_id' => $workerId,
            'pool' => $this->normalizePool($job->pool ?? null),
            'status' => 'in_progress',
            'job_class' => trim((string)($job->task['class'] ?? '')),
        ]);
    }

    public function recordHeartbeat(string $jobId): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $key = $this->inflightKey($jobId);
        try {
            if ($this->client->exists($key)) {
                $payload = $this->decodeJson((string)$this->client->get($key));
                $payload['last_seen_at'] = gmdate('c');
                $this->client->setex($key, 3600, json_encode($payload, JSON_UNESCAPED_SLASHES));
            }
        } catch (\Throwable) {
        }
    }

    public function recordRequeued(JobDocument $job, string $workerId, int $delaySeconds, string $error): void
    {
        $this->deleteInflightState((string)($job->id ?? ''));
        $this->appendEvent('job_requeued', [
            'job_id' => (string)($job->id ?? ''),
            'worker_id' => $workerId,
            'pool' => $this->normalizePool($job->pool ?? null),
            'status' => 'pending',
            'next_retry_delay' => (string)max(0, $delaySeconds),
            'attempts' => (string)((int)($job->attempts ?? 0)),
            'error' => trim($error),
            'job_class' => trim((string)($job->task['class'] ?? '')),
        ]);
    }

    public function recordCompleted(JobDocument $job, string $workerId): void
    {
        $this->deleteInflightState((string)($job->id ?? ''));
        $this->appendEvent('job_completed', [
            'job_id' => (string)($job->id ?? ''),
            'worker_id' => $workerId,
            'pool' => $this->normalizePool($job->pool ?? null),
            'status' => 'completed',
            'job_class' => trim((string)($job->task['class'] ?? '')),
        ]);
    }

    public function recordTerminal(JobDocument $job, string $workerId, string $status, ?string $error = null): void
    {
        $jobId = (string)($job->id ?? '');
        $status = trim($status) !== '' ? trim($status) : 'failed';
        $this->deleteInflightState($jobId);
        $payload = [
            'job_id' => $jobId,
            'worker_id' => $workerId,
            'pool' => $this->normalizePool($job->pool ?? null),
            'status' => $status,
            'job_class' => trim((string)($job->task['class'] ?? '')),
            'attempts' => (string)((int)($job->attempts ?? 0)),
        ];
        if (trim((string)$error) !== '') {
            $payload['error'] = trim((string)$error);
        }

        $this->appendEvent('job_' . $status, $payload);

        if (in_array($status, ['failed', 'auto_resolved', 'manually_resolved', 'disabled'], true)) {
            $this->pushRecentFailure($payload);
        }
    }

    /**
     * Rebuild hot counters and inflight state from Mongo. This protects the Redis
     * snapshot from drift and lets the adminpanel use Redis as a fast read path later.
     */
    public function syncSnapshotFromMongo(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $collection = MongoDBDriver::getInstance()->getCollection('jobs');
            $dashboardStatuses = ['pending', 'assigned', 'in_progress', 'failed'];
            $rows = $collection->aggregate([
                [
                    '$match' => [
                        'status' => ['$in' => $dashboardStatuses],
                    ],
                ],
                [
                    '$project' => [
                        'status' => ['$ifNull' => ['$status', '']],
                        'pool' => [
                            '$let' => [
                                'vars' => [
                                    'poolName' => [
                                        '$trim' => [
                                            'input' => ['$ifNull' => ['$pool', '']],
                                        ],
                                    ],
                                ],
                                'in' => [
                                    '$cond' => [
                                        ['$eq' => ['$$poolName', '']],
                                        'general',
                                        '$$poolName',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    '$group' => [
                        '_id' => [
                            'status' => '$status',
                            'pool' => '$pool',
                        ],
                        'count' => ['$sum' => 1],
                    ],
                ],
            ])->toArray();

            $global = [];
            $perPool = [];
            foreach ($rows as $row) {
                $key = is_array($row['_id'] ?? null) ? $row['_id'] : (array)($row['_id'] ?? []);
                $status = trim((string)($key['status'] ?? ''));
                $pool = $this->normalizePool($key['pool'] ?? null);
                if ($status === '') {
                    continue;
                }

                $count = (int)($row['count'] ?? 0);
                $global[$status] = (int)($global[$status] ?? 0) + $count;
                if (!isset($perPool[$pool])) {
                    $perPool[$pool] = [];
                }
                $perPool[$pool][$status] = $count;
            }

            $activeRows = $collection->find([
                'status' => ['$in' => ['assigned', 'in_progress']],
            ], [
                'projection' => [
                    '_id' => 1,
                    'status' => 1,
                    'pool' => 1,
                    'assignee' => 1,
                    'task' => 1,
                    'attempts' => 1,
                    'updatedAt' => 1,
                ],
                'typeMap' => ['root' => 'array', 'document' => 'array'],
            ])->toArray();

            $this->deleteMatchingKeys($this->prefix . ':inflight:*');
            $this->client->set($this->globalCountsKey(), json_encode($global, JSON_UNESCAPED_SLASHES));
            $this->client->set($this->poolCountsKey(), json_encode($perPool, JSON_UNESCAPED_SLASHES));
            $this->client->set($this->snapshotMetaKey(), json_encode([
                'synced_at' => gmdate('c'),
            ], JSON_UNESCAPED_SLASHES));
            foreach ($activeRows as $row) {
                $jobId = trim((string)($row['_id'] ?? ''));
                if ($jobId === '') {
                    continue;
                }

                $task = is_array($row['task'] ?? null) ? $row['task'] : [];
                $payload = [
                    'job_id' => $jobId,
                    'pool' => $this->normalizePool($row['pool'] ?? null),
                    'worker_id' => trim((string)($row['assignee'] ?? '')),
                    'status' => trim((string)($row['status'] ?? '')),
                    'job_class' => trim((string)($task['class'] ?? '')),
                    'attempts' => (int)($row['attempts'] ?? 0),
                    'last_seen_at' => gmdate('c'),
                ];
                $this->client->setex($this->inflightKey($jobId), 3600, json_encode($payload, JSON_UNESCAPED_SLASHES));
            }

            $this->writeDepthSnapshot($global, $perPool, $this->getRunningWorkers());

            $this->appendEvent('snapshot_synced', [
                'global_statuses' => (string)count($global),
                'active_jobs' => (string)count($activeRows),
            ]);
        } catch (\Throwable $e) {
            Log::warning('JobRealtimeStateService: snapshot sync failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getSnapshot(): array
    {
        if (!$this->isEnabled()) {
            return [
                'enabled' => false,
                'global' => [],
                'pools' => [],
                'workers' => [],
                'recent_failures' => [],
            ];
        }

        try {
            $global = $this->decodeJson((string)$this->client->get($this->globalCountsKey()));
            $pools = $this->decodeJson((string)$this->client->get($this->poolCountsKey()));
            $meta = $this->decodeJson((string)$this->client->get($this->snapshotMetaKey()));
            $workers = $this->decodeJsonMap($this->client->hGetAll($this->workersKey()));
            $recentFailures = array_map(
                fn($entry) => $this->decodeJson((string)$entry),
                $this->client->lRange($this->recentFailuresKey(), 0, min(49, $this->recentFailureListMaxLen - 1))
            );

            return [
                'enabled' => true,
                'global' => is_array($global) ? $global : [],
                'pools' => is_array($pools) ? $pools : [],
                'generated_at' => trim((string)($meta['synced_at'] ?? '')),
                'workers' => $workers,
                'recent_failures' => $recentFailures,
            ];
        } catch (\Throwable) {
            return [
                'enabled' => false,
                'global' => [],
                'pools' => [],
                'generated_at' => '',
                'workers' => [],
                'recent_failures' => [],
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function getDashboardBootstrap(int $windowMinutes = 180): array
    {
        $windowMinutes = max(30, min($windowMinutes, $this->timelineRetentionMinutes));

        $snapshot = $this->getSnapshot();
        $workers = $this->getRunningWorkers();
        $workerSummary = $this->buildWorkerSummary($workers);

        return [
            'enabled' => (bool)($snapshot['enabled'] ?? false),
            'generated_at' => trim((string)($snapshot['generated_at'] ?? '')),
            'snapshot' => $snapshot,
            'worker_summary' => $workerSummary,
            'timeline' => $this->getTimelineBuckets($windowMinutes),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRunningWorkers(?int $staleAfterSeconds = null): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $staleAfterSeconds = $staleAfterSeconds ?? $this->resolveWorkerStaleThresholdSeconds();
        $cutoffTs = time() - max(5, $staleAfterSeconds);

        try {
            $rows = $this->decodeJsonMap($this->client->hGetAll($this->workersKey()));
            $running = [];
            $staleWorkerIds = [];

            foreach ($rows as $workerId => $payload) {
                if (!is_array($payload)) {
                    continue;
                }

                $status = trim((string)($payload['status'] ?? ''));
                $updatedAt = trim((string)($payload['updated_at'] ?? ''));
                $updatedTs = $updatedAt !== '' ? strtotime($updatedAt) : false;

                if ($status !== 'running' || $updatedTs === false || $updatedTs < $cutoffTs) {
                    $staleWorkerIds[] = (string)$workerId;
                    continue;
                }

                $payload['worker_id'] = trim((string)($payload['worker_id'] ?? $workerId));
                $payload['pool'] = $this->normalizePool($payload['pool'] ?? null);
                $payload['worker_index'] = (int)($payload['worker_index'] ?? 0);
                $running[] = $payload;
            }

            if ($staleWorkerIds !== []) {
                $this->client->hDel($this->workersKey(), ...$staleWorkerIds);
            }

            return $running;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRecentActivity(int $limit = 20): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $limit = max(1, min($limit, $this->recentActivityListMaxLen));

        try {
            return array_values(array_filter(array_map(
                fn($entry) => $this->decodeJson((string)$entry),
                $this->client->lRange($this->recentActivityKey(), 0, $limit - 1)
            ), static fn($row): bool => is_array($row) && $row !== []));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTimelineBuckets(int $windowMinutes = 180): array
    {
        if (!$this->isEnabled()) {
            return [];
        }

        $windowMinutes = max(1, min($windowMinutes, $this->timelineRetentionMinutes));
        $endMinuteTs = (int)(floor(time() / 60) * 60);
        $startMinuteTs = $endMinuteTs - (($windowMinutes - 1) * 60);

        $minuteTimestamps = [];
        for ($ts = $startMinuteTs; $ts <= $endMinuteTs; $ts += 60) {
            $minuteTimestamps[] = $ts;
        }

        if ($minuteTimestamps === []) {
            return [];
        }

        try {
            $pipe = $this->client->multi(\Redis::PIPELINE);
            foreach ($minuteTimestamps as $ts) {
                $pipe->hGetAll($this->timelineBucketKey($this->bucketStampFromTimestamp($ts)));
            }
            $rows = $pipe->exec();
            if (!is_array($rows)) {
                return [];
            }

            $result = [];
            foreach ($minuteTimestamps as $index => $ts) {
                $row = is_array($rows[$index] ?? null) ? $rows[$index] : [];
                $result[] = [
                    'minute' => gmdate('c', $ts),
                    'label' => gmdate('H:i', $ts),
                    'queued' => (int)($row['queued'] ?? 0),
                    'assigned' => (int)($row['assigned'] ?? 0),
                    'started' => (int)($row['started'] ?? 0),
                    'completed' => (int)($row['completed'] ?? 0),
                    'failed' => (int)($row['failed'] ?? 0),
                    'requeued' => (int)($row['requeued'] ?? 0),
                    'manually_resolved' => (int)($row['manually_resolved'] ?? 0),
                    'auto_resolved' => (int)($row['auto_resolved'] ?? 0),
                    'disabled' => (int)($row['disabled'] ?? 0),
                    'pending_depth' => (int)($row['pending_depth'] ?? 0),
                    'assigned_depth' => (int)($row['assigned_depth'] ?? 0),
                    'in_progress_depth' => (int)($row['in_progress_depth'] ?? 0),
                    'failed_depth' => (int)($row['failed_depth'] ?? 0),
                    'workers_running' => (int)($row['workers_running'] ?? 0),
                ];
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    private function writeWorkerState(string $workerId, string $poolName, string $status, array $metadata = []): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $existing = $this->decodeJson((string)$this->client->hGet($this->workersKey(), $workerId));
            $payload = [
                'worker_id' => $workerId,
                'pool' => $this->normalizePool($poolName),
                'status' => $status,
                'pid' => (int)($metadata['pid'] ?? $existing['pid'] ?? 0),
                'worker_index' => (int)($metadata['worker_index'] ?? $existing['worker_index'] ?? -1),
                'capacity' => max(1, (int)($metadata['capacity'] ?? $existing['capacity'] ?? 1)),
                'timeout' => max(0, (int)($metadata['timeout'] ?? $existing['timeout'] ?? 0)),
                'updated_at' => gmdate('c'),
            ];
            $this->client->hSet($this->workersKey(), $workerId, json_encode($payload, JSON_UNESCAPED_SLASHES));
            $this->refreshWorkerTimelineSnapshot();
        } catch (\Throwable) {
        }
    }

    private function writeInflightState(JobDocument $job, string $workerId, string $status): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $jobId = trim((string)($job->id ?? ''));
        if ($jobId === '') {
            return;
        }

        try {
            $payload = [
                'job_id' => $jobId,
                'pool' => $this->normalizePool($job->pool ?? null),
                'worker_id' => $workerId,
                'status' => $status,
                'job_class' => trim((string)($job->task['class'] ?? '')),
                'attempts' => (int)($job->attempts ?? 0),
                'last_seen_at' => gmdate('c'),
            ];
            $this->client->setex($this->inflightKey($jobId), 3600, json_encode($payload, JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
        }
    }

    private function deleteInflightState(string $jobId): void
    {
        if (!$this->isEnabled() || $jobId === '') {
            return;
        }

        try {
            $this->client->del($this->inflightKey($jobId));
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function appendEvent(string $event, array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $timestamp = gmdate('c');
        $fields = ['event' => $event, 'timestamp' => $timestamp];
        foreach ($payload as $key => $value) {
            if ($value === null) {
                continue;
            }
            if (is_scalar($value)) {
                $fields[$key] = (string)$value;
                continue;
            }
            $fields[$key] = json_encode($value, JSON_UNESCAPED_SLASHES) ?: '';
        }

        try {
            $this->client->xAdd($this->eventsKey(), '*', $fields, $this->eventStreamMaxLen, true);
        } catch (\Throwable) {
        }

        $this->trackActivityEvent($event, $payload, $timestamp);
        $this->updateTimelineMetrics($event, $payload, $timestamp);
        $this->publishEvent($event, $payload, $timestamp);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function pushRecentFailure(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                return;
            }
            $this->client->lPush($this->recentFailuresKey(), $encoded);
            $this->client->lTrim($this->recentFailuresKey(), 0, $this->recentFailureListMaxLen - 1);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function pushRecentActivity(array $payload): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($encoded)) {
                return;
            }

            $this->client->lPush($this->recentActivityKey(), $encoded);
            $this->client->lTrim($this->recentActivityKey(), 0, $this->recentActivityListMaxLen - 1);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publishEvent(string $event, array $payload, string $timestamp): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $message = [
            'event' => $event,
            'timestamp' => $timestamp,
            'payload' => $payload,
        ];

        try {
            $encoded = json_encode($message, JSON_UNESCAPED_SLASHES);
            if (is_string($encoded) && $encoded !== '') {
                $this->client->publish($this->eventsChannel(), $encoded);
            }
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function trackActivityEvent(string $event, array $payload, string $timestamp): void
    {
        if (!$this->shouldTrackActivityEvent($event)) {
            return;
        }

        $this->pushRecentActivity([
            'event' => $event,
            'timestamp' => $timestamp,
            'job_id' => trim((string)($payload['job_id'] ?? '')),
            'worker_id' => trim((string)($payload['worker_id'] ?? '')),
            'pool' => $this->normalizePool($payload['pool'] ?? null),
            'status' => trim((string)($payload['status'] ?? '')),
            'job_class' => trim((string)($payload['job_class'] ?? '')),
            'job_method' => trim((string)($payload['job_method'] ?? '')),
            'employer' => trim((string)($payload['employer'] ?? '')),
            'priority' => (int)($payload['priority'] ?? 0),
            'attempts' => (int)($payload['attempts'] ?? 0),
            'max_attempts' => (int)($payload['max_attempts'] ?? 0),
            'error' => trim((string)($payload['error'] ?? '')),
        ]);
    }

    private function shouldTrackActivityEvent(string $event): bool
    {
        return in_array($event, [
            'job_queued',
            'job_assigned',
            'job_started',
            'job_requeued',
            'job_completed',
            'job_failed',
            'job_manually_resolved',
            'job_auto_resolved',
            'job_disabled',
        ], true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function updateTimelineMetrics(string $event, array $payload, string $timestamp): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $field = match ($event) {
            'job_queued' => 'queued',
            'job_assigned' => 'assigned',
            'job_started' => 'started',
            'job_completed' => 'completed',
            'job_failed' => 'failed',
            'job_requeued' => 'requeued',
            'job_manually_resolved' => 'manually_resolved',
            'job_auto_resolved' => 'auto_resolved',
            'job_disabled' => 'disabled',
            default => null,
        };

        if ($field === null) {
            return;
        }

        $bucketKey = $this->timelineBucketKey($this->bucketStampFromIsoTimestamp($timestamp));
        try {
            $this->client->hIncrBy($bucketKey, $field, 1);
            $this->client->expire($bucketKey, ($this->timelineRetentionMinutes + 60) * 60);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, int> $global
     * @param array<string, array<string, int>> $perPool
     * @param array<int, array<string, mixed>> $workers
     */
    private function writeDepthSnapshot(array $global, array $perPool, array $workers): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $bucketKey = $this->timelineBucketKey($this->bucketStampFromTimestamp((int)(floor(time() / 60) * 60)));
        $workerSummary = $this->buildWorkerSummary($workers);
        $fields = [
            'pending_depth' => (string)((int)($global['pending'] ?? 0)),
            'assigned_depth' => (string)((int)($global['assigned'] ?? 0)),
            'in_progress_depth' => (string)((int)($global['in_progress'] ?? 0)),
            'failed_depth' => (string)((int)($global['failed'] ?? 0)),
            'workers_running' => (string)((int)($workerSummary['running_workers'] ?? 0)),
        ];

        try {
            $this->client->hMSet($bucketKey, $fields);
            $this->client->expire($bucketKey, ($this->timelineRetentionMinutes + 60) * 60);
        } catch (\Throwable) {
        }
    }

    private function refreshWorkerTimelineSnapshot(): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $bucketKey = $this->timelineBucketKey($this->bucketStampFromTimestamp((int)(floor(time() / 60) * 60)));

        try {
            $this->client->hSet($bucketKey, 'workers_running', (string)count($this->getRunningWorkers()));
            $this->client->expire($bucketKey, ($this->timelineRetentionMinutes + 60) * 60);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<int, array<string, mixed>> $workers
     * @return array<string, mixed>
     */
    private function buildWorkerSummary(array $workers): array
    {
        $summary = [
            'running_workers' => 0,
            'estimated_capacity' => 0,
            'pools' => [],
        ];

        foreach ($workers as $worker) {
            $pool = $this->normalizePool($worker['pool'] ?? null);
            $capacity = max(1, (int)($worker['capacity'] ?? 1));
            $summary['running_workers']++;
            $summary['estimated_capacity'] += $capacity;

            if (!isset($summary['pools'][$pool])) {
                $summary['pools'][$pool] = [
                    'running_workers' => 0,
                    'estimated_capacity' => 0,
                ];
            }

            $summary['pools'][$pool]['running_workers']++;
            $summary['pools'][$pool]['estimated_capacity'] += $capacity;
        }

        ksort($summary['pools']);
        return $summary;
    }

    private function bucketStampFromIsoTimestamp(string $timestamp): string
    {
        $ts = strtotime($timestamp);
        if ($ts === false || $ts <= 0) {
            $ts = time();
        }

        return $this->bucketStampFromTimestamp((int)(floor($ts / 60) * 60));
    }

    private function bucketStampFromTimestamp(int $timestamp): string
    {
        return gmdate('YmdHi', $timestamp);
    }

    private function connect(): bool
    {
        try {
            $client = new RedisClientProxy($this->connection, null, 'JobRealtimeStateService');
            $client->ping();
            $this->client = $client;
            return true;
        } catch (\Throwable $e) {
            Log::warning('JobRealtimeStateService: Redis unavailable', [
                'error' => $e->getMessage(),
                'label' => (string)($this->connection['label'] ?? 'JobRealtimeStateService'),
            ]);
            return false;
        }
    }

    private function deleteMatchingKeys(string $pattern): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        try {
            $iterator = null;
            while (true) {
                $keys = $this->client->scan($iterator, $pattern, 200);
                if (is_array($keys) && $keys !== []) {
                    $this->client->del($keys);
                }

                if ($iterator === 0 || $iterator === '0' || $iterator === null || $iterator === false) {
                    break;
                }
            }
        } catch (\Throwable) {
        }
    }

    private function normalizePool(?string $poolName): string
    {
        $poolName = trim((string)$poolName);
        return $poolName !== '' ? $poolName : 'general';
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $value): array
    {
        if (trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, string> $rows
     * @return array<string, array<string, mixed>>
     */
    private function decodeJsonMap(array $rows): array
    {
        $result = [];
        foreach ($rows as $key => $value) {
            $result[(string)$key] = $this->decodeJson((string)$value);
        }

        return $result;
    }

    private function workersKey(): string
    {
        return $this->prefix . ':workers';
    }

    private function inflightKey(string $jobId): string
    {
        return $this->prefix . ':inflight:' . $jobId;
    }

    private function eventsChannel(): string
    {
        return $this->prefix . ':channel:events';
    }

    private function eventsKey(): string
    {
        return $this->prefix . ':events';
    }

    private function recentFailuresKey(): string
    {
        return $this->prefix . ':recent_failures';
    }

    private function recentActivityKey(): string
    {
        return $this->prefix . ':recent_activity';
    }

    private function globalCountsKey(): string
    {
        return $this->prefix . ':counts:global';
    }

    private function poolCountsKey(): string
    {
        return $this->prefix . ':counts:pools';
    }

    private function snapshotMetaKey(): string
    {
        return $this->prefix . ':snapshot:meta';
    }

    private function timelineBucketKey(string $bucketStamp): string
    {
        return $this->prefix . ':timeline:' . $bucketStamp;
    }

    private function resolveWorkerStaleThresholdSeconds(): int
    {
        $explicit = $this->envInt('JOB_WORKER_STATE_STALE_SECONDS');
        if ($explicit !== null && $explicit > 0) {
            return $explicit;
        }

        $heartbeat = $this->envInt('JOB_WORKER_STATE_HEARTBEAT_SECONDS') ?? 10;
        return max(15, $heartbeat * 3);
    }

    private function envString(string $key): ?string
    {
        $raw = getenv($key);
        if ($raw === false || $raw === null || trim((string)$raw) === '') {
            $raw = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        if ($raw === null) {
            return null;
        }

        $value = trim((string)$raw);
        return $value !== '' ? $value : null;
    }

    private function envInt(string $key): ?int
    {
        $value = $this->envString($key);
        if ($value === null || !preg_match('/^-?\d+$/', $value)) {
            return null;
        }

        return (int)$value;
    }

    private function envFloat(string $key): ?float
    {
        $value = $this->envString($key);
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        return (float)$value;
    }
}
