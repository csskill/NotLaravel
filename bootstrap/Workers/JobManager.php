<?php

namespace Nraa\Workers;

use Nraa\Workers\Documents\JobDocument;

class JobManager
{
    protected JobQueue $queue;
    protected ScheduledJobs $scheduledJobs;
    protected RecurringJobs $recurringJobs;
    protected array $workers = [];
    private array $lastWorkerIndexByPool = [];

    /**
     * Constructor for JobManager
     *
     * @param JobQueue $queue The queue to get jobs from
     * @param array $workers List of workers to distribute jobs to
     * @param ScheduledJobs|null $scheduledJobs Optional, defaults to new ScheduledJobs()
     * @param RecurringJobs|null $recurringJobs Optional, defaults to new RecurringJobs()
     */
    public function __construct(
        JobQueue $queue,
        array $workers,
        ?ScheduledJobs $scheduledJobs = null,
        ?RecurringJobs $recurringJobs = null
    ) {
        $this->queue = $queue;
        $this->workers = $workers;
        $this->scheduledJobs = $scheduledJobs ?? new ScheduledJobs();
        $this->recurringJobs = $recurringJobs ?? new RecurringJobs();
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
    public function fetchAndQueueDueJobs(\DateTimeImmutable $now): void
    {
        // Scheduled jobs
        foreach ($this->scheduledJobs->fetchDueJobs($now) as $scheduled) {
            $this->queue->enqueue($scheduled->job);
            $scheduled->status = 'processed';
            $scheduled->save();
        }

        // Recurring jobs
        $this->recurringJobs->expandDueJobs($now);
    }


    /**
     * Count how many workers are currently processing jobs of a specific class
     * 
     * @param string $jobClass The job class name (e.g. 'Nraa\\Jobs\\DownloadCS2DemoJob')
     * @return int Number of workers currently processing this job type
     */
    private function countWorkersProcessingJobClass(string $jobClass): int
    {
        $count = JobDocument::count([
            'status' => ['$in' => ['assigned', 'in_progress']],
            'task.class' => $jobClass
        ]);

        return $count;
    }

    /**
     * Check if a job can be assigned to a worker
     * 
     * Checks:
     * 1. Worker has free capacity (checks database for actual assigned jobs)
     * 2. Worker's pool matches job's pool
     * 3. Job hasn't exceeded workerLimit (if specified)
     * 
     * @param Worker $worker The worker to check
     * @param JobDocument $job The job to assign
     * @return bool True if job can be assigned to this worker
     */
    private function canAssignJobToWorker(Worker $worker, JobDocument $job): bool
    {
        // Check pool assignment first (fast check)
        $jobPool = $job->pool ?? 'general';
        if ($worker->getPool() !== $jobPool) {
            return false;  // Worker not in correct pool
        }

        // Check capacity by counting active jobs in database assigned to this worker
        $activeJobsCount = JobDocument::count([
            'assignee' => $worker->getId(),
            'status' => ['$in' => ['assigned', 'in_progress']]
        ]);

        $poolConfig = (new PoolManager())->getPoolConfig($worker->getPool());
        $workerCapacity = $poolConfig['capacity'] ?? 1;

        if ($activeJobsCount >= $workerCapacity) {
            return false; // Worker at capacity
        }

        // Check workerLimit (backward compatibility)
        $workerLimit = $job->workerLimit ?? null;
        if ($workerLimit !== null && isset($job->task['class'])) {
            $activeCount = $this->countWorkersProcessingJobClass(
                $job->task['class']
            );
            if ($activeCount >= $workerLimit) {
                return false;
            }
        }

        return true;
    }

    /**
     * Distribute pending jobs to available workers.
     * 
     * This method will check each worker for available capacity and
     * assign pending jobs to them until their capacity is filled.
     * 
     * Uses pool-based assignment:
     * - pool: Pool name (download, parse, calculation, general)
     * - Worker pool must match job pool
     * - Round-robin within matching pools
     * 
     * Worker limits:
     * - workerLimit: Max number of workers that can process this job type simultaneously
     * 
     * @return void
     */
    public function distributeJobs(): void
    {
        // fetch all pending jobs (remove limit by passing null)
        $pendingJobs = iterator_to_array($this->queue->fetchPending(10000));

        if (empty($pendingJobs)) {
            return;
        }

        // Try to assign each job to a worker
        foreach ($pendingJobs as $job) {
            $jobPool = $job->pool ?? 'general';

            // Get workers in this job's pool
            $poolWorkers = array_filter($this->workers, function ($w) use ($jobPool) {
                return $w->getPool() === $jobPool;
            });

            // Failover to general pool if no workers in the specified pool
            if (empty($poolWorkers) && $jobPool !== 'general') {
                $jobPool = 'general';
                $poolWorkers = array_filter($this->workers, function ($w) use ($jobPool) {
                    return $w->getPool() === $jobPool;
                });
            }

            if (empty($poolWorkers)) {
                continue; // No workers available (even in general pool)
            }

            // Reindex array to get sequential keys
            $poolWorkers = array_values($poolWorkers);
            $numPoolWorkers = count($poolWorkers);

            // Get last index for this pool (or start at -1)
            $lastIdx = $this->lastWorkerIndexByPool[$jobPool] ?? -1;
            $startIdx = ($lastIdx + 1) % $numPoolWorkers;

            // Round-robin through workers in this pool
            for ($offset = 0; $offset < $numPoolWorkers; $offset++) {
                $i = ($startIdx + $offset) % $numPoolWorkers;
                $worker = $poolWorkers[$i];

                if ($this->canAssignJobToWorker($worker, $job)) {
                    $this->assignJobToWorker($worker, $job);
                    $this->lastWorkerIndexByPool[$jobPool] = $i; // Track per-pool round-robin
                    break; // Job assigned, move to next job
                }
            }
        }
    }

    /**
     * Assign a job to a worker
     * 
     * Passes worker configuration to job parameters and logs the assignment
     * 
     * @param Worker $worker Worker to assign to
     * @param JobDocument $job Job to assign
     */
    private function assignJobToWorker(Worker $worker, JobDocument $job): void
    {
        // Add worker config to job params if worker has any
        $workerConfig = $worker->getConfig();
        if (!empty($workerConfig)) {
            if (!isset($job->instructions)) {
                $job->instructions = [];
            }
            $job->instructions['worker_config'] = $workerConfig;
            $job->save(); // Update job with worker config
        }

        $this->queue->markAssigned((string)$job->id, (string)$worker->getId());

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
     * Reset stale jobs that were assigned or in_progress but have no active worker
     *
     * Uses heartbeat system to detect stale jobs. Jobs without a recent heartbeat
     * are considered stale and will be recovered.
     *
     * @param int|null $staleThresholdSeconds Consider jobs stale if heartbeat is older than this (in seconds)
     */
    public function recoverStaleJobs(?int $staleThresholdSeconds = null): void
    {
        // Use heartbeat threshold from environment or default
        if ($staleThresholdSeconds === null) {
            $staleThresholdSeconds = (int)($_ENV['STALE_JOB_THRESHOLD'] ?? 60); // Default: 60 seconds
        }

        $threshold = new \MongoDB\BSON\UTCDateTime((new \DateTimeImmutable())->modify("-{$staleThresholdSeconds} seconds"));

        // For in_progress jobs, check lastHeartbeat
        // For assigned jobs (not yet started), check updatedAt as fallback
        $staleInProgress = JobDocument::find([
            'status' => 'in_progress',
            '$or' => [
                ['lastHeartbeat' => ['$lt' => $threshold]],
                ['lastHeartbeat' => null], // Jobs without heartbeat (legacy or just started)
            ],
        ])->toArray();

        $staleAssigned = JobDocument::find([
            'status' => 'assigned',
            'updatedAt' => ['$lte' => $threshold],
        ])->toArray();

        $staleJobs = array_merge($staleInProgress, $staleAssigned);

        foreach ($staleJobs as $job) {
            $job->status = 'pending';
            $job->assignee = null; // free for reassignment
            $job->lastHeartbeat = null; // Reset heartbeat
            $job->save();

            echo "♻️ Recovered stale job {$job->id} to pending (no heartbeat for {$staleThresholdSeconds}s)\n";
        }
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
