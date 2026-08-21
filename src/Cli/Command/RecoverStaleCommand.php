<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Cli\BootstrapResolver;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueStorageInterface;

#[AsCommand(name: 'queue:recover-stale')]
final class RecoverStaleCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('bootstrap', null, InputOption::VALUE_REQUIRED)
            ->addOption('transport', null, InputOption::VALUE_REQUIRED, default: 'postgres')
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, default: 'default')
            ->addOption('lock-ttl', null, InputOption::VALUE_REQUIRED, default: 300);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $runtime = (new BootstrapResolver())->resolve($input->getOption('bootstrap'));
        $status = $runtime instanceof \Wolfcharaa\MessageBus\Runtime\MessageBusRuntime ? $runtime->queueStatus() : null;

        if (!$status instanceof PostgresQueueStorageInterface) {
            throw new \RuntimeException('queue:recover-stale requires MessageBusRuntime with PostgresQueueStorageInterface.');
        }

        $count = $status->recoverStale(new ConsumerOptions(
            $input->getOption('transport'),
            $input->getOption('queue'),
            lockTtlSeconds: (int) $input->getOption('lock-ttl'),
        ));

        $output->writeln('recovered=' . $count);

        return Command::SUCCESS;
    }
}
