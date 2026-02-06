<?php

namespace Nraa\Workers;

use React\EventLoop\Loop;
use Nraa\Workers\Documents\JobDocument;
use Nraa\Workers\Documents\JobExecutionDocument;
use Nraa\Workers\JobRetryStrategy;
use \React\Promise\Deferred;
use function Opis\Closure\{serialize, unserialize};

class JobExecution
{
    protected Worker $worker;
    protected $job;
    protected \DateTimeImmutable $startedAt;

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
     * @param int $attempt The current attempt number (1-based).
     */
    public function executeAsync(Deferred $deferred, ?int $maxAttempts = null, int $attempt = 1): void
    {
        // Use job's maxAttempts if not provided, default to 3
        $maxAttempts = $maxAttempts ?? $this->job->maxAttempts ?? 3;

        // Job is already marked as 'in_progress' by getNextJob() atomic operation
        // No need to update status again - this caused race conditions!

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
            $markCompletedSuccess = false;
            try {
            $this->job->markCompleted();
                $markCompletedSuccess = true;
            } catch (\Throwable $markError) {
                echo "❌ [" . date('H:i:s') . "] Failed to mark job {$this->job->id} as completed: {$markError->getMessage()}\n";
                echo "{$markError->getTraceAsString()}\n";
                // Don't re-throw - we'll still resolve the promise so the worker doesn't block
                // The job executed successfully, even if we couldn't update the status in the database
            }
            
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
            // Special handling for RequeueException - return job to queue without counting as failure

            // Increment attempts counter and calculate exponential backoff
            $currentAttempts = $this->job->attempts ?? 0;
            $newAttempts = $currentAttempts + 1;
            $maxAttempts = $this->job->maxAttempts ?? 10; // Default to 10 if not specified

            // Check if we've exceeded max attempts
            if ($newAttempts >= $maxAttempts) {
                echo "❌ [" . date('H:i:s') . "] Job {$this->job->id} exceeded max attempts ({$maxAttempts}): {$e->getMessage()}\n";

                JobExecutionDocument::log([
                    'jobId'      => (string)$this->job->id,
                    'workerId'   => $this->worker->getId(),
                    'employer'   => $this->job->employer ?? 'unknown',
                    'startedAt'  => new \MongoDB\BSON\UTCDateTime($this->startedAt),
                    'finishedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
                    'execution_time' => $this->startedAt->diff(new \DateTimeImmutable())->format('%H:%I:%S.%f'),
                    'status'     => 'failed',
                    'error'      => "Max attempts exceeded ({$maxAttempts}): " . $e->getMessage(),
                    'attempts'   => $newAttempts,
                ]);

                $this->job->markFailed("Max attempts exceeded ({$maxAttempts}): " . $e->getMessage());
                $deferred->reject(new \Exception("Max attempts exceeded: " . $e->getMessage()));
                return;
            }

            // Exponential backoff: 30s, 2min, 5min, 15min, 30min (capped)
            $backoffDelays = [30, 120, 300, 900, 1800];
            $delaySeconds = $backoffDelays[min($newAttempts - 1, count($backoffDelays) - 1)];

            echo "↩️  [" . date('H:i:s') . "] Job {$this->job->id} returned to queue (attempt {$newAttempts}/{$maxAttempts}): {$e->getMessage()}\n";
            echo "   Next retry in " . gmdate('i:s', $delaySeconds) . "\n";

            // Mark job as pending again with exponential backoff
            $this->job->status = 'pending';
            $this->job->attempts = $newAttempts;
            $this->job->nextRunAt = new \MongoDB\BSON\UTCDateTime((new \DateTimeImmutable())->modify("+{$delaySeconds} seconds"));
            $this->job->assignee = null;
            $this->job->startedAt = null;
            $this->job->save();

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
                'attempts'   => $newAttempts,
                'nextRetryDelay' => $delaySeconds,
            ]);

            $deferred->resolve(['status' => 'requeued', 'message' => $e->getMessage(), 'attempts' => $newAttempts]);
        } catch (\Throwable $e) {
            if (JobRetryStrategy::shouldRetry($attempt, $maxAttempts)) {
                $delay = JobRetryStrategy::getDelay($attempt);
                
                // Increment attempts counter
                $this->job->attempts = ($this->job->attempts ?? 0) + 1;
                $this->job->assignee = null;
                $this->job->startedAt = null;
                $this->job->save();
                
                echo "⚠️ [" . date('H:i:s') . "] Job {$this->job->id} failed (attempt {$attempt}/{$maxAttempts}). Retrying in {$delay}s...\n";
                echo "Error: {$e->getMessage()}\n";
                echo "{$e->getTraceAsString()}\n";
                Loop::addTimer($delay, function () use ($deferred, $maxAttempts, $attempt) {
                    $this->executeAsync($deferred, $maxAttempts, $attempt + 1);
                });
                return; // Important: exit early, don't mark as failed yet
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
                'attempts'   => $attempt,
            ]);

            $this->job->markFailed($e->getMessage());

            $deferred->reject($e);
        }
    }
}
