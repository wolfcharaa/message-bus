<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueSchemaGenerator;
use Wolfcharaa\MessageBus\Queue\QueueTableDefinition;

#[AsCommand(name: 'queue:schema:postgres')]
final class PostgresSchemaCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('table', null, InputOption::VALUE_REQUIRED, default: 'message_bus__queue_jobs')
            ->addOption('output', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sql = (new PostgresQueueSchemaGenerator())->generate(new QueueTableDefinition($input->getOption('table')));

        if ($input->getOption('output') !== null) {
            \file_put_contents($input->getOption('output'), $sql . PHP_EOL);
        } else {
            $output->writeln($sql);
        }

        return Command::SUCCESS;
    }
}
