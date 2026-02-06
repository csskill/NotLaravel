<?php

namespace Nraa\Workers;

use React\Promise\Deferred;
use React\EventLoop\Loop;
use Nraa\Workers\Documents\JobExecutionDocument;
use Nraa\Workers\JobRetryStrategy;

class JobPool
{
    private int $maxConcurrency;
    private int $running = 0;
    private \SplQueue $queue;

    private $worker; // needed for logging

    /**
     * Construct a new JobPool instance.
     *
     * @param int $maxConcurrency The maximum number of worker processes to run concurrently.
     * @param mixed $worker The worker object that will be used for logging.
     */
    public function __construct(int $maxConcurrency, $worker)
    {
        $this->maxConcurrency = $maxConcurrency;
        $this->queue = new \SplQueue();
        $this->worker = $worker;
    }


    /**
     * Enqueue a new task to be executed by a worker process.
     *
     * The task is a callable that will be executed by a worker process.
     * The job is the associated job document.
     * The maxRetries parameter sets the maximum number of times to retry the job if it fails.
     * The attempt parameter sets the current attempt number.
     *
     * The method returns a promise that will be resolved or rejected by the job execution process.
     * The promise will be resolved with the result of the job or rejected with an exception if the job fails.
     *
     * If the job is retried, the next attempt will be scheduled after a fixed delay:
     * - Attempt 1: 30 seconds
     * - Attempt 2: 60 seconds (1 minute)
     * - Attempt 3: 120 seconds (2 minutes)
     * If the job is retried after the maximum number of attempts, the job will be marked as failed and the promise will be rejected with an exception.
     *
     * @param callable $task The task to be executed by a worker process.
     * @param mixed $job The associated job document.
     * @param int $maxAttempts The maximum number of attempts (defaults to job's maxAttempts or 3).
     * @param int $attempt The current attempt number (1-based).
     * @return \React\Promise\PromiseInterface The promise that will be resolved or rejected by the job execution process.
     */
    public function enqueue(callable $task, $job, ?int $maxAttempts = null, int $attempt = 1): \React\Promise\PromiseInterface
    {
        $deferred = new Deferred();

        // Use job's maxAttempts if not provided, default to 3
        $maxAttempts = $maxAttempts ?? $job->maxAttempts ?? 3;
        
        $wrapper = function () use ($task, $job, $deferred, $maxAttempts, $attempt) {
            $this->running++;

            try {
            $promise = $task();
                
                if (!($promise instanceof \React\Promise\PromiseInterface)) {
                    throw new \RuntimeException("Task did not return a PromiseInterface for job {$job->id}");
                }
            } catch (\Throwable $e) {
                $this->running--;
                echo "❌ Error executing task wrapper for job {$job->id}: {$e->getMessage()}\n";
                echo "{$e->getTraceAsString()}\n";
                $deferred->reject($e);
                $this->next();
                return;
            }

            $promise->then(
                function ($result) use ($job, $deferred, $attempt) {
                    $this->running--;

                    JobExecutionDocument::log([
                        'jobId'      => (string)$job->id,
                        'workerId'   => $this->worker->getId(),
                        'employer'   => $job->employer ?? 'unknown',
                        'startedAt'  => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
                        'finishedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
                        'status'     => 'completed',
                        'attempt'    => $attempt,
                        'result'     => $result,
                    ]);

                    // Status already updated by JobExecution::executeAsync()
                    $deferred->resolve($result);
                    $this->next();
                },
                function ($error) use ($task, $job, $deferred, $maxAttempts, $attempt) {
                    $this->running--;

                    JobExecutionDocument::log([
                        'jobId'      => (string)$job->id,
                        'workerId'   => $this->worker->getId(),
                        'employer'   => $job->employer ?? 'unknown',
                        'startedAt'  => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
                        'finishedAt' => new \MongoDB\BSON\UTCDateTime(new \DateTimeImmutable()),
                        'status'     => 'failed',
                        'attempt'    => $attempt,
                        'error'      => $error->getMessage(),
                    ]);

                    if (JobRetryStrategy::shouldRetry($attempt, $maxAttempts)) {
                        $delay = JobRetryStrategy::getDelay($attempt);
                        echo "⚠️ Job {$job->id} failed (attempt {$attempt}/{$maxAttempts}). Retrying in {$delay}s...\n";
                        echo "Error: {$error->getMessage()}\n";
                        echo "{$error->getTraceAsString()}\n";
                        Loop::addTimer($delay, function () use ($task, $job, $deferred, $maxAttempts, $attempt) {
                            $this->enqueue($task, $job, $maxAttempts, $attempt + 1)
                                ->then([$deferred, 'resolve'], [$deferred, 'reject']);
                        });
                    } else {
                        echo "❌ Job {$job->id} failed after {$attempt} attempts (max: {$maxAttempts})\n";
                        // Status already updated by JobExecution::executeAsync()
                        $deferred->reject($error);
                    }

                    $this->next();
                }
            );
        };

        if ($this->running < $this->maxConcurrency) {
            $wrapper();
        } else {
            $this->queue->enqueue($wrapper);
        }

        return $deferred->promise();
    }

    /**
     * Process the next task in the queue if there are available worker processes.
     *
     * This method will dequeue the next task from the queue and execute it if there are available worker processes.
     * If there are no available worker processes, the task will remain in the queue until a worker process is available.
     */
    private function next(): void
    {
        if (!$this->queue->isEmpty() && $this->running < $this->maxConcurrency) {
            $task = $this->queue->dequeue();
            $task();
        }
    }
}
