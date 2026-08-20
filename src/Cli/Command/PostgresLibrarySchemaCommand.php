<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueSchemaGenerator;
use Wolfcharaa\MessageBus\Queue\QueueTableDefinition;
use Wolfcharaa\MessageBus\Worker\Postgres\PostgresWorkerControlSchemaGenerator;

final class PostgresLibrarySchemaCommand extends Command
{
    public function __construct()
    {
        parent::__construct('schema:postgres');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate PostgreSQL schema for message-bus tables.')
            ->addOption('with', null, InputOption::VALUE_REQUIRED, default: 'all')
            ->addOption('queue-table', null, InputOption::VALUE_REQUIRED, default: 'message_bus__queue_jobs')
            ->addOption('output', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $parts = \array_map('trim', \explode(',', (string) $input->getOption('with')));
        if (\in_array('all', $parts, true)) {
            $parts = ['queue', 'worker-control'];
        }

        $sql = [];
        foreach ($parts as $part) {
            $sql[] = match ($part) {
                'queue' => (new PostgresQueueSchemaGenerator())->generate(new QueueTableDefinition($input->getOption('queue-table'))),
                'worker-control' => (new PostgresWorkerControlSchemaGenerator())->generate(),
                default => throw new \InvalidArgumentException('schema:postgres --with supports queue, worker-control or all.'),
            };
        }

        $result = \implode("\n\n", $sql);

        if ($input->getOption('output') !== null) {
            \file_put_contents($input->getOption('output'), $result . PHP_EOL);
        } else {
            $output->writeln($result);
        }

        return self::SUCCESS;
    }
}
