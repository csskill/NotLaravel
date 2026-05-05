<?php

namespace Nraa\Workers;

use React\Promise\Deferred;

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
     * Retry semantics are owned by JobExecution; this pool only handles concurrency.
     *
     * @param callable $task The task to be executed by a worker process.
     * @param mixed $job The associated job document.
     * @param int $maxAttempts Reserved for compatibility with existing callers.
     * @param int $attempt Reserved for compatibility with existing callers.
     * @return \React\Promise\PromiseInterface The promise that will be resolved or rejected by the job execution process.
     */
    public function enqueue(callable $task, $job, ?int $maxAttempts = null, int $attempt = 1): \React\Promise\PromiseInterface
    {
        $deferred = new Deferred();

        $wrapper = function () use ($task, $job, $deferred) {
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
                function ($result) use ($deferred) {
                    $this->running--;

                    // Status already updated by JobExecution::executeAsync()
                    $deferred->resolve($result);
                    $this->next();
                },
                function ($error) use ($deferred) {
                    $this->running--;

                    // Retry and attempt accounting are handled in JobExecution.
                    $deferred->reject($error);

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
