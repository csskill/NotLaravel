<?php

namespace Nraa\Workers;

use Nraa\Pillars\Log;
use Symfony\Component\Process\Process;

final class JobHeartbeatProcessManager
{
    private ?Process $process = null;

    public function __construct(
        private readonly string $jobId,
        private readonly int $intervalSeconds,
        private readonly int $parentPid
    ) {
    }

    public function start(): void
    {
        if ($this->jobId === '' || ($this->process instanceof Process && $this->process->isRunning())) {
            return;
        }

        try {
            $consolePath = dirname(__DIR__, 2) . '/nraa';
            $process = new Process([
                PHP_BINARY,
                $consolePath,
                'app:job-heartbeat',
                $this->jobId,
                '--interval=' . max(1, $this->intervalSeconds),
                '--parent-pid=' . max(0, $this->parentPid),
                '--max-misses=2',
            ], dirname($consolePath));
            $process->setTimeout(null);
            $process->setIdleTimeout(null);
            $process->disableOutput();
            $process->start();
            $this->process = $process;
        } catch (\Throwable $e) {
            Log::warning('JobHeartbeatProcessManager: failed to start heartbeat process', [
                'job_id' => $this->jobId,
                'parent_pid' => $this->parentPid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function stop(): void
    {
        if (!$this->process instanceof Process) {
            return;
        }

        try {
            if ($this->process->isRunning()) {
                $this->process->stop(1, \SIGTERM);
                if ($this->process->isRunning()) {
                    $this->process->signal(\SIGKILL);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('JobHeartbeatProcessManager: failed stopping heartbeat process', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            $this->process = null;
        }
    }

    public function isRunning(): bool
    {
        return $this->process instanceof Process && $this->process->isRunning();
    }
}
