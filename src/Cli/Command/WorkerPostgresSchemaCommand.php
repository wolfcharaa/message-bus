<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Worker\Postgres\PostgresWorkerControlSchemaGenerator;

final class WorkerPostgresSchemaCommand extends Command
{
    public function __construct()
    {
        parent::__construct('worker:schema:postgres');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Generate PostgreSQL schema for worker control tables.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $sql = (new PostgresWorkerControlSchemaGenerator())->generate();

        if ($input->getOption('output') !== null) {
            \file_put_contents($input->getOption('output'), $sql . PHP_EOL);
        } else {
            $output->writeln($sql);
        }

        return self::SUCCESS;
    }
}
