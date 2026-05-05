<?php

namespace Nraa\Pillars\Console;

use Nraa\Services\FutureDataArchitecture\OperationalRetentionService;
use Nraa\Workers\JobLogger;
use Nraa\Workers\JobQueue;
use Nraa\Workers\JobRealtimeStateService;
use Nraa\Workers\PoolManager;
use React\EventLoop\Loop;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:job-worker',
    description: 'Supervisor for one or more worker runners',
)]
class JobWorkerCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('pool', InputArgument::REQUIRED, 'Pool name (download, parse, calculation, general)')
            ->addOption('worker-index', null, InputOption::VALUE_REQUIRED, 'Worker index within pool (for config assignment)', 0);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        pcntl_async_signals(true);
        $this->bootstrapOperationalRetention($output);

        $poolName = strtolower(trim((string)$input->getArgument('pool')));
        $workerIndex = (int)$input->getOption('worker-index');
        $poolManager = new PoolManager();
        $jobQueue = new JobQueue();
        $realtimeState = JobRealtimeStateService::getInstance();

        $enabledPoolsEnv = $_ENV['WORKER_POOLS'] ?? null;
        if ($enabledPoolsEnv !== null && $enabledPoolsEnv !== '') {
            $enabledPools = array_map('trim', explode(',', $enabledPoolsEnv));
            if (!in_array($poolName, $enabledPools, true)) {
                $output->writeln("<error>❌ Pool '{$poolName}' is not enabled in this container</error>");
                $output->writeln("<info>Enabled pools: " . implode(', ', $enabledPools) . "</info>");
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

        $instanceId = $this->resolveInstanceId();
        $runnerPlans = $this->buildRunnerPlans($poolName, $workerIndex, $poolConfig, $instanceId);
        $totalCapacity = array_sum(array_map(
            static fn(array $plan): int => (int)($plan['capacity'] ?? 1),
            $runnerPlans
        ));

        $output->writeln("⚙️  Starting worker for pool: <info>{$poolConfig['name']}</info>");
        $output->writeln("   Worker index: <info>{$workerIndex}</info>");
        $output->writeln("   Instance: <info>{$instanceId}</info>");
        $output->writeln("   Capacity: <info>{$totalCapacity}</info>");
        $output->writeln("   Runner processes: <info>" . count($runnerPlans) . '</info>');
        $output->writeln("   Timeout: <info>{$poolConfig['timeout']}s</info>");

        $runnerSlots = [];
        foreach ($runnerPlans as $plan) {
            $process = $this->startRunnerProcess(
                $plan['command'],
                (string)$plan['worker_id'],
                (int)$plan['capacity'],
                $poolConfig,
                $output
            );
            $plan['process'] = $process;
            $runnerSlots[(int)$plan['slot_index']] = $plan;

            $metadata = [
                'pid' => $process->getPid(),
                'worker_index' => (int)$plan['worker_index'],
                'capacity' => (int)$plan['capacity'],
                'timeout' => (int)($poolConfig['timeout'] ?? 0),
            ];
            $realtimeState->registerWorker((string)$plan['worker_id'], $poolName, $metadata);

            JobLogger::info([
                'worker_id' => (string)$plan['worker_id'],
                'pool' => $poolName,
                'message' => 'Worker started',
                'metadata' => [
                    'pid' => $process->getPid(),
                    'capacity' => (int)$plan['capacity'],
                    'timeout' => (int)($poolConfig['timeout'] ?? 0),
                    'worker_index' => (int)$plan['worker_index'],
                    'slot_index' => (int)$plan['slot_index'],
                ],
            ]);
        }

        $cleanup = function (int $signo) use (&$runnerSlots, $poolName, $realtimeState, $output): void {
            foreach ($runnerSlots as $slot) {
                $workerId = (string)($slot['worker_id'] ?? '');
                $process = $slot['process'] ?? null;
                $realtimeState->unregisterWorker($workerId, $poolName, [
                    'pid' => $process instanceof Process ? ($process->getPid() ?? 0) : 0,
                ]);

                if ($process instanceof Process && $process->isRunning()) {
                    $output->writeln("[" . date('H:i:s') . "] 🛑 Stopping {$workerId} (PID {$process->getPid()}) due to signal {$signo}...");
                    $process->stop(3, SIGTERM);
                    if ($process->isRunning()) {
                        $process->signal(SIGKILL);
                    }
                }
            }

            exit(0);
        };

        pcntl_signal(SIGTERM, $cleanup);
        pcntl_signal(SIGINT, $cleanup);
        Loop::addSignal(SIGTERM, static function () use ($cleanup): void {
            $cleanup(SIGTERM);
        });
        Loop::addSignal(SIGINT, static function () use ($cleanup): void {
            $cleanup(SIGINT);
        });

        $workerHeartbeatInterval = max(5, (int)($_ENV['JOB_WORKER_STATE_HEARTBEAT_SECONDS'] ?? 10));
        Loop::addPeriodicTimer($workerHeartbeatInterval, function () use (&$runnerSlots, $poolName, $poolConfig, $realtimeState): void {
            foreach ($runnerSlots as $slot) {
                $process = $slot['process'] ?? null;
                $realtimeState->heartbeatWorker((string)$slot['worker_id'], $poolName, [
                    'pid' => $process instanceof Process ? ($process->getPid() ?? 0) : 0,
                    'worker_index' => (int)($slot['worker_index'] ?? 0),
                    'capacity' => (int)($slot['capacity'] ?? 1),
                    'timeout' => (int)($poolConfig['timeout'] ?? 0),
                ]);
            }
        });

        Loop::addPeriodicTimer(5, function () use (&$runnerSlots, $jobQueue, $poolName, $poolConfig, $realtimeState, $output): void {
            foreach ($runnerSlots as $slotIndex => $slot) {
                $process = $slot['process'] ?? null;
                if ($process instanceof Process && $process->isRunning()) {
                    continue;
                }

                $workerId = (string)$slot['worker_id'];
                $crashMessage = $process instanceof Process
                    ? $this->buildRunnerCrashMessage($process)
                    : 'Runner process crashed unexpectedly before supervisor state was fully initialized.';

                $output->writeln("[" . date('H:i:s') . "] ❌ <error>{$workerId} died</error>, reconciling active jobs...");

                try {
                    $reconciled = $jobQueue->reconcileWorkerCrash($workerId, $crashMessage);
                    if ($reconciled > 0) {
                        $output->writeln("   Reconciled <info>{$reconciled}</info> abandoned job(s) for <info>{$workerId}</info>");
                    }
                } catch (\Throwable $e) {
                    $output->writeln("   <error>Crash reconciliation failed for {$workerId}: {$e->getMessage()}</error>");
                }

                $output->writeln("   Restarting <info>{$workerId}</info>...");
                $process = $this->startRunnerProcess(
                    $slot['command'],
                    $workerId,
                    (int)($slot['capacity'] ?? 1),
                    $poolConfig,
                    $output
                );
                $slot['process'] = $process;
                $runnerSlots[$slotIndex] = $slot;

                $realtimeState->registerWorker($workerId, $poolName, [
                    'pid' => $process->getPid(),
                    'worker_index' => (int)($slot['worker_index'] ?? 0),
                    'capacity' => (int)($slot['capacity'] ?? 1),
                    'timeout' => (int)($poolConfig['timeout'] ?? 0),
                ]);
            }
        });

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
    private function buildRunnerCommand(string $workerId, string $poolName, int $workerIndex, int $capacity): array
    {
        $phpMemoryLimit = trim((string)($_ENV['PHP_MEMORY_LIMIT'] ?? getenv('PHP_MEMORY_LIMIT') ?: ''));
        $runnerCommand = [PHP_BINARY];
        if ($phpMemoryLimit !== '') {
            $runnerCommand[] = '-d';
            $runnerCommand[] = 'memory_limit=' . $phpMemoryLimit;
        }

        $runnerCommand[] = dirname(__DIR__, 3) . '/nraa';
        $runnerCommand[] = 'app:job-runner';
        $runnerCommand[] = $workerId;
        $runnerCommand[] = '--pool=' . $poolName;
        $runnerCommand[] = '--worker-index=' . $workerIndex;
        $runnerCommand[] = '--capacity=' . max(1, $capacity);

        return $runnerCommand;
    }

    /**
     * @param array<int, string> $runnerCommand
     */
    private function startRunnerProcess(array $runnerCommand, string $workerId, int $capacity, array $poolConfig, OutputInterface $output): Process
    {
        $process = new Process($runnerCommand);
        $process->setTimeout($poolConfig['timeout']);
        $process->setIdleTimeout(null);
        $process->start(function ($type, $buffer) use ($workerId): void {
            if (Process::ERR === $type) {
                echo "[" . date('H:i:s') . "] ERR ({$workerId}) > " . $buffer;
            } else {
                echo "[" . date('H:i:s') . "] OUT ({$workerId}) > " . $buffer;
            }
        });

        $output->writeln("✅ Started <info>{$workerId}</info> (PID <info>{$process->getPid()}</info>, slot capacity <info>{$capacity}</info>)");
        $output->writeln('');

        return $process;
    }

    /**
     * @return array<int, array{slot_index:int,worker_id:string,worker_index:int,capacity:int,command:array<int,string>}>
     */
    private function buildRunnerPlans(string $poolName, int $workerIndex, array $poolConfig, string $instanceId): array
    {
        $totalCapacity = max(1, (int)($poolConfig['capacity'] ?? 1));
        $runnerProcesses = max(1, (int)($poolConfig['runner_processes'] ?? 1));
        $runnerProcesses = min($runnerProcesses, $totalCapacity);
        $slotCapacities = $this->splitCapacityAcrossProcesses($totalCapacity, $runnerProcesses);
        $slotCount = count($slotCapacities);
        $plans = [];

        foreach ($slotCapacities as $slotIndex => $slotCapacity) {
            $workerId = $this->buildRunnerWorkerId($poolName, $workerIndex, $instanceId, $slotIndex, $slotCount);
            $plans[] = [
                'slot_index' => $slotIndex,
                'worker_id' => $workerId,
                'worker_index' => $workerIndex,
                'capacity' => $slotCapacity,
                'command' => $this->buildRunnerCommand($workerId, $poolName, $workerIndex, $slotCapacity),
            ];
        }

        return $plans;
    }

    /**
     * @return array<int, int>
     */
    private function splitCapacityAcrossProcesses(int $totalCapacity, int $runnerProcesses): array
    {
        $totalCapacity = max(1, $totalCapacity);
        $runnerProcesses = max(1, min($runnerProcesses, $totalCapacity));
        $baseCapacity = intdiv($totalCapacity, $runnerProcesses);
        $remainder = $totalCapacity % $runnerProcesses;
        $slots = [];

        for ($slotIndex = 0; $slotIndex < $runnerProcesses; $slotIndex++) {
            $slots[] = $baseCapacity + ($slotIndex < $remainder ? 1 : 0);
        }

        return array_values(array_filter($slots, static fn(int $capacity): bool => $capacity > 0));
    }

    private function buildRunnerWorkerId(string $poolName, int $workerIndex, string $instanceId, int $slotIndex, int $slotCount): string
    {
        if ($slotCount <= 1) {
            return "{$poolName}-{$workerIndex}@{$instanceId}";
        }

        return "{$poolName}-{$workerIndex}-slot{$slotIndex}@{$instanceId}";
    }

    private function buildRunnerCrashMessage(Process $process): string
    {
        $details = [];
        $exitCode = $process->getExitCode();
        if ($exitCode !== null) {
            $details[] = 'exit code ' . $exitCode;
        }

        if (method_exists($process, 'getTermSignal')) {
            $termSignal = $process->getTermSignal();
            if (is_int($termSignal) && $termSignal > 0) {
                $details[] = 'signal ' . $termSignal;
            }
        }

        $message = 'Runner process crashed unexpectedly';
        if ($details !== []) {
            $message .= ' (' . implode(', ', $details) . ')';
        }

        $tail = $this->extractCrashOutputTail($process);
        if ($tail !== null) {
            $message .= ': ' . $tail;
        }

        return $message;
    }

    private function extractCrashOutputTail(Process $process): ?string
    {
        $output = trim((string)$process->getErrorOutput());
        if ($output === '') {
            $output = trim((string)$process->getOutput());
        }

        if ($output === '') {
            return null;
        }

        $lines = preg_split('/\R+/', $output) ?: [];
        for ($index = count($lines) - 1; $index >= 0; $index--) {
            $line = trim((string)($lines[$index] ?? ''));
            if ($line === '') {
                continue;
            }

            $line = preg_replace('/\s+/', ' ', $line) ?? $line;
            if (strlen($line) > 300) {
                $line = substr($line, -300);
            }

            return $line;
        }

        return null;
    }

    private function resolveInstanceId(): string
    {
        $raw = trim((string)(
            $_ENV['JOB_WORKER_INSTANCE_ID']
            ?? getenv('JOB_WORKER_INSTANCE_ID')
            ?: ($_ENV['HOSTNAME'] ?? getenv('HOSTNAME') ?: gethostname() ?: 'local')
        ));

        $normalized = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $raw) ?? 'local';
        $normalized = trim($normalized, '-_.');

        if ($normalized === '') {
            $normalized = 'local';
        }

        return substr($normalized, 0, 48);
    }
}
