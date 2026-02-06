<?php

namespace Nraa\Pillars\Console;

use Nraa\Workers\{
    JobRegistrar,
    JobQueue,
    ScheduledJobs,
    RecurringJobs,
    Worker,
    JobManager,
    PoolManager,
    JobLogger
};
use React\EventLoop\Loop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Process\Process;


#[AsCommand(
    name: 'app:job-worker',
    description: 'Supervisor for job workers',
)]
class JobWorkerCommand extends Command
{
    /**
     * Configure the command.
     *
     * Adds required pool argument and optional worker-index option.
     * Pool determines which type of jobs this worker will handle.
     * Worker-index is used by supervisor to assign unique configs (e.g., Steam accounts).
     */
    protected function configure(): void
    {
        $this
            ->addArgument('pool', InputArgument::REQUIRED, 'Pool name (download, parse, calculation, general)')
            ->addOption('worker-index', null, InputOption::VALUE_REQUIRED, 'Worker index within pool (for config assignment)', 0);
    }

    /**
     * Start a single worker for the specified pool.
     *
     * This command starts a single worker process for a specific pool.
     * The pool determines job types, capacity, and timeout.
     * Worker-specific config (e.g., Steam accounts) is loaded via worker_config_provider.
     *
     * Every 60 seconds, it will fetch scheduled jobs that are due to be
     * executed and distribute them to the worker.
     *
     * @return int The exit status of the command.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Enable async signal handling for ReactPHP
        pcntl_async_signals(true);

        $poolName = $input->getArgument('pool');
        $workerIndex = (int) $input->getOption('worker-index');

        // Load pool configuration
        $poolManager = new PoolManager();

        // Check if pool is enabled via environment variable
        $enabledPoolsEnv = $_ENV['WORKER_POOLS'] ?? null;
        if ($enabledPoolsEnv !== null && $enabledPoolsEnv !== '') {
            $enabledPools = array_map('trim', explode(',', $enabledPoolsEnv));
            if (!in_array($poolName, $enabledPools)) {
                $output->writeln("<error>❌ Pool '{$poolName}' is not enabled in this container</error>");
                $output->writeln("<info>Enabled pools: " . implode(', ', $enabledPools) . "</info>");
                $output->writeln("<info>Set WORKER_POOLS environment variable to enable pools (comma-separated)</info>");
                return Command::FAILURE;
            }
        }

        try {
            $poolConfig = $poolManager->getPoolConfig($poolName);
        } catch (\InvalidArgumentException $e) {
            $output->writeln("<error>❌ Invalid pool: {$poolName}</error>");
            $output->writeln("<info>Error: {$e->getMessage()}</info>");
            $enabledPools = $poolManager->getEnabledPools();
            $output->writeln("<info>Enabled pools: " . implode(', ', array_keys($enabledPools)) . "</info>");
            return Command::FAILURE;
        }

        $output->writeln("⚙️  Starting worker for pool: <info>{$poolConfig['name']}</info>");
        $output->writeln("   Worker index: <info>{$workerIndex}</info>");
        $output->writeln("   Capacity: <info>{$poolConfig['capacity']}</info>");
        $output->writeln("   Timeout: <info>{$poolConfig['timeout']}s</info>");

        // Get worker-specific config (e.g., Steam account for download workers)
        $workerConfig = $poolManager->getWorkerConfig($poolName, $workerIndex);

        if (!empty($workerConfig)) {
            $output->writeln("   Config keys: <info>" . implode(', ', array_keys($workerConfig)) . "</info>");

            // Show specific config details (without sensitive data)
            if (isset($workerConfig['steam_account']['username'])) {
                $output->writeln("   Steam account: <info>{$workerConfig['steam_account']['username']}</info>");
            }
        }

        // Create single worker with pool assignment
        $workerId = "{$poolName}-{$workerIndex}";
        $worker = new Worker($workerId, $poolConfig['capacity'], $poolName, $workerConfig);

        // Create Worker objects for ALL workers in this pool (for job distribution)
        // Even though this process only handles ONE worker, JobManager needs to know
        // about all workers in the pool to properly distribute jobs via round-robin
        $poolWorkers = [];
        for ($i = 0; $i < $poolConfig['workers']; $i++) {
            $poolWorkerId = "{$poolName}-{$i}";
            $poolWorkerConfig = (new PoolManager())->getWorkerConfig($poolName, $i);
            $poolWorkers[] = new Worker($poolWorkerId, $poolConfig['capacity'], $poolName, $poolWorkerConfig);
        }

        // Initialize job management with ALL pool workers (not just this one)
        $queue = new JobQueue();
        $scheduled = new ScheduledJobs();
        $recurring = new RecurringJobs();
        $manager = new JobManager($queue, $poolWorkers, $scheduled, $recurring);
        $manager->recoverStaleJobs();

        // Start child process for this worker
        $process = new Process([
            PHP_BINARY,
            dirname(__DIR__, 3) . '/nraa',
            'app:job-runner',
            $workerId,
        ]);
        $process->setTimeout($poolConfig['timeout']);
        $process->setIdleTimeout(null);
        $process->start(function ($type, $buffer) use ($workerId): void {
            if (Process::ERR === $type) {
                echo "[" . date('H:i:s') . "] ERR ({$workerId}) > " . $buffer;
            } else {
                echo "[" . date('H:i:s') . "] OUT ({$workerId}) > " . $buffer;
            }
        });

        $output->writeln("✅ Started <info>{$workerId}</info> (PID <info>{$process->getPid()}</info>)");
        $output->writeln("");
        
        // Log worker startup
        JobLogger::info([
            'worker_id' => $workerId,
            'pool' => $poolName,
            'message' => "Worker started",
            'metadata' => [
                'pid' => $process->getPid(),
                'capacity' => $poolConfig['capacity'],
                'timeout' => $poolConfig['timeout'],
            ]
        ]);

        // Setup signal handlers to cleanup child process when parent is terminated
        $cleanup = function ($signo) use (&$process, $workerId, $output) {
            if ($process && $process->isRunning()) {
                $output->writeln("[" . date('H:i:s') . "] 🛑 Stopping {$workerId} (PID {$process->getPid()}) due to signal {$signo}...");
                $process->stop(3, SIGTERM); // Send SIGTERM, wait 3 seconds
                if ($process->isRunning()) {
                    $process->signal(SIGKILL); // Force kill if still running
                }
            }
            exit(0);
        };

        // Register signal handlers for graceful shutdown using pcntl
        pcntl_signal(SIGTERM, $cleanup);
        pcntl_signal(SIGINT, $cleanup);

        // Also register with ReactPHP loop as backup
        Loop::addSignal(SIGTERM, function () use ($cleanup) {
            $cleanup(SIGTERM);
        });
        Loop::addSignal(SIGINT, function () use ($cleanup) {
            $cleanup(SIGINT);
        });

        // Run every 10 seconds to check for jobs that should be executed
        // Note: Recurring jobs are rate-limited to check only once per minute internally
        Loop::addPeriodicTimer(10, function () use ($manager, $output, $workerId, $workerIndex) {
            $now = new \DateTimeImmutable();

            // Only worker 0 should expand scheduled/recurring jobs to prevent race conditions
            // All workers will distribute and recover jobs
            if ($workerIndex === 0) {
                $output->writeln("[" . date('H:i:s') . "] 🔄 {$workerId} checking for due jobs...");

                // 1. Fetch and queue due jobs (scheduled + recurring)
                // Only one worker does this to prevent duplicate job creation
                // Recurring jobs are rate-limited internally to check only once per minute
                $manager->fetchAndQueueDueJobs($now);
            }

            // 2. Distribute jobs to workers (all workers participate)
            $manager->distributeJobs();

            // 3. Recover stale jobs (all workers check)
            $manager->recoverStaleJobs();
        });

        // Monitor child process and restart if it dies
        Loop::addPeriodicTimer(5, function () use (&$process, $output, $workerId, $poolConfig) {
            if (!$process->isRunning()) {
                $output->writeln("[" . date('H:i:s') . "] ❌ <error>{$workerId} died</error>, restarting...");
                $newProc = new Process([
                    PHP_BINARY,
                    dirname(__DIR__, 3) . '/nraa',
                    'app:job-runner',
                    $workerId,
                ]);
                $newProc->setTimeout($poolConfig['timeout']);
                $newProc->setIdleTimeout(null);
                $newProc->start(function ($type, $buffer) use ($workerId): void {
                    if (Process::ERR === $type) {
                        echo "[" . date('H:i:s') . "] ERR ({$workerId}) > " . $buffer;
                    } else {
                        echo "[" . date('H:i:s') . "] OUT ({$workerId}) > " . $buffer;
                    }
                });
                $process = $newProc;
                $output->writeln("🔄 Restarted <info>{$workerId}</info> (PID <info>{$newProc->getPid()}</info>)");
            }
        });

        Loop::run();
        return Command::SUCCESS;
    }
}
