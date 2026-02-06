<?php

namespace Nraa\Pillars\Console;

use Nraa\Workers\{
    JobQueue,
    Worker,
    JobPool,
    JobExecution,
    JobHeartbeat,
    JobLogger
};
use React\EventLoop\Loop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use React\Promise\Deferred;

#[AsCommand(
    name: 'app:job-runner',
    description: 'Run a single worker process',
)]
class JobRunnerCommand extends Command
{
    /**
     * Configure the command.
     *
     * This command requires a single argument, the worker identifier.
     */
    protected function configure(): void
    {
        $this->addArgument('workerId', InputArgument::REQUIRED, 'Worker identifier');
    }

    /**
     * Execute the command.
     *
     * This command will start a worker process and run until stopped.
     * It will check for jobs every 10 seconds and process them
     * concurrently.
     *
     * @param InputInterface $input The input interface
     * @param OutputInterface $output The output interface
     *
     * @return int The exit status of the command
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workerId = $input->getArgument('workerId');
        $worker = new Worker($workerId, 5);
        $queue = new JobQueue();
        $pool = new JobPool(5, $worker);
        $output->writeln("🚀 $workerId started (PID " . getmypid() . ")");
        
        // Log worker startup
        JobLogger::info([
            'worker_id' => $workerId,
            'message' => "Job runner started",
            'metadata' => ['pid' => getmypid()]
        ]);
        
        Loop::addPeriodicTimer(10, function () use ($pool, $queue, $worker, $output) {
            //$output->writeln("🚀 {$worker->getId()} checking for jobs");

            try {
                while ($job = $queue->getNextJob($worker->getId())) {
                    $output->writeln("[" . date('H:i:s') . "]  🚀 {$worker->getId()} processing job {$job->id}");

                    // Log job start
                    JobLogger::info([
                        'job_id' => (string)$job->id,
                        'worker_id' => $worker->getId(),
                        'pool' => $job->pool ?? 'general',
                        'message' => "Job processing started",
                    ]);

                    // Start heartbeat timer for this job
                    $heartbeatInterval = JobHeartbeat::getInterval();
                    $heartbeatTimer = Loop::addPeriodicTimer($heartbeatInterval, function () use ($job) {
                        JobHeartbeat::update((string)$job->id);
                    });

                    // Initial heartbeat
                    JobHeartbeat::update((string)$job->id);

                    try {
                        $executor = new JobExecution($worker, $job);
                        $pool->enqueue(function () use ($executor, $output, $job, $heartbeatTimer) {
                            try {
                                return new \React\Promise\Promise(function ($resolve, $reject) use ($executor, $output, $job, $heartbeatTimer) {
                                    try {
                                        $deferred = new Deferred();
                                        $deferred->promise()->then(
                                            function ($result) use ($heartbeatTimer, $resolve) {
                                                // Stop heartbeat on success
                                                Loop::cancelTimer($heartbeatTimer);
                                                $resolve($result);
                                            },
                                            function ($error) use ($heartbeatTimer, $reject) {
                                                // Stop heartbeat on failure
                                                Loop::cancelTimer($heartbeatTimer);
                                                $reject($error);
                                            }
                                        );
                                        $executor->executeAsync($deferred);
                                    } catch (\Throwable $e) {
                                        // Stop heartbeat on error
                                        Loop::cancelTimer($heartbeatTimer);
                                        $output->writeln("❌ Error creating promise for job {$job->id}: {$e->getMessage()}");
                                        $output->writeln("{$e->getTraceAsString()}");
                                        $reject($e);
                                    }
                                });
                            } catch (\Throwable $e) {
                                $output->writeln("❌ Error in task function for job {$job->id}: {$e->getMessage()}");
                                $output->writeln("{$e->getTraceAsString()}");
                                // Return a rejected promise so the error is handled by the pool
                                return \React\Promise\reject($e);
                            }
                        }, $job, $job->maxAttempts ?? 3)->otherwise(function ($error) use ($job, $output, $heartbeatTimer) {
                            // Stop heartbeat on unhandled error
                            Loop::cancelTimer($heartbeatTimer);
                            // This catches unhandled promise rejections
                            $output->writeln("❌ Unhandled error for job {$job->id}: {$error->getMessage()}");
                            if (method_exists($error, 'getTraceAsString')) {
                                $output->writeln("{$error->getTraceAsString()}");
                            }
                        });
                    } catch (\Throwable $e) {
                        // Stop heartbeat on exception
                        Loop::cancelTimer($heartbeatTimer);
                        $output->writeln("❌ Failed to enqueue job {$job->id}: {$e->getMessage()}");
                        $output->writeln("{$e->getTraceAsString()}");
                        try {
                            $job->markFailed('Failed to enqueue: ' . $e->getMessage());
                        } catch (\Throwable $saveError) {
                            $output->writeln("❌ Failed to mark job as failed: {$saveError->getMessage()}");
                        }
                    }
                }
            } catch (\Throwable $e) {
                $output->writeln("❌ Error in job check loop: {$e->getMessage()}");
            }
        });

        Loop::run();
        return Command::SUCCESS;
    }
}
