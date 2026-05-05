<?php

namespace Nraa\Pillars\Console;

use Nraa\Workers\JobRealtimeStateService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:job-realtime-snapshot',
    description: 'Print the Redis-backed realtime queue snapshot',
)]
class JobRealtimeSnapshotCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $snapshot = JobRealtimeStateService::getInstance()->getSnapshot();
        $output->writeln(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return Command::SUCCESS;
    }
}
