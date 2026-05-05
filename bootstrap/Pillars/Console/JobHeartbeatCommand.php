<?php

namespace Nraa\Pillars\Console;

use Nraa\Workers\JobHeartbeat;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:job-heartbeat',
    description: 'Run an out-of-band heartbeat loop for a single in-progress job',
)]
class JobHeartbeatCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('jobId', InputArgument::REQUIRED, 'Job identifier')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Heartbeat interval in seconds', (string)JobHeartbeat::getInterval())
            ->addOption('parent-pid', null, InputOption::VALUE_REQUIRED, 'Expected parent process id', '0')
            ->addOption('max-misses', null, InputOption::VALUE_REQUIRED, 'Exit after this many consecutive missed updates', '2');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (\function_exists('pcntl_async_signals')) {
            \pcntl_async_signals(true);
        }

        $jobId = trim((string)$input->getArgument('jobId'));
        $intervalSeconds = max(1, (int)$input->getOption('interval'));
        $parentPid = max(0, (int)$input->getOption('parent-pid'));
        $maxMisses = max(1, (int)$input->getOption('max-misses'));
        $running = true;

        $stop = static function () use (&$running): void {
            $running = false;
        };

        if (\function_exists('pcntl_signal')) {
            \pcntl_signal(\SIGTERM, $stop);
            \pcntl_signal(\SIGINT, $stop);
        }

        $misses = 0;
        while ($running) {
            if ($parentPid > 0 && !$this->isProcessAlive($parentPid)) {
                break;
            }

            if (JobHeartbeat::update($jobId)) {
                $misses = 0;
            } else {
                $misses++;
                if ($misses >= $maxMisses) {
                    break;
                }
            }

            for ($elapsed = 0; $elapsed < $intervalSeconds && $running; $elapsed++) {
                sleep(1);
                if ($parentPid > 0 && !$this->isProcessAlive($parentPid)) {
                    $running = false;
                    break;
                }
            }
        }

        return Command::SUCCESS;
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return true;
        }

        if (\function_exists('posix_kill')) {
            $alive = @\posix_kill($pid, 0);
            if ($alive) {
                return true;
            }

            if (\function_exists('posix_get_last_error') && \posix_get_last_error() === 1) {
                return true;
            }
        }

        return is_dir('/proc/' . $pid);
    }
}
