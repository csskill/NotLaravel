<?php

namespace Nraa\Workers;

use Nraa\Workers\Documents\JobDocument;
use Nraa\Workers\Documents\ScheduledJobDocument;
use Nraa\Workers\ScheduledJobs;
use function Opis\Closure\{serialize, unserialize};

class JobRegistrar
{
    protected JobQueue $queue;
    protected ScheduledJobs $scheduledJobs;

    /**
     * Constructor for JobRegistrar
     *
     * @param JobQueue|null $queue The queue to register jobs in. If null, a new JobQueue instance will be created.
     * @param ScheduledJobs|null $scheduledJobs The scheduled jobs instance to register scheduled jobs in. If null, a new ScheduledJobs instance will be created.
     */
    public function __construct(JobQueue $queue = null, ScheduledJobs $scheduled = null)
    {
        $this->queue = $queue ?? new JobQueue();
        $this->scheduledJobs = $scheduled ?? new ScheduledJobs();
    }

    /**
     * Register a new job.
     *
     * @param array|callable $callback ['ClassName::class', 'method'] or closure
     * @param array $params Optional parameters for the callable
     * @param \DateTimeImmutable|null $runAt If set, schedule job; otherwise enqueue ASAP
     * @param string|null $employer Optional informational string
     * @param bool $preventDuplicates If true, check for existing jobs with same signature before enqueuing
     * @param int|null $workerLimit Max number of workers that can process this job type simultaneously (deprecated, use pool config)
     * @param string|null $pool Pool name to assign job to (download, parse, calculation, general). If null, auto-detected from job class.
     * @param int|null $maxAttempts Maximum retry attempts for this job. Defaults to 3.
     * @param int|null $priority Queue priority. Higher numbers are assigned first.
     * @param array<string,mixed>|null $jobOptions Optional queue metadata such as lane, fairness_key, or next_run_at.
     */
    public function registerJob(
        $callback,
        array $params = [],
        ?\DateTimeImmutable $runAt = null,
        ?string $employer = null,
        bool $preventDuplicates = true,
        ?int $workerLimit = null,
        ?string $pool = null,
        ?int $maxAttempts = null,
        ?int $priority = null,
        ?array $jobOptions = null
    ): JobDocument | ScheduledJobDocument | null {
        $callableData = $this->resolveCallable($callback);
        $resolvedParams = $this->resolveParams($params);
        $effectiveMaxAttempts = $maxAttempts ?? 3;
        $poolManager = new PoolManager();

        if (
            isset($callableData['class'])
            && (new JobTypeControlService())->isJobTypeDisabled((string)$callableData['class'])
        ) {
            return null;
        }

        // Auto-detect pool from job class if not specified
        if ($pool === null && isset($callableData['class'])) {
            $pool = $poolManager->getPoolForJobClass($callableData['class']);
        }

        $pool = $this->normalizePoolName($pool, $poolManager);

        $effectivePriority = $priority !== null ? max(1, (int)$priority) : 1;
        if ($priority === null) {
            try {
                $poolConfig = $poolManager->getPoolConfig($pool);
                $effectivePriority = max(1, (int)($poolConfig['priority'] ?? 1));
            } catch (\Throwable) {
                $effectivePriority = 1;
            }
        }

        // When creating/enqueuing jobs via JobQueue or JobRegistrar, include:
        $jobData = [
            'task' => $callableData,
            'instructions' => $resolvedParams,
            'employer' => $employer ?? 'System',
            'priority' => $effectivePriority,
            'status' => 'pending',
            'attempts' => 0,
            'maxAttempts' => max(1, $effectiveMaxAttempts),
        ];

        // Add pool assignment
        $jobData['pool'] = $pool;
        $this->applyJobOptions($jobData, $jobOptions);

        // Keep workerLimit for backward compatibility (deprecated - prefer pool config)
        if ($workerLimit !== null) {
            $jobData['workerLimit'] = $workerLimit;
        }

        // Generate idempotency key from job signature
        $idempotencyKey = $this->generateIdempotencyKey($callableData, $resolvedParams);
        $jobData['idempotency_key'] = $idempotencyKey;

        if ($runAt instanceof \DateTimeImmutable) {

            return $this->scheduledJobs->schedule($jobData, $runAt);
        }

        return $this->queue->enqueue($jobData, $preventDuplicates);
    }

    /**
     * Generate an idempotency key from job signature
     * This prevents duplicate jobs from being queued
     * 
     * @param array $callableData
     * @param array $params
     * @return string
     */
    protected function generateIdempotencyKey(array $callableData, array $params): string
    {
        // Create a unique signature based on class, method, and parameters
        $signature = [
            'class' => $callableData['class'] ?? null,
            'method' => $callableData['method'] ?? null,
            'params' => $params
        ];

        return md5(json_encode($signature));
    }

    /**
     * Resolve and validate the provided callable.
     *
     * @param callable|array $cb
     * @return array ['type' => 'callable', 'class' => ?, 'method' => ?]
     */
    protected function resolveCallable($cb): array
    {
        if (is_array($cb) && count($cb) === 2 && class_exists($cb[0]) && method_exists($cb[0], $cb[1])) {
            return ['type' => 'class_method', 'class' => $cb[0], 'method' => $cb[1]];
        }

        if ($cb instanceof \Closure) {
            $serialized = serialize($cb);
            return ['type' => 'closure', 'closure' => $serialized];
        }
        throw new \InvalidArgumentException('Invalid callback provided to JobRegistrar . ' . json_encode($cb));
    }

    /**
     * Resolve and sanitize the provided parameters.
     *
     * This method is used to resolve any serialized closures in the provided parameters.
     * It will return an array of resolved parameters.
     *
     * @param array $params The parameters to resolve and sanitize.
     * @return array The resolved and sanitized parameters.
     */
    protected function resolveParams(array $params = [])
    {
        $sanitizedParams = [];
        foreach ($params as $param) {
            if ($param instanceof \Closure) {
                $sanitizedParams[] = serialize($param);
                continue;
            }
            $sanitizedParams[] = $param;
        }
        return $sanitizedParams;
    }

    private function normalizePoolName(?string $pool, PoolManager $poolManager): string
    {
        $normalized = strtolower(trim((string)$pool));
        if ($normalized === '') {
            return 'general';
        }

        if ($poolManager->poolExists($normalized)) {
            return $normalized;
        }

        return 'general';
    }

    /**
     * @param array<string,mixed> $jobData
     * @param array<string,mixed>|null $jobOptions
     */
    private function applyJobOptions(array &$jobData, ?array $jobOptions): void
    {
        if (!is_array($jobOptions) || $jobOptions === []) {
            return;
        }

        $lane = strtolower(trim((string)($jobOptions['lane'] ?? '')));
        if (in_array($lane, ['realtime', 'backfill'], true)) {
            $jobData['lane'] = $lane;
        }

        $fairnessKey = trim((string)($jobOptions['fairness_key'] ?? ''));
        if ($fairnessKey !== '') {
            $jobData['fairness_key'] = $fairnessKey;
        }

        $nextRunAt = $this->normalizeNextRunAt($jobOptions['next_run_at'] ?? null);
        if ($nextRunAt instanceof \MongoDB\BSON\UTCDateTime) {
            $jobData['nextRunAt'] = $nextRunAt;
        }
    }

    private function normalizeNextRunAt(mixed $value): ?\MongoDB\BSON\UTCDateTime
    {
        if ($value instanceof \MongoDB\BSON\UTCDateTime) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return new \MongoDB\BSON\UTCDateTime($value);
        }

        return null;
    }
}
