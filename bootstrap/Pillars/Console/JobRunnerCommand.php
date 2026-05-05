<?php

namespace Nraa\Pillars\Console;

use Nraa\Workers\JobExecution;
use Nraa\Workers\JobHeartbeat;
use Nraa\Workers\JobHeartbeatProcessManager;
use Nraa\Workers\JobLogger;
use Nraa\Workers\JobPool;
use Nraa\Workers\JobQueue;
use Nraa\Workers\JobRealtimeStateService;
use Nraa\Workers\PoolManager;
use Nraa\Workers\Worker;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:job-runner',
    description: 'Run a single worker process',
)]
class JobRunnerCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('workerId', InputArgument::REQUIRED, 'Worker identifier')
            ->addOption('pool', null, InputOption::VALUE_REQUIRED, 'Worker pool name')
            ->addOption('worker-index', null, InputOption::VALUE_REQUIRED, 'Worker index within pool', 0)
            ->addOption('capacity', null, InputOption::VALUE_REQUIRED, 'Real slot capacity for this runner', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
        }

        $workerId = trim((string)$input->getArgument('workerId'));
        $poolName = strtolower(trim((string)$input->getOption('pool')));
        $workerIndex = (int)$input->getOption('worker-index');

        $poolManager = new PoolManager();
        if ($poolName === '') {
            $poolName = $this->resolvePoolNameFromWorkerId($workerId, $poolManager);
        }
        $poolConfig = $poolManager->getPoolConfig($poolName);
        $workerConfig = $poolManager->getWorkerConfig($poolName, $workerIndex);
        $capacityOverride = trim((string)$input->getOption('capacity'));
        $runnerCapacity = $capacityOverride !== '' ? max(1, (int)$capacityOverride) : max(1, (int)$poolConfig['capacity']);

        $worker = new Worker($workerId, $runnerCapacity, $poolName, $workerConfig);
        $queue = new JobQueue();
        $pool = new JobPool($runnerCapacity, $worker);
        $pollInterval = max(1, (int)($_ENV['JOB_RUNNER_POLL_INTERVAL_SECONDS'] ?? 2));
        $workerHeartbeatInterval = max(5, (int)($_ENV['JOB_WORKER_STATE_HEARTBEAT_SECONDS'] ?? 10));
        $realtimeState = JobRealtimeStateService::getInstance();
        $workerRealtimeMetadata = [
            'pid' => (int)getmypid(),
            'worker_index' => $workerIndex,
            'capacity' => $runnerCapacity,
            'timeout' => (int)($poolConfig['timeout'] ?? 0),
        ];

        $output->writeln("🚀 {$workerId} started (PID " . getmypid() . ")");

        $realtimeState->registerWorker($workerId, $poolName, $workerRealtimeMetadata);

        JobLogger::info([
            'worker_id' => $workerId,
            'pool' => $poolName,
            'message' => 'Job runner started',
            'metadata' => [
                'pid' => getmypid(),
                'capacity' => $runnerCapacity,
                'poll_interval_seconds' => $pollInterval,
            ],
        ]);

        $cleanup = function (int $signo = 0) use ($realtimeState, $workerId, $poolName, $workerRealtimeMetadata): void {
            $realtimeState->unregisterWorker($workerId, $poolName, $workerRealtimeMetadata);
        };

        if (function_exists('pcntl_signal') && defined('SIGTERM') && defined('SIGINT')) {
            pcntl_signal(SIGTERM, $cleanup);
            pcntl_signal(SIGINT, $cleanup);
        }

        if (method_exists(Loop::class, 'addSignal') && defined('SIGTERM') && defined('SIGINT')) {
            Loop::addSignal(SIGTERM, static function () use ($cleanup): void {
                $cleanup(SIGTERM);
                exit(0);
            });
            Loop::addSignal(SIGINT, static function () use ($cleanup): void {
                $cleanup(SIGINT);
                exit(0);
            });
        }

        register_shutdown_function(static function () use ($cleanup): void {
            $cleanup(0);
        });

        Loop::addPeriodicTimer($workerHeartbeatInterval, function () use ($realtimeState, $workerId, $poolName, $workerRealtimeMetadata): void {
            $realtimeState->heartbeatWorker($workerId, $poolName, $workerRealtimeMetadata);
        });

        Loop::addPeriodicTimer($pollInterval, function () use ($pool, $queue, $worker, $output, $poolName, $workerConfig): void {
            try {
                while ($job = $queue->getNextJob($worker->getId(), $poolName, $workerConfig)) {
                    $output->writeln("[" . date('H:i:s') . "] 🚀 {$worker->getId()} processing job {$job->id}");

                    JobLogger::info([
                        'job_id' => (string)$job->id,
                        'worker_id' => $worker->getId(),
                        'pool' => $job->pool ?? 'general',
                        'message' => 'Job processing started',
                    ]);

                    JobHeartbeat::update((string)$job->id);
                    $heartbeatManager = new JobHeartbeatProcessManager(
                        (string)$job->id,
                        JobHeartbeat::getInterval(),
                        (int)getmypid()
                    );
                    $heartbeatManager->start();

                    try {
                        $executor = new JobExecution($worker, $job);
                        $pool->enqueue(function () use ($executor, $output, $job, $heartbeatManager) {
                            try {
                                return new \React\Promise\Promise(function ($resolve, $reject) use ($executor, $output, $job, $heartbeatManager): void {
                                    try {
                                        $deferred = new Deferred();
                                        $deferred->promise()->then(
                                            function ($result) use ($heartbeatManager, $resolve): void {
                                                $heartbeatManager->stop();
                                                $resolve($result);
                                            },
                                            function ($error) use ($heartbeatManager, $reject): void {
                                                $heartbeatManager->stop();
                                                $reject($error);
                                            }
                                        );
                                        $executor->executeAsync($deferred);
                                    } catch (\Throwable $e) {
                                        $heartbeatManager->stop();
                                        $output->writeln("❌ Error creating promise for job {$job->id}: {$e->getMessage()}");
                                        $output->writeln("{$e->getTraceAsString()}");
                                        $reject($e);
                                    }
                                });
                            } catch (\Throwable $e) {
                                $output->writeln("❌ Error in task function for job {$job->id}: {$e->getMessage()}");
                                $output->writeln("{$e->getTraceAsString()}");
                                return \React\Promise\reject($e);
                            }
                        }, $job, $job->maxAttempts ?? 3)->otherwise(function ($error) use ($job, $output, $heartbeatManager): void {
                            $heartbeatManager->stop();
                            $message = method_exists($error, 'getMessage') ? (string)$error->getMessage() : (string)$error;
                            if (str_starts_with($message, 'Max attempts exceeded')) {
                                $output->writeln("❌ Job {$job->id} failed permanently: {$message}");
                                return;
                            }

                            $output->writeln("❌ Job {$job->id} failed unexpectedly: {$message}");
                            if (method_exists($error, 'getTraceAsString')) {
                                $output->writeln("{$error->getTraceAsString()}");
                            }
                        });
                    } catch (\Throwable $e) {
                        $heartbeatManager->stop();
                        $output->writeln("❌ Failed to enqueue job {$job->id}: {$e->getMessage()}");
                        $output->writeln("{$e->getTraceAsString()}");
                        try {
                            $queue->markFailed((string)$job->id, 'Failed to enqueue: ' . $e->getMessage());
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

    private function resolvePoolNameFromWorkerId(string $workerId, PoolManager $poolManager): string
    {
        $parts = explode('-', $workerId, 2);
        $candidate = strtolower(trim((string)($parts[0] ?? '')));
        if ($candidate !== '' && $poolManager->poolExists($candidate)) {
            return $candidate;
        }

        return 'general';
    }
}
