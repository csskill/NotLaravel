<?php

namespace Nraa\Workers;

use Nraa\Pillars\Log;
use Nraa\Workers\Documents\JobDocument;

class JobManager
{
    protected JobQueue $queue;
    protected ScheduledJobs $scheduledJobs;
    protected RecurringJobs $recurringJobs;
    protected DistributedLock $schedulerLock;
    protected PoolManager $poolManager;
    protected array $workers = [];
    private array $lastWorkerIndexByPool = [];

    /**
     * Constructor for JobManager
     *
     * @param JobQueue $queue The queue to get jobs from
     * @param array $workers List of workers to distribute jobs to
     * @param ScheduledJobs|null $scheduledJobs Optional, defaults to new ScheduledJobs()
     * @param RecurringJobs|null $recurringJobs Optional, defaults to new RecurringJobs()
     * @param string|null $coordinatorId Optional lock owner identity for scheduler coordination
     */
    public function __construct(
        JobQueue $queue,
        array $workers,
        ?ScheduledJobs $scheduledJobs = null,
        ?RecurringJobs $recurringJobs = null,
        ?string $coordinatorId = null
    ) {
        $this->queue = $queue;
        $this->workers = $workers;
        $this->scheduledJobs = $scheduledJobs ?? new ScheduledJobs();
        $this->recurringJobs = $recurringJobs ?? new RecurringJobs();
        $this->schedulerLock = new DistributedLock($coordinatorId);
        $this->poolManager = new PoolManager();
    }

    /**
     * Return the JobQueue instance used by this JobManager.
     *
     * @return JobQueue
     */
    public function getQueue(): JobQueue
    {
        return $this->queue;
    }


    /**
     * Get the ScheduledJobs instance used by this JobManager
     *
     * @return ScheduledJobs
     */
    public function getScheduledJobs(): ScheduledJobs
    {
        return $this->scheduledJobs;
    }

    /**
     * Get the RecurringJobs instance used by this JobManager
     *
     * @return RecurringJobs
     */
    public function getRecurringJobs(): RecurringJobs
    {
        return $this->recurringJobs;
    }


    /**
     * Fetch and queue due jobs from scheduled and recurring jobs.
     *
     * @param \DateTimeImmutable $now The current datetime
     */
    public function fetchAndQueueDueJobs(\DateTimeImmutable $now, ?callable $progressHeartbeat = null): void
    {
        // Global coordinator lease: only one worker process should expand due jobs at a time.
        $lockTtlSeconds = max(10, (int)($_ENV['JOB_SCHEDULER_LOCK_TTL_SECONDS'] ?? 15));
        if (!$this->schedulerLock->acquire('job_scheduler:fetch_due_jobs', $lockTtlSeconds)) {
            return;
        }

        $scheduledBatchSize = max(1, (int)($_ENV['JOB_SCHEDULED_DISPATCH_BATCH_SIZE'] ?? 25));
        $scheduledMaxSeconds = max(1, (int)($_ENV['JOB_SCHEDULED_DISPATCH_MAX_SECONDS'] ?? 10));
        $scheduledDeadline = microtime(true) + $scheduledMaxSeconds;

        // Scheduled jobs
        foreach ($this->scheduledJobs->fetchDueJobs($now, $scheduledBatchSize) as $scheduled) {
            $progressHeartbeat?->__invoke();

            try {
                $enqueuedJob = $this->queue->enqueue($scheduled->job);
                $terminalAt = new \MongoDB\BSON\UTCDateTime($now);
                $scheduled->status = $enqueuedJob instanceof JobDocument
                    ? 'processed'
                    : JobTypeControlService::SCHEDULED_STATUS_SKIPPED_DISABLED;
                $scheduled->dispatchClaimedAt = null;
                $scheduled->terminalAt = $terminalAt;
                $scheduled->save();
            } catch (\Throwable $e) {
                $scheduled->status = 'scheduled';
                $scheduled->dispatchClaimedAt = null;
                $scheduled->terminalAt = null;
                $scheduled->save();

                Log::error('JobManager: failed to expand scheduled job', [
                    'scheduled_job_id' => (string)($scheduled->id ?? ''),
                    'error' => $e->getMessage(),
                ]);
            }

            $progressHeartbeat?->__invoke();

            if (microtime(true) >= $scheduledDeadline) {
                break;
            }
        }

        // Recurring jobs
        $progressHeartbeat?->__invoke();
        $this->recurringJobs->expandDueJobs($now);
    }


    /**
     * Distribute pending jobs to workers using pool-aware selection and aggregated capacity lookups.
     *
     * @param array<int, string>|null $poolNames
     * @return array<string, int>
     */
    public function distributeJobs(?array $poolNames = null): array
    {
        if (!$this->queue->supportsDispatcherAssignments()) {
            return $this->queue->releaseDueJobs($poolNames, new \DateTimeImmutable());
        }

        $workersByPool = $this->groupWorkersByPool();
        if ($workersByPool === []) {
            return [
                'considered_jobs' => 0,
                'assigned_jobs' => 0,
            ];
        }

        $poolNames = $poolNames ?? array_keys($workersByPool);
        $poolNames = array_values(array_unique(array_filter(array_map(
            static fn($poolName): string => strtolower(trim((string)$poolName)),
            $poolNames
        ), static fn(string $poolName): bool => $poolName !== '')));

        $stats = [
            'considered_jobs' => 0,
            'assigned_jobs' => 0,
        ];
        $now = new \DateTimeImmutable();

        foreach ($poolNames as $poolName) {
            $candidateWorkers = $this->resolveCandidateWorkersForPool($poolName, $workersByPool);
            if ($candidateWorkers === []) {
                continue;
            }

            $fetchLimit = $this->resolvePendingFetchLimit($candidateWorkers);
            $pendingJobs = array_values(iterator_to_array(
                $this->queue->fetchPendingForPool($poolName, $fetchLimit, $now)
            ));

            if ($pendingJobs === []) {
                continue;
            }

            $stats['considered_jobs'] += count($pendingJobs);
            $workerIds = array_map(static fn(Worker $worker): string => $worker->getId(), $candidateWorkers);
            $workerLoadMap = $this->queue->getActiveWorkerLoadMap($workerIds);
            $jobClassLoadMap = $this->buildJobClassLoadMap($pendingJobs);

            foreach ($pendingJobs as $job) {
                if ($this->assignPendingJob($job, $candidateWorkers, $workerLoadMap, $jobClassLoadMap, $poolName)) {
                    $stats['assigned_jobs']++;
                }
            }
        }

        return $stats;
    }

    /**
     * Assign a job to a worker
     * 
     * Passes worker configuration to job parameters and logs the assignment
     * 
     * @param Worker $worker Worker to assign to
     * @param JobDocument $job Job to assign
     */
    private function assignJobToWorker(Worker $worker, JobDocument $job): bool
    {
        $workerConfig = $worker->getConfig();
        $instructions = null;

        if (!empty($workerConfig)) {
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

        $assigned = $this->queue->markAssigned((string)$job->id, (string)$worker->getId(), $instructions);
        if ($assigned) {
            if ($instructions !== null) {
                $job->instructions = $instructions;
            }
            $job->assignee = $worker->getId();
            $job->status = 'assigned';
            $job->assignedAt = new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable());
            $this->queue->recordAssigned($job, (string)$worker->getId());
        }

        return $assigned;

        /*
        // Log job assignment to dedicated log file
        $jobClass = $job->task['class'] ?? 'Unknown';
        $jobPool = $job->pool ?? 'general';
        $workerId = $worker->getId();
        $jobId = (string)$job->id;

        // Extract relevant job parameters for logging
        $instructions = $job->instructions ?? [];
        $logContext = [
            'job_id' => $jobId,
            'job_class' => $jobClass,
            'pool' => $jobPool,
            'worker_id' => $workerId,
            'priority' => $job->priority ?? 1,
        ];

        // Add specific parameters based on job type
        if (isset($instructions['shareCode'])) {
            $logContext['share_code'] = $instructions['shareCode'];
        }
        if (isset($instructions['outputPath'])) {
            $logContext['demo_file'] = basename($instructions['outputPath']);
        }

        \Nraa\Pillars\Log::channel('job_manager')->info(
            "Job assigned: {$jobClass} -> {$workerId} (pool: {$jobPool})",
            $logContext
        );*/
    }

    /**
     * @return array<string, array<int, Worker>>
     */
    private function groupWorkersByPool(): array
    {
        $grouped = [];
        foreach ($this->workers as $worker) {
            if (!$worker instanceof Worker) {
                continue;
            }

            $poolName = strtolower(trim((string)$worker->getPool())) ?: 'general';
            if (!isset($grouped[$poolName])) {
                $grouped[$poolName] = [];
            }
            $grouped[$poolName][] = $worker;
        }

        return $grouped;
    }

    /**
     * @param array<string, array<int, Worker>> $workersByPool
     * @return array<int, Worker>
     */
    private function resolveCandidateWorkersForPool(string $poolName, array $workersByPool): array
    {
        $poolName = strtolower(trim($poolName)) ?: 'general';
        if (isset($workersByPool[$poolName]) && $workersByPool[$poolName] !== []) {
            return array_values($workersByPool[$poolName]);
        }

        if ($poolName !== 'general' && isset($workersByPool['general'])) {
            return array_values($workersByPool['general']);
        }

        return [];
    }

    /**
     * @param array<int, Worker> $candidateWorkers
     */
    private function resolvePendingFetchLimit(array $candidateWorkers): int
    {
        $capacity = 0;
        foreach ($candidateWorkers as $worker) {
            $capacity += max(1, $worker->getCapacity());
        }

        $multiplier = max(2, (int)($_ENV['JOB_DISPATCHER_PENDING_FETCH_MULTIPLIER'] ?? 4));
        return max(25, $capacity * $multiplier);
    }

    /**
     * @param array<int, JobDocument> $jobs
     * @return array<string, int>
     */
    private function buildJobClassLoadMap(array $jobs): array
    {
        $jobClasses = [];
        foreach ($jobs as $job) {
            $workerLimit = $job->workerLimit ?? null;
            $jobClass = trim((string)($job->task['class'] ?? ''));
            if ($workerLimit === null || $jobClass === '') {
                continue;
            }
            $jobClasses[] = $jobClass;
        }

        return $this->queue->getActiveJobClassLoadMap($jobClasses);
    }

    /**
     * @param array<int, Worker> $candidateWorkers
     * @param array<string, int> $workerLoadMap
     * @param array<string, int> $jobClassLoadMap
     */
    private function assignPendingJob(
        JobDocument $job,
        array $candidateWorkers,
        array &$workerLoadMap,
        array &$jobClassLoadMap,
        string $dispatchPool
    ): bool {
        if ($candidateWorkers === []) {
            return false;
        }

        $numWorkers = count($candidateWorkers);
        $lastIdx = $this->lastWorkerIndexByPool[$dispatchPool] ?? -1;
        $startIdx = ($lastIdx + 1) % $numWorkers;

        for ($offset = 0; $offset < $numWorkers; $offset++) {
            $index = ($startIdx + $offset) % $numWorkers;
            $worker = $candidateWorkers[$index];

            if (!$this->canAssignJobToWorker($worker, $job, $workerLoadMap, $jobClassLoadMap)) {
                continue;
            }

            if (!$this->assignJobToWorker($worker, $job)) {
                continue;
            }

            $workerId = $worker->getId();
            $workerLoadMap[$workerId] = (int)($workerLoadMap[$workerId] ?? 0) + 1;

            $jobClass = trim((string)($job->task['class'] ?? ''));
            if ($jobClass !== '' && $job->workerLimit !== null) {
                $jobClassLoadMap[$jobClass] = (int)($jobClassLoadMap[$jobClass] ?? 0) + 1;
            }

            $this->lastWorkerIndexByPool[$dispatchPool] = $index;
            return true;
        }

        return false;
    }

    /**
     * @param array<string, int> $workerLoadMap
     * @param array<string, int> $jobClassLoadMap
     */
    private function canAssignJobToWorker(
        Worker $worker,
        JobDocument $job,
        array $workerLoadMap,
        array $jobClassLoadMap
    ): bool {
        $workerPool = strtolower(trim((string)$worker->getPool())) ?: 'general';
        $jobPool = strtolower(trim((string)($job->pool ?? ''))) ?: 'general';
        if ($jobPool !== 'general' && $workerPool !== $jobPool && $workerPool !== 'general') {
            return false;
        }

        $workerCapacity = max(1, $worker->getCapacity());

        $activeJobsCount = (int)($workerLoadMap[$worker->getId()] ?? 0);
        if ($activeJobsCount >= $workerCapacity) {
            return false;
        }

        $workerLimit = $job->workerLimit ?? null;
        $jobClass = trim((string)($job->task['class'] ?? ''));
        if ($workerLimit !== null && $jobClass !== '') {
            if ((int)($jobClassLoadMap[$jobClass] ?? 0) >= $workerLimit) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reset stale jobs that were assigned or in_progress but have no active worker.
     *
     * Uses indexed bulk updates to avoid collection scans and full document hydration.
     *
     * @param int|null $staleThresholdSeconds Consider jobs stale if heartbeat is older than this (in seconds)
     * @param string|null $poolName Optional pool scope for recovery
     * @return int Number of recovered stale jobs
     */
    public function recoverStaleJobs(?int $staleThresholdSeconds = null, ?string $poolName = null): int
    {
        if ($staleThresholdSeconds === null) {
            $staleThresholdSeconds = $this->resolveStaleThresholdSeconds($poolName);
        }

        $now = new \DateTimeImmutable();
        $thresholdDateTime = $now->modify("-{$staleThresholdSeconds} seconds");
        $threshold = new \MongoDB\BSON\UTCDateTime($thresholdDateTime);
        $nowUtc = new \MongoDB\BSON\UTCDateTime($now);

        $instance = new JobDocument();
        $collection = $instance->getCollection();

        $poolFilter = $this->buildPoolRecoveryFilter($poolName);
        $disabledJobTypes = (new JobTypeControlService())->getDisabledJobTypes();

        $resetPayload = [
            '$set' => [
                'status' => 'pending',
                'assignee' => null,
                'assignedAt' => null,
                'startedAt' => null,
                'lastHeartbeat' => null,
                'updatedAt' => $nowUtc,
            ],
        ];
        $disablePayload = [
            '$set' => [
                'status' => JobTypeControlService::JOB_STATUS_DISABLED,
                'assignee' => null,
                'assignedAt' => null,
                'startedAt' => null,
                'lastHeartbeat' => null,
                'nextRunAt' => null,
                'error' => null,
                'failedAt' => null,
                'active_idempotency_key' => null,
                'updatedAt' => $nowUtc,
            ],
        ];

        $scopeFilter = static function (array $scope, array $conditions): array {
            if (empty($scope)) {
                return $conditions;
            }

            return [
                '$and' => [
                    $scope,
                    $conditions,
                ],
            ];
        };
        $withJobTypeScope = static function (array $conditions, array $jobTypes, bool $include): array {
            if ($jobTypes === []) {
                return $conditions;
            }

            $conditions['task.class'] = [$include ? '$in' : '$nin' => array_values($jobTypes)];
            return $conditions;
        };

        $staleInProgressWithHeartbeatFilter = $scopeFilter($poolFilter, $withJobTypeScope([
            'status' => 'in_progress',
            'lastHeartbeat' => ['$lt' => $threshold],
        ], $disabledJobTypes, false));
        $staleInProgressNoHeartbeatFilter = $scopeFilter($poolFilter, $withJobTypeScope([
            'status' => 'in_progress',
            'lastHeartbeat' => null,
            '$or' => [
                ['startedAt' => ['$lte' => $threshold]],
                [
                    'startedAt' => null,
                    'updatedAt' => ['$lte' => $threshold],
                ],
            ],
        ], $disabledJobTypes, false));
        $staleAssignedFilter = $scopeFilter($poolFilter, $withJobTypeScope([
            'status' => 'assigned',
            '$or' => [
                ['assignedAt' => ['$lte' => $threshold]],
                [
                    'assignedAt' => null,
                    'updatedAt' => ['$lte' => $threshold],
                ],
            ],
        ], $disabledJobTypes, false));

        $recovered = 0;
        $recovered += $collection->updateMany($staleInProgressWithHeartbeatFilter, $resetPayload)->getModifiedCount();
        $recovered += $collection->updateMany($staleInProgressNoHeartbeatFilter, $resetPayload)->getModifiedCount();
        $recovered += $collection->updateMany($staleAssignedFilter, $resetPayload)->getModifiedCount();

        if ($disabledJobTypes !== []) {
            $disabledStaleInProgressWithHeartbeatFilter = $scopeFilter($poolFilter, $withJobTypeScope([
                'status' => 'in_progress',
                'lastHeartbeat' => ['$lt' => $threshold],
            ], $disabledJobTypes, true));
            $disabledStaleInProgressNoHeartbeatFilter = $scopeFilter($poolFilter, $withJobTypeScope([
                'status' => 'in_progress',
                'lastHeartbeat' => null,
                '$or' => [
                    ['startedAt' => ['$lte' => $threshold]],
                    [
                        'startedAt' => null,
                        'updatedAt' => ['$lte' => $threshold],
                    ],
                ],
            ], $disabledJobTypes, true));
            $disabledStaleAssignedFilter = $scopeFilter($poolFilter, $withJobTypeScope([
                'status' => 'assigned',
                '$or' => [
                    ['assignedAt' => ['$lte' => $threshold]],
                    [
                        'assignedAt' => null,
                        'updatedAt' => ['$lte' => $threshold],
                    ],
                ],
            ], $disabledJobTypes, true));

            $recovered += $collection->updateMany($disabledStaleInProgressWithHeartbeatFilter, $disablePayload)->getModifiedCount();
            $recovered += $collection->updateMany($disabledStaleInProgressNoHeartbeatFilter, $disablePayload)->getModifiedCount();
            $recovered += $collection->updateMany($disabledStaleAssignedFilter, $disablePayload)->getModifiedCount();
        }

        if ($recovered > 0 && !$this->queue->supportsDispatcherAssignments()) {
            $reconciledRows = $collection->find(
                $scopeFilter($poolFilter, [
                    'updatedAt' => $nowUtc,
                    'status' => [
                        '$in' => [
                            'pending',
                            JobTypeControlService::JOB_STATUS_DISABLED,
                        ],
                    ],
                ]),
                [
                    'projection' => ['_id' => 1],
                ]
            )->toArray();

            $reconciledJobIds = [];
            foreach ($reconciledRows as $row) {
                if ($row instanceof JobDocument) {
                    $jobId = trim((string)($row->id ?? ''));
                    if ($jobId !== '') {
                        $reconciledJobIds[] = $jobId;
                    }
                    continue;
                }

                if ($row instanceof \MongoDB\Model\BSONDocument) {
                    $row = $row->getArrayCopy();
                } elseif ($row instanceof \stdClass) {
                    $row = (array)$row;
                }

                $jobId = trim((string)($row['_id'] ?? ''));
                if ($jobId !== '') {
                    $reconciledJobIds[] = $jobId;
                }
            }

            if ($reconciledJobIds !== []) {
                $this->queue->reconcileJobs($reconciledJobIds, $now);
            }
        }

        if ($recovered > 0) {
            echo "♻️ Recovered {$recovered} stale job(s) (threshold: {$staleThresholdSeconds}s)\n";
        }

        return $recovered;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPoolRecoveryFilter(?string $poolName): array
    {
        $normalizedPool = strtolower(trim((string)($poolName ?? '')));
        if ($normalizedPool === '') {
            return [];
        }

        if ($normalizedPool === 'general') {
            return [
                '$or' => [
                    ['pool' => 'general'],
                    ['pool' => null],
                    ['pool' => ['$exists' => false]],
                ],
            ];
        }

        return ['pool' => $normalizedPool];
    }

    private function resolveStaleThresholdSeconds(?string $poolName): int
    {
        $envThreshold = (int)($_ENV['STALE_JOB_THRESHOLD'] ?? 0);
        $heartbeatInterval = max(5, (int)($_ENV['HEARTBEAT_INTERVAL'] ?? 10));
        $minimumFromHeartbeat = $heartbeatInterval * 6;

        // Long-running jobs are protected by fresh heartbeats. Pool timeouts must not
        // delay recovery once a worker has stopped heartbeating.
        if ($envThreshold > 0) {
            return max($envThreshold, $minimumFromHeartbeat);
        }

        return $minimumFromHeartbeat;
    }


    /**
     * Start all workers to loop and execute jobs.
     *
     * This method will call the startWork() method on each worker, which will
     * start a loop to fetch and execute jobs.
     *
     * @return void
     */
    public function startAllWorkers(): void
    {
        foreach ($this->workers as $worker) {
            $worker->startWork();
        }
    }
}
