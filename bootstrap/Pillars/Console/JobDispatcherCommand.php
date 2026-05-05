<?php

namespace Nraa\Pillars\Console;

use Nraa\Workers\DistributedLock;
use Nraa\Workers\JobManager;
use Nraa\Workers\JobQueue;
use Nraa\Workers\JobRealtimeStateService;
use Nraa\Workers\PoolManager;
use Nraa\Workers\RecurringJobs;
use Nraa\Workers\ScheduledJobs;
use Nraa\Workers\Worker;
use Nraa\Services\FutureDataArchitecture\OperationalRetentionService;
use React\EventLoop\Loop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:job-dispatcher',
    description: 'Dedicated queue dispatcher and scheduler coordinator',
)]
class JobDispatcherCommand extends Command
{
    private const DEFAULT_HEARTBEAT_PATH = '/tmp/job-dispatcher-heartbeat';

    protected function configure(): void
    {
        $this
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run a single dispatch iteration and exit')
            ->addOption('pools', null, InputOption::VALUE_REQUIRED, 'Optional comma-separated pool list override');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->bootstrapOperationalRetention($output);

        $poolManager = new PoolManager();
        $poolNames = $this->resolveDispatcherPools($input, $poolManager);
        $realtimeState = JobRealtimeStateService::getInstance();
        $queue = new JobQueue();
        $coordinatorId = 'job-dispatcher:' . (gethostname() ?: 'unknown') . ':' . (getmypid() ?: 0);
        $dispatcherLock = new DistributedLock($coordinatorId);
        $intervalSeconds = max(2, (int)($_ENV['JOB_DISPATCHER_INTERVAL_SECONDS'] ?? 5));
        $snapshotIntervalSeconds = max($intervalSeconds, (int)($_ENV['JOB_REALTIME_SNAPSHOT_INTERVAL_SECONDS'] ?? 15));
        $lastSnapshotAt = 0;
        $heartbeatPath = $this->getHeartbeatPath();
        $runOnce = (bool)$input->getOption('once');

        $runIteration = function () use ($dispatcherLock, $poolManager, $realtimeState, $queue, $output, $poolNames, $intervalSeconds, $snapshotIntervalSeconds, &$lastSnapshotAt, $coordinatorId, $heartbeatPath, $runOnce): void {
            try {
                $lockTtl = max(15, $intervalSeconds * 3);
                if (!$dispatcherLock->acquire('job_dispatcher:loop', $lockTtl)) {
                    return;
                }

                $workers = $this->buildWorkers($poolNames, $poolManager, $realtimeState);
                $manager = new JobManager(
                    $queue,
                    $workers,
                    new ScheduledJobs(),
                    new RecurringJobs(),
                    $coordinatorId
                );

                $now = new \DateTimeImmutable();
                $this->touchHeartbeat($heartbeatPath);
                $manager->fetchAndQueueDueJobs($now, fn() => $this->touchHeartbeat($heartbeatPath));

                $this->touchHeartbeat($heartbeatPath);
                $recovered = 0;
                foreach ($poolNames as $recoveryPoolName) {
                    $recovered += $manager->recoverStaleJobs(null, $recoveryPoolName);
                }

                $this->touchHeartbeat($heartbeatPath);
                $stats = $manager->distributeJobs($poolNames);

                $shouldSyncSnapshot = (time() - $lastSnapshotAt) >= $snapshotIntervalSeconds;
                if ($shouldSyncSnapshot) {
                    $this->touchHeartbeat($heartbeatPath);
                    $queue->syncRealtimeSnapshot();
                    $lastSnapshotAt = time();
                }

                $this->touchHeartbeat($heartbeatPath);

                if ($recovered > 0 || ($stats['assigned_jobs'] ?? 0) > 0) {
                    $output->writeln(sprintf(
                        '[%s] dispatch assigned=%d considered=%d recovered=%d pools=%s',
                        date('H:i:s'),
                        (int)($stats['assigned_jobs'] ?? 0),
                        (int)($stats['considered_jobs'] ?? 0),
                        (int)$recovered,
                        implode(',', $poolNames)
                    ));
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf(
                    '[%s] dispatcher iteration failed: %s',
                    date('H:i:s'),
                    $e->getMessage()
                ));

                if ($runOnce) {
                    throw $e;
                }
            }
        };

        $this->touchHeartbeat($heartbeatPath);

        if ($runOnce) {
            try {
                $runIteration();
                return Command::SUCCESS;
            } catch (\Throwable) {
                return Command::FAILURE;
            }
        }

        $output->writeln('⚙️  Starting dedicated job dispatcher');
        $output->writeln('   Pools: <info>' . implode(', ', $poolNames) . '</info>');
        $output->writeln('   Interval: <info>' . $intervalSeconds . "s</info>");
        $output->writeln('   Snapshot sync: <info>' . $snapshotIntervalSeconds . "s</info>");

        $runIteration();
        Loop::addPeriodicTimer($intervalSeconds, $runIteration);
        Loop::run();

        return Command::SUCCESS;
    }

    private function bootstrapOperationalRetention(OutputInterface $output): void
    {
        $result = (new OperationalRetentionService())->enforceRuntimeRetentionIfEnabled();
        if (($result['status'] ?? '') === 'applied') {
            $output->writeln('   Phase 0 retention: <info>applied</info>');
            return;
        }

        if (($result['status'] ?? '') === 'error') {
            $output->writeln('   Phase 0 retention: <error>failed</error> (' . (string)($result['reason'] ?? 'unknown') . ')');
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveDispatcherPools(InputInterface $input, PoolManager $poolManager): array
    {
        $override = trim((string)$input->getOption('pools'));
        if ($override === '') {
            $override = trim((string)($_ENV['JOB_DISPATCHER_POOLS'] ?? ''));
        }

        $poolNames = [];
        if ($override !== '') {
            $poolNames = array_values(array_unique(array_filter(array_map(
                static fn(string $poolName): string => strtolower(trim($poolName)),
                explode(',', $override)
            ), static fn(string $poolName): bool => $poolName !== '')));
        }

        if ($poolNames === []) {
            $poolNames = array_keys($poolManager->getAllPools());
        }

        return array_values(array_filter($poolNames, static fn(string $poolName) => $poolManager->poolExists($poolName)));
    }

    /**
     * @param array<int, string> $poolNames
     * @return array<int, Worker>
     */
    private function buildWorkers(array $poolNames, PoolManager $poolManager, JobRealtimeStateService $realtimeState): array
    {
        if ($realtimeState->isEnabled()) {
            $workers = [];
            foreach ($realtimeState->getRunningWorkers() as $workerState) {
                $poolName = strtolower(trim((string)($workerState['pool'] ?? '')));
                if ($poolName === '' || !in_array($poolName, $poolNames, true) || !$poolManager->poolExists($poolName)) {
                    continue;
                }

                $workerId = trim((string)($workerState['worker_id'] ?? ''));
                if ($workerId === '') {
                    continue;
                }

                $workerIndex = max(0, (int)($workerState['worker_index'] ?? 0));
                $poolConfig = $poolManager->getPoolConfig($poolName);
                $workerCapacity = max(1, (int)($workerState['capacity'] ?? $poolConfig['capacity'] ?? 1));
                $workers[] = new Worker(
                    $workerId,
                    $workerCapacity,
                    $poolName,
                    $poolManager->getWorkerConfig($poolName, $workerIndex)
                );
            }

            return $workers;
        }

        $workers = [];
        foreach ($poolNames as $poolName) {
            $poolConfig = $poolManager->getPoolConfig($poolName);
            $workerCount = $poolManager->getWorkerCount($poolName);
            $capacity = max(1, (int)($poolConfig['capacity'] ?? 1));

            for ($i = 0; $i < $workerCount; $i++) {
                $workers[] = new Worker(
                    "{$poolName}-{$i}",
                    $capacity,
                    $poolName,
                    $poolManager->getWorkerConfig($poolName, $i)
                );
            }
        }

        return $workers;
    }

    private function getHeartbeatPath(): string
    {
        $path = trim((string)($_ENV['JOB_DISPATCHER_HEARTBEAT_PATH'] ?? self::DEFAULT_HEARTBEAT_PATH));
        return $path !== '' ? $path : self::DEFAULT_HEARTBEAT_PATH;
    }

    private function touchHeartbeat(string $heartbeatPath): void
    {
        $directory = dirname($heartbeatPath);
        if (!is_dir($directory)) {
            @mkdir($directory, 0777, true);
        }

        @file_put_contents($heartbeatPath, (string)microtime(true), LOCK_EX);
        @touch($heartbeatPath);
    }
}
