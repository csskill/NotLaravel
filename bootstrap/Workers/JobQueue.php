<?php

namespace Nraa\Workers;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use Nraa\Workers\Contracts\QueueTransportInterface;
use Nraa\Workers\Documents\JobExecutionDocument;
use Nraa\Workers\Documents\JobDocument;
use Nraa\Workers\Transports\MongoQueueTransport;
use Nraa\Workers\Transports\RedisStreamsQueueTransport;

class JobQueue
{
    private QueueTransportInterface $transport;
    private JobRealtimeStateService $realtimeState;

    public function __construct(?QueueTransportInterface $transport = null)
    {
        $this->transport = $transport ?? $this->resolveTransport();
        $this->realtimeState = JobRealtimeStateService::getInstance();
    }

    public function getTransport(): QueueTransportInterface
    {
        return $this->transport;
    }

    public function getRealtimeState(): JobRealtimeStateService
    {
        return $this->realtimeState;
    }

    /**
     * Atomically claim the next job already assigned to a worker.
     */
    public function getNextJob(string $workerId, ?string $poolName = null, ?array $workerConfig = null): ?JobDocument
    {
        try {
            $job = $this->transport->claimNextJob($workerId, trim((string)$poolName) ?: 'general', $workerConfig);
            if ($job instanceof JobDocument) {
                $this->realtimeState->recordStarted($job, $workerId);
            }

            return $job;
        } catch (\Throwable $e) {
            echo "[" . date('H:i:s') . "] ❌ Worker {$workerId}: Error in getNextJob: {$e->getMessage()}\n";
            echo "{$e->getTraceAsString()}\n";
            return null;
        }
    }

    /**
     * Enqueue a new job into the active queue transport.
     */
    public function enqueue(array|object $jobData, bool $preventDuplicates = true): ?JobDocument
    {
        $payload = is_array($jobData) ? $jobData : (array)$jobData;
        $job = $this->transport->enqueue($payload, $preventDuplicates);
        if ($job instanceof JobDocument) {
            $this->realtimeState->recordQueued($payload, (string)$job->id);
        }

        return $job;
    }

    /**
     * @return iterable<JobDocument>
     */
    public function fetchAll(): iterable
    {
        return JobDocument::all();
    }

    /**
     * Legacy helper retained for backward compatibility.
     *
     * @return iterable<JobDocument>
     */
    public function fetchPending(int $limit = 10): iterable
    {
        return $this->fetchPendingForPool('general', $limit);
    }

    /**
     * @return iterable<JobDocument>
     */
    public function fetchPendingForPool(string $poolName, int $limit = 10, ?\DateTimeImmutable $now = null): iterable
    {
        return $this->transport->fetchPendingForPool($poolName, $limit, $now);
    }

    public function supportsDispatcherAssignments(): bool
    {
        return $this->transport->supportsDispatcherAssignments();
    }

    /**
     * @param array<int, string>|null $poolNames
     * @return array<string, int>
     */
    public function releaseDueJobs(?array $poolNames = null, ?\DateTimeImmutable $now = null): array
    {
        return $this->transport->releaseDueJobs($poolNames, $now);
    }

    /**
     * @param array<int, string> $jobIds
     */
    public function reconcileJobs(array $jobIds, ?\DateTimeImmutable $now = null): int
    {
        return $this->transport->reconcileJobs($jobIds, $now);
    }

    public function markAssigned(string $jobId, string $workerId, ?array $instructions = null): bool
    {
        try {
            return $this->transport->markAssigned($jobId, $workerId, $instructions);
        } catch (\Throwable $e) {
            echo "[" . date('H:i:s') . "] ❌ Error saving job assignment: {$e->getMessage()}\n";
            echo "{$e->getTraceAsString()}\n";
            return false;
        }
    }

    /**
     * Used by the dispatcher to keep assignment capacity calculations O(workers + job classes).
     *
     * @param array<int, string> $workerIds
     * @return array<string, int>
     */
    public function getActiveWorkerLoadMap(array $workerIds): array
    {
        return $this->transport->getActiveWorkerLoadMap($workerIds);
    }

    /**
     * @param array<int, string> $jobClasses
     * @return array<string, int>
     */
    public function getActiveJobClassLoadMap(array $jobClasses): array
    {
        return $this->transport->getActiveJobClassLoadMap($jobClasses);
    }

    public function recordAssigned(JobDocument $job, string $workerId): void
    {
        $this->realtimeState->recordAssigned($job, $workerId);
    }

    public function reconcileWorkerCrash(string $workerId, string $errorMessage, ?\DateTimeImmutable $finishedAt = null): int
    {
        $workerId = trim($workerId);
        if ($workerId === '') {
            return 0;
        }

        $finishedAt ??= new \DateTimeImmutable();
        $jobs = JobDocument::find([
            'assignee' => $workerId,
            'status' => ['$in' => ['assigned', 'in_progress']],
        ], [
            'sort' => [
                'assignedAt' => 1,
                'startedAt' => 1,
                'createdAt' => 1,
            ],
        ])->toArray();

        $reconciled = 0;
        foreach ($jobs as $job) {
            if (!$job instanceof JobDocument) {
                continue;
            }

            try {
                $this->reconcileCrashedJob($job, $workerId, $errorMessage, $finishedAt);
                $reconciled++;
            } catch (\Throwable $e) {
                $jobId = trim((string)($job->id ?? ''));
                echo "❌ Failed to reconcile crashed job {$jobId} for {$workerId}: {$e->getMessage()}\n";
            }
        }

        return $reconciled;
    }

    public function handleJobCompleted(JobDocument $job, string $workerId): void
    {
        try {
            $this->realtimeState->recordCompleted($job, $workerId);
            $this->transport->afterJobCompleted($job, $workerId);
        } catch (\Throwable $e) {
            echo "❌ Failed to finalize completed job {$job->id}: {$e->getMessage()}\n";
        }
    }

    public function handleJobRequeued(JobDocument $job, string $workerId, int $delaySeconds, string $errorMessage): void
    {
        try {
            $this->realtimeState->recordRequeued($job, $workerId, $delaySeconds, $errorMessage);
            $this->transport->afterJobRequeued($job, $workerId, $delaySeconds, $errorMessage);
        } catch (\Throwable $e) {
            echo "❌ Failed to finalize requeued job {$job->id}: {$e->getMessage()}\n";
        }
    }

    public function handleJobTerminal(JobDocument $job, string $workerId, string $status, ?string $errorMessage = null): void
    {
        try {
            $this->realtimeState->recordTerminal($job, $workerId, $status, $errorMessage);
            $this->transport->afterJobTerminal($job, $workerId, $status, $errorMessage);
        } catch (\Throwable $e) {
            echo "❌ Failed to finalize terminal job {$job->id}: {$e->getMessage()}\n";
        }
    }

    public function syncRealtimeSnapshot(): void
    {
        $this->realtimeState->syncSnapshotFromMongo();
    }

    public function markCompleted(string $jobId): void
    {
        try {
            $job = JobDocument::findOne(['_id' => new ObjectId($jobId)]);
            if (!$job) {
                return;
            }

            $workerId = (string)($job->assignee ?? '');
            $job->markCompleted();
            $this->handleJobCompleted($job, $workerId);

            echo "✅ Job {$job->id} marked as completed\n";
        } catch (\Throwable $e) {
            echo "❌ Job {$jobId} failed: {$e->getMessage()}\n";
            echo "{$e->getTraceAsString()}\n";
        }
    }

    public function markInProgress(string $jobId): void
    {
        $job = JobDocument::findOne(['_id' => new ObjectId($jobId)]);
        if (!$job) {
            return;
        }

        $job->status = 'in_progress';
        $job->updatedAt = new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable());
        echo "[" . date('H:i:s') . "] job ({$jobId}) as in_progress \n";
        $job->save();
        $this->realtimeState->recordStarted($job, (string)($job->assignee ?? ''));
    }

    public function setPriority(string $jobId, int $priority): void
    {
        $job = JobDocument::findOne(['_id' => new ObjectId($jobId)]);
        if ($job) {
            $job->priority = $priority;
            $job->save();
        }
    }

    /**
     * @return iterable<JobDocument>
     */
    public function fetchAssigned(string $workerId, int $limit = 10): iterable
    {
        $jobs = JobDocument::find([
            'status' => 'assigned',
            'assignee' => $workerId,
        ], [
            'limit' => $limit,
            'sort' => [
                'priority' => -1,
                'assignedAt' => 1,
                'createdAt' => 1,
            ],
        ])->toArray();

        foreach ($jobs as $job) {
            yield $job;
        }
    }

    public function markFailed(string $jobId, string $errorMessage): void
    {
        $job = JobDocument::findOne(['_id' => new ObjectId($jobId)]);
        if (!$job) {
            return;
        }

        $workerId = (string)($job->assignee ?? '');
        $maxAttempts = $job->maxAttempts ?? 3;
        $job->attempts = min((int)($job->attempts ?? 0) + 1, $maxAttempts);
        $job->error = $errorMessage;
        $job->failedAt = new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable());

        if (JobRetryStrategy::shouldRetry($job->attempts, $maxAttempts)) {
            $delaySeconds = JobRetryStrategy::getDelay($job->attempts);
            $job->nextRunAt = new \MongoDB\BSON\UTCDateTime((new \DateTimeImmutable())->modify("+{$delaySeconds} seconds"));
            $job->status = 'pending';
            $job->assignee = null;
            $job->assignedAt = null;
            $job->startedAt = null;
            $job->lastHeartbeat = null;
            $job->updatedAt = new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable());
            echo "♻️ Retrying job {$job->id} in {$delaySeconds}s (attempt {$job->attempts}/{$maxAttempts})\n";
            $job->save();
            $this->handleJobRequeued($job, $workerId, $delaySeconds, $errorMessage);
            return;
        }

        echo "❌ Job {$job->id} permanently failed after {$job->attempts} attempts (max: {$maxAttempts})\n";
        $job->markFailed($errorMessage);
        $this->handleJobTerminal($job, $workerId, 'failed', $errorMessage);
    }

    private function reconcileCrashedJob(
        JobDocument $job,
        string $workerId,
        string $errorMessage,
        \DateTimeImmutable $finishedAt
    ): void {
        $maxAttempts = max(1, (int)($job->maxAttempts ?? 3));
        $attempts = min((int)($job->attempts ?? 0) + 1, $maxAttempts);
        $startedAt = $this->resolveExecutionStartedAt($job, $finishedAt);

        $job->attempts = $attempts;
        $job->error = $errorMessage;
        $job->assignee = null;
        $job->assignedAt = null;
        $job->startedAt = null;
        $job->lastHeartbeat = null;
        $job->completedAt = null;

        if (JobRetryStrategy::shouldRetry($attempts, $maxAttempts)) {
            $delaySeconds = JobRetryStrategy::getDelay($attempts);
            $job->status = 'pending';
            $job->failedAt = null;
            $job->terminalAt = null;
            $job->nextRunAt = new UTCDateTime($finishedAt->modify("+{$delaySeconds} seconds"));
            $job->save();

            $this->logCrashExecution($job, $workerId, $startedAt, $finishedAt, 'requeued', $errorMessage, $attempts, $delaySeconds);
            $this->handleJobRequeued($job, $workerId, $delaySeconds, $errorMessage);
            return;
        }

        $finalMessage = $this->buildMaxAttemptsMessage($maxAttempts, $errorMessage);
        $job->status = 'failed';
        $job->error = $finalMessage;
        $job->failedAt = new UTCDateTime($finishedAt);
        $job->terminalAt = new UTCDateTime($finishedAt);
        $job->nextRunAt = null;
        $job->save();

        $this->logCrashExecution($job, $workerId, $startedAt, $finishedAt, 'failed', $finalMessage, $attempts);
        $this->handleJobTerminal($job, $workerId, 'failed', $finalMessage);
    }

    private function logCrashExecution(
        JobDocument $job,
        string $workerId,
        \DateTimeImmutable $startedAt,
        \DateTimeImmutable $finishedAt,
        string $status,
        string $errorMessage,
        int $attempts,
        ?int $nextRetryDelay = null
    ): void {
        JobExecutionDocument::log([
            'jobId' => (string)($job->id ?? ''),
            'workerId' => $workerId,
            'employer' => $job->employer ?? 'unknown',
            'startedAt' => new UTCDateTime($startedAt),
            'finishedAt' => new UTCDateTime($finishedAt),
            'execution_time' => $startedAt->diff($finishedAt)->format('%H:%I:%S.%f'),
            'status' => $status,
            'error' => $errorMessage,
            'attempts' => $attempts,
            'nextRetryDelay' => $nextRetryDelay,
        ]);
    }

    private function resolveExecutionStartedAt(JobDocument $job, \DateTimeImmutable $finishedAt): \DateTimeImmutable
    {
        foreach ([$job->startedAt ?? null, $job->assignedAt ?? null, $job->createdAt ?? null] as $candidate) {
            if ($candidate instanceof UTCDateTime) {
                return \DateTimeImmutable::createFromMutable($candidate->toDateTime());
            }
        }

        return $finishedAt;
    }

    private function buildMaxAttemptsMessage(int $maxAttempts, string $reason): string
    {
        $cleanedReason = trim(preg_replace('/\s+Job will be retried\.?$/i', '', $reason) ?? $reason);
        return "Max attempts exceeded ({$maxAttempts}): {$cleanedReason}. No more retries.";
    }

    private function resolveTransport(): QueueTransportInterface
    {
        $transport = strtolower(trim((string)($_ENV['JOB_QUEUE_TRANSPORT'] ?? getenv('JOB_QUEUE_TRANSPORT') ?: 'mongo')));

        return match ($transport) {
            '', 'mongo' => new MongoQueueTransport(),
            'redis-streams' => new RedisStreamsQueueTransport(),
            default => throw new \RuntimeException("Unsupported job queue transport '{$transport}'"),
        };
    }
}
