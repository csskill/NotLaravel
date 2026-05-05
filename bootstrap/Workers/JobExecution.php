<?php

namespace Nraa\Workers;

use MongoDB\BSON\ObjectId;
use Nraa\Workers\Documents\JobExecutionDocument;
use Nraa\Workers\Documents\JobDocument;
use Nraa\Workers\Exceptions\AutoResolveException;
use Nraa\Workers\JobRetryStrategy;
use \React\Promise\Deferred;
use function Opis\Closure\{serialize, unserialize};

class JobExecution
{
    protected Worker $worker;
    protected $job;
    protected \DateTimeImmutable $startedAt;
    protected JobQueue $queue;

    /**
     * Constructs a new JobExecution instance.
     *
     * @param Worker $worker The worker that will execute the job.
     * @param $job The job to execute.
     */
    public function __construct(Worker $worker, $job)
    {
        $this->worker = $worker;
        $this->job = $job;
        $this->startedAt = new \DateTimeImmutable();
        $this->queue = new JobQueue();
    }

    /**
     * Returns a deferred promise that will be resolved or rejected by the job execution process.
     *
     * This method is used to return a promise that will be resolved or rejected by the job execution process.
     * The promise will be resolved with the result of the job or rejected with an exception if the job fails.
     *
     * @return Deferred A deferred promise that will be resolved or rejected by the job execution process.
     */
    public function getDeferred(): Deferred
    {
        return new Deferred();
    }


    /**
     * Resolves the arguments to be passed to the job, unserializing any arguments that are serialized closures.
     *
     * This method is used to resolve the arguments that will be passed to the job, unserializing any arguments that are serialized closures.
     * The method will return an array of resolved arguments.
     *
     * @param array $args The arguments to resolve.
     * @return array The resolved arguments.
     */
    protected function resolveArguments($args): array
    {
        $sanitizedArgs = [];
        foreach ($args as $arg) {
            if (is_string($arg) && str_starts_with($arg, 'O:16:"Opis\Closure\Box"')) {
                $sanitizedArgs[] = unserialize($arg);
                continue;
            }
            // Convert BSONDocument to array (MongoDB returns BSONDocument for nested data)
            if ($arg instanceof \MongoDB\Model\BSONDocument) {
                $sanitizedArgs[] = $arg->getArrayCopy();
                continue;
            }
            $sanitizedArgs[] = $arg;
        }
        return $sanitizedArgs;
    }

    /**
     * Execute the job asynchronously.
     *
     * This method will execute the job and return a deferred promise that will be resolved or rejected by the job execution process.
     * The promise will be resolved with the result of the job or rejected with an exception if the job fails.
     *
     * @param Deferred $deferred The deferred promise to resolve or reject when the job execution process is complete.
     * @param int|null $maxAttempts The maximum number of attempts (defaults to job's maxAttempts or 3).
     * @param int $attempt The current attempt number (kept for compatibility, unused for retry accounting).
     */
    public function executeAsync(Deferred $deferred, ?int $maxAttempts = null, int $attempt = 1): void
    {
        // Use job's maxAttempts if not provided, default to 3
        $maxAttempts = $maxAttempts ?? $this->job->maxAttempts ?? 3;

        // Job is already marked as 'in_progress' by getNextJob() atomic operation
        // No need to update status again - this caused race conditions!
        if ($this->disableJobIfSuppressed('before execution', $deferred)) {
            return;
        }

        try {
            $task = $this->job->task ?? [];
            
            // Ensure task is an array (Model should already handle conversion via bsonUnserialize)
            if (!is_array($task)) {
                $task = [];
            }
            
            $callable = $task;
            
            // Extract params - instructions might be the params directly, or it might have a 'params' key
            $instructions = $this->job->instructions ?? [];
            if (!is_array($instructions)) {
                // Convert BSONDocument or stdClass to array
                if ($instructions instanceof \MongoDB\Model\BSONDocument) {
                    $instructions = $instructions->getArrayCopy();
                } elseif ($instructions instanceof \stdClass) {
                    $instructions = (array) $instructions;
                } else {
                    $instructions = [];
                }
            }
            
            // Check if instructions has a 'params' key (legacy format) or is the params directly (new format)
            $params = isset($instructions['params']) && is_array($instructions['params']) 
                ? $instructions['params'] 
                : (is_array($instructions) ? $instructions : []);

            if (($callable['type'] ?? null) === 'class_method') {
                $class = $callable['class'] ?? null;
                $method = $callable['method'] ?? null;
                if (!$class || !$method) {
                    throw new \Exception("Job task missing 'class' or 'method' for class_method type");
                }
                $instance = new $class();
                $resolvedArgs = $this->resolveArguments($params);
                $result = $instance->$method(...$resolvedArgs);
            } elseif (($callable['type'] ?? null) === 'closure') {
                $closure = unserialize($callable['closure'] ?? '');
                if (!$closure || !($closure instanceof \Closure)) {
                    throw new \Exception("Job task missing or invalid 'closure' for closure type");
                }
                $resolvedArgs = $this->resolveArguments($params);
                $result = $closure(...$resolvedArgs);
            } else {
                throw new \Exception("Job task has invalid or missing 'type'. Expected 'class_method' or 'closure', got: " . ($callable['type'] ?? 'null') . ". Task data: " . json_encode($task));
            }

            // Log success - ensure result is serializable
            try {
                // Try to serialize result to check if it's safe to store
                $safeResult = $result;
                if (is_object($result) && !($result instanceof \stdClass)) {
                    // If result is an object, try to convert to array or get a safe representation
                    if (method_exists($result, '__toString')) {
                        $safeResult = (string)$result;
                    } elseif (method_exists($result, 'toArray')) {
                        $safeResult = $result->toArray();
                    } else {
                        // For complex objects, just store a summary
                        $safeResult = get_class($result);
                    }
                }
                
            $execution = JobExecutionDocument::log([
                'jobId'      => (string)$this->job->id,
                'workerId'   => $this->worker->getId(),
                    'employer'   => $this->job->employer ?? 'unknown',
                'startedAt'  => new \MongoDB\BSON\UTCDateTime($this->startedAt),
                'finishedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
                'execution_time' => $this->startedAt->diff(new \DateTimeImmutable())->format('%H:%I:%S.%f'),
                'status'     => 'completed',
                    'result'     => $safeResult,
            ]);
            } catch (\Throwable $logError) {
                echo "⚠️ [" . date('H:i:s') . "] Failed to log execution for job {$this->job->id}: {$logError->getMessage()}\n";
                // Continue anyway - don't let logging failure prevent job completion
            }
            
            echo "✅ [" . date('H:i:s') . "] Job {$this->job->id} done by {$this->worker->getId()}\n";
            
            // Try to mark job as completed, but don't let failure block promise resolution
            // The job executed successfully - even if we can't update the status, we should resolve the promise
            $markCompletedSuccess = $this->finalizeSuccessfulJob();
            
            // Resolve the deferred promise - this MUST happen or the worker will block
            // The promise MUST be resolved/rejected even if markCompleted failed
            $resolved = false;
            try {
                $deferred->resolve($execution ?? ['status' => 'completed', 'jobId' => (string)$this->job->id, 'markCompletedSuccess' => $markCompletedSuccess]);
                $resolved = true;
            } catch (\Throwable $resolveError) {
                echo "❌ [" . date('H:i:s') . "] CRITICAL: Failed to resolve promise for job {$this->job->id}: {$resolveError->getMessage()}\n";
                echo "{$resolveError->getTraceAsString()}\n";
                // Even if resolve fails, try to reject so the promise chain doesn't hang
                if (!$resolved) {
                    try {
                        $deferred->reject($resolveError);
                        $resolved = true; // Mark as handled
                    } catch (\Throwable $e) {
                        // If both resolve and reject fail, we're in trouble
                        echo "❌ [" . date('H:i:s') . "] FATAL: Cannot resolve or reject promise for job {$this->job->id}. Worker may be blocked!\n";
                    }
                }
            }
            
            // Final safety check - if we somehow didn't resolve or reject, do it now
            // This should never happen, but protects against edge cases
            if (!$resolved) {
                echo "⚠️ [" . date('H:i:s') . "] WARNING: Promise not resolved for job {$this->job->id}, forcing resolution\n";
                try {
                    $deferred->resolve(['status' => 'completed', 'jobId' => (string)$this->job->id, 'note' => 'force-resolved']);
                } catch (\Throwable $e) {
                    // Last resort - try to reject
                    try {
                        $deferred->reject(new \RuntimeException("Job {$this->job->id} promise resolution failed"));
                    } catch (\Throwable $final) {
                        echo "❌ [" . date('H:i:s') . "] FATAL: Cannot resolve or reject promise for job {$this->job->id} after all attempts. Worker blocked!\n";
                    }
                }
            }
        } catch (\Nraa\Workers\Exceptions\RequeueException $e) {
            // Special handling for RequeueException with capped attempts.
            // RequeueException represents transient conditions (rate limits, login throttles, etc.),
            // but we still enforce maxAttempts to avoid infinite retry loops.
            $currentAttempts = (int)($this->job->attempts ?? 0);
            $newAttempts = $currentAttempts + 1;
            $cappedAttempts = min($newAttempts, $maxAttempts);
            if ($this->disableJobIfSuppressed('after failure', $deferred, $e->getMessage(), $cappedAttempts)) {
                return;
            }

            if ($newAttempts >= $maxAttempts) {
                $finalMessage = $this->buildMaxAttemptsMessage($maxAttempts, $e->getMessage());

                echo "❌ [" . date('H:i:s') . "] Job {$this->job->id} exceeded max attempts ({$maxAttempts}): {$e->getMessage()}\n";

                $this->job->status = 'failed';
                $this->job->attempts = $cappedAttempts;
                $this->job->error = $finalMessage;
                $this->job->failedAt = new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable());
                $this->job->assignee = null;
                $this->job->assignedAt = null;
                $this->job->startedAt = null;
                $this->job->lastHeartbeat = null;
                $this->job->nextRunAt = null;
                $this->job->save();

                JobExecutionDocument::log([
                    'jobId'      => (string)$this->job->id,
                    'workerId'   => $this->worker->getId(),
                    'employer'   => $this->job->employer ?? 'unknown',
                    'startedAt'  => new \MongoDB\BSON\UTCDateTime($this->startedAt),
                    'finishedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
                    'execution_time' => $this->startedAt->diff(new \DateTimeImmutable())->format('%H:%I:%S.%f'),
                    'status'     => 'failed',
                    'error'      => $finalMessage,
                    'attempts'   => $cappedAttempts,
                ]);
                $this->queue->handleJobTerminal($this->job, $this->worker->getId(), 'failed', $finalMessage);

                $deferred->reject(new \RuntimeException($finalMessage, 0, $e));
                return;
            }

            // Exponential backoff: 30s, 2min, 5min, 15min, 30min (capped)
            $backoffDelays = [30, 120, 300, 900, 1800];
            $delaySeconds = $backoffDelays[min($newAttempts - 1, count($backoffDelays) - 1)];

            echo "↩️  [" . date('H:i:s') . "] Job {$this->job->id} returned to queue (attempt {$newAttempts}/{$maxAttempts}): {$e->getMessage()}\n";
            echo "   Next retry in " . gmdate('i:s', $delaySeconds) . "\n";

            if ($this->disableJobIfSuppressed('before retry', $deferred, $e->getMessage(), $cappedAttempts)) {
                return;
            }

            // Mark job as pending again with exponential backoff
            $this->job->status = 'pending';
            $this->job->attempts = $cappedAttempts;
            $this->job->nextRunAt = new \MongoDB\BSON\UTCDateTime((new \DateTimeImmutable())->modify("+{$delaySeconds} seconds"));
            $this->job->assignee = null;
            $this->job->assignedAt = null;
            $this->job->startedAt = null;
            $this->job->lastHeartbeat = null;
            $this->job->error = $e->getMessage();
            $this->job->save();
            $this->queue->handleJobRequeued($this->job, $this->worker->getId(), $delaySeconds, $e->getMessage());

            // Log the requeue (not a failure)
            JobExecutionDocument::log([
                'jobId'      => (string)$this->job->id,
                'workerId'   => $this->worker->getId(),
                'employer'   => $this->job->employer ?? 'unknown',
                'startedAt'  => new \MongoDB\BSON\UTCDateTime($this->startedAt),
                'finishedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
                'execution_time' => $this->startedAt->diff(new \DateTimeImmutable())->format('%H:%I:%S.%f'),
                'status'     => 'requeued',
                'error'      => $e->getMessage(),
                'attempts'   => $cappedAttempts,
                'nextRetryDelay' => $delaySeconds,
            ]);

            $deferred->resolve(['status' => 'requeued', 'message' => $e->getMessage(), 'attempts' => $cappedAttempts]);
        } catch (AutoResolveException $e) {
            $currentAttempts = (int)($this->job->attempts ?? 0);
            $newAttempts = $currentAttempts + 1;
            $cappedAttempts = min($newAttempts, $maxAttempts);

            echo "ℹ️ [" . date('H:i:s') . "] Job {$this->job->id} auto-resolved: {$e->getMessage()}\n";

            JobExecutionDocument::log([
                'jobId'      => (string)$this->job->id,
                'workerId'   => $this->worker->getId(),
                'employer'   => $this->job->employer ?? 'unknown',
                'startedAt'  => new \MongoDB\BSON\UTCDateTime($this->startedAt),
                'finishedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
                'execution_time' => $this->startedAt->diff(new \DateTimeImmutable())->format('%H:%I:%S.%f'),
                'status'     => 'auto_resolved',
                'error'      => $e->getMessage(),
                'attempts'   => $cappedAttempts,
            ]);

            $this->job->attempts = $cappedAttempts;
            $this->job->markAutoResolved($e->getMessage());
            $this->queue->handleJobTerminal($this->job, $this->worker->getId(), 'auto_resolved', $e->getMessage());

            $deferred->resolve([
                'status' => 'auto_resolved',
                'message' => $e->getMessage(),
                'attempts' => $cappedAttempts,
            ]);
            return;
        } catch (\Throwable $e) {
            $currentAttempts = (int)($this->job->attempts ?? 0);
            $newAttempts = $currentAttempts + 1;
            $cappedAttempts = min($newAttempts, $maxAttempts);
            if ($this->disableJobIfSuppressed('after failure', $deferred, $e->getMessage(), $cappedAttempts)) {
                return;
            }

            if (JobRetryStrategy::shouldRetry($newAttempts, $maxAttempts)) {
                $delay = JobRetryStrategy::getDelay($cappedAttempts);

                if ($this->disableJobIfSuppressed('before retry', $deferred, $e->getMessage(), $cappedAttempts)) {
                    return;
                }

                // Queue-based retry: move job back to pending with delay.
                $this->job->status = 'pending';
                $this->job->attempts = $cappedAttempts;
                $this->job->nextRunAt = new \MongoDB\BSON\UTCDateTime((new \DateTimeImmutable())->modify("+{$delay} seconds"));
                $this->job->assignee = null;
                $this->job->assignedAt = null;
                $this->job->startedAt = null;
                $this->job->lastHeartbeat = null;
                $this->job->error = $e->getMessage();
                $this->job->save();
                $this->queue->handleJobRequeued($this->job, $this->worker->getId(), $delay, $e->getMessage());

                echo "⚠️ [" . date('H:i:s') . "] Job {$this->job->id} failed (attempt {$cappedAttempts}/{$maxAttempts}). Requeued in {$delay}s...\n";
                echo "Error: {$e->getMessage()}\n";
                echo "{$e->getTraceAsString()}\n";

                JobExecutionDocument::log([
                    'jobId'      => (string)$this->job->id,
                    'workerId'   => $this->worker->getId(),
                    'employer'   => $this->job->employer ?? 'unknown',
                    'startedAt'  => new \MongoDB\BSON\UTCDateTime($this->startedAt),
                    'finishedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
                    'execution_time' => $this->startedAt->diff(new \DateTimeImmutable())->format('%H:%I:%S.%f'),
                    'status'     => 'requeued',
                    'error'      => $e->getMessage(),
                    'attempts'   => $cappedAttempts,
                    'nextRetryDelay' => $delay,
                ]);

                $deferred->resolve([
                    'status' => 'requeued',
                    'message' => $e->getMessage(),
                    'attempts' => $cappedAttempts,
                    'nextRetryDelay' => $delay,
                ]);
                return;
            }

            // Final failure after all retries exhausted
            JobExecutionDocument::log([
                'jobId'      => (string)$this->job->id,
                'workerId'   => $this->worker->getId(),
                'employer'   => $this->job->employer ?? 'unknown',
                'startedAt'  => new \MongoDB\BSON\UTCDateTime($this->startedAt),
                'finishedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
                'execution_time' => $this->startedAt->diff(new \DateTimeImmutable())->format('%H:%I:%S.%f'),
                'status'     => 'failed',
                'error'      => $e->getMessage(),
                'attempts'   => $cappedAttempts,
            ]);

            // Count the terminal failed run as an attempt.
            $this->job->attempts = $cappedAttempts;
            $this->job->markFailed($e->getMessage());
            $this->queue->handleJobTerminal($this->job, $this->worker->getId(), 'failed', $e->getMessage());

            $deferred->reject($e);
        }
    }

    private function finalizeSuccessfulJob(): bool
    {
        $jobId = trim((string)($this->job->id ?? ''));
        if ($jobId === '') {
            return false;
        }

        $workerId = $this->worker->getId();
        $lastError = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $job = $attempt === 1
                    ? $this->job
                    : JobDocument::findOne(['_id' => new ObjectId($jobId)]);

                if (!$job instanceof JobDocument) {
                    throw new \RuntimeException('Job document missing during successful finalization');
                }

                $job->markCompleted();
                $this->queue->handleJobCompleted($job, $workerId);
                $this->job = $job;

                return true;
            } catch (\Throwable $markError) {
                $lastError = $markError;
                echo "❌ [" . date('H:i:s') . "] Failed to finalize completed job {$jobId} (attempt {$attempt}/3): {$markError->getMessage()}\n";
                if ($attempt < 3) {
                    usleep($attempt * 200000);
                }
            }
        }

        if ($lastError instanceof \Throwable) {
            echo "❌ [" . date('H:i:s') . "] CRITICAL: Job {$jobId} executed successfully but could not be finalized after retries: {$lastError->getMessage()}\n";
        }

        return false;
    }

    private function buildMaxAttemptsMessage(int $maxAttempts, string $reason): string
    {
        $cleanedReason = trim(preg_replace('/\s+Job will be retried\.?$/i', '', $reason) ?? $reason);
        return "Max attempts exceeded ({$maxAttempts}): {$cleanedReason}. No more retries.";
    }

    private function disableJobIfSuppressed(
        string $context,
        Deferred $deferred,
        ?string $message = null,
        ?int $attempts = null
    ): bool {
        $jobClass = JobTypeControlService::extractJobClassFromTask($this->job->task ?? []);
        $controls = new JobTypeControlService();
        if (!$controls->isJobTypeDisabled($jobClass)) {
            return false;
        }

        $controls->markJobDocumentDisabled($this->job);
        JobExecutionDocument::log([
            'jobId'      => (string)$this->job->id,
            'workerId'   => $this->worker->getId(),
            'employer'   => $this->job->employer ?? 'unknown',
            'startedAt'  => new \MongoDB\BSON\UTCDateTime($this->startedAt),
            'finishedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
            'execution_time' => $this->startedAt->diff(new \DateTimeImmutable())->format('%H:%I:%S.%f'),
            'status'     => JobTypeControlService::JOB_STATUS_DISABLED,
            'error'      => trim($message ?? '') !== '' ? $message : "Job type disabled {$context}",
            'attempts'   => $attempts,
        ]);
        $this->queue->handleJobTerminal(
            $this->job,
            $this->worker->getId(),
            JobTypeControlService::JOB_STATUS_DISABLED,
            trim($message ?? '') !== '' ? $message : "Job type disabled {$context}"
        );
        $deferred->resolve([
            'status' => JobTypeControlService::JOB_STATUS_DISABLED,
            'message' => "Job type disabled {$context}",
            'attempts' => $attempts,
        ]);

        return true;
    }
}
