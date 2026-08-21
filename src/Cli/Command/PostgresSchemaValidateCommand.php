<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli\Command;

use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Cli\ExitCode;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaComponent;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaValidator;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaVersionTableDefinition;
use Wolfcharaa\MessageBus\Queue\QueueTableDefinition;
use Wolfcharaa\MessageBus\Worker\WorkerControlTableDefinition;

final class PostgresSchemaValidateCommand extends Command
{
    public function __construct()
    {
        parent::__construct('message-bus:postgres:schema:validate');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Validate PostgreSQL schema compatibility for message-bus tables.')
            ->addOption('dsn', null, InputOption::VALUE_REQUIRED, 'PostgreSQL PDO DSN. Falls back to MESSAGE_BUS_POSTGRES_DSN.')
            ->addOption('user', null, InputOption::VALUE_REQUIRED, 'PostgreSQL user. Falls back to MESSAGE_BUS_POSTGRES_USER.')
            ->addOption('password', null, InputOption::VALUE_REQUIRED, 'PostgreSQL password. Falls back to MESSAGE_BUS_POSTGRES_PASSWORD.')
            ->addOption('with', null, InputOption::VALUE_REQUIRED, 'Components to validate: queue, worker-control or all.', 'all')
            ->addOption('schema-version-table', null, InputOption::VALUE_REQUIRED, default: 'message_bus__schema_versions')
            ->addOption('queue-table', null, InputOption::VALUE_REQUIRED, default: 'message_bus__queue_jobs')
            ->addOption('worker-commands-table', null, InputOption::VALUE_REQUIRED, default: 'message_bus__worker_control_commands')
            ->addOption('worker-desired-state-table', null, InputOption::VALUE_REQUIRED, default: 'message_bus__worker_desired_state')
            ->addOption('worker-instances-table', null, InputOption::VALUE_REQUIRED, default: 'message_bus__worker_instances')
            ->addOption('worker-child-instances-table', null, InputOption::VALUE_REQUIRED, default: 'message_bus__worker_child_instances')
            ->addOption('worker-deliveries-table', null, InputOption::VALUE_REQUIRED, default: 'message_bus__worker_control_command_deliveries')
            ->addOption('worker-acknowledgements-table', null, InputOption::VALUE_REQUIRED, default: 'message_bus__worker_control_command_acknowledgements')
            ->addOption('worker-audit-table', null, InputOption::VALUE_REQUIRED, default: 'message_bus__worker_control_command_audit');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = (new PostgresSchemaValidator(
            $this->pdo($input),
            new PostgresSchemaVersionTableDefinition((string) $input->getOption('schema-version-table')),
            new QueueTableDefinition((string) $input->getOption('queue-table')),
            new WorkerControlTableDefinition(
                commandsTable: (string) $input->getOption('worker-commands-table'),
                desiredStatesTable: (string) $input->getOption('worker-desired-state-table'),
                workerInstancesTable: (string) $input->getOption('worker-instances-table'),
                childInstancesTable: (string) $input->getOption('worker-child-instances-table'),
                acknowledgementsTable: (string) $input->getOption('worker-acknowledgements-table'),
                commandDeliveriesTable: (string) $input->getOption('worker-deliveries-table'),
                commandAuditTable: (string) $input->getOption('worker-audit-table'),
                schemaVersionsTable: (string) $input->getOption('schema-version-table'),
            ),
        ))->validate($this->components((string) $input->getOption('with')));

        foreach ($result->requiredVersions as $component => $required) {
            $current = $result->currentVersions[$component] ?? 'missing';
            $output->writeln(\sprintf('component=%s required=%s current=%s', $component, $required, $current ?? 'missing'));
        }

        if ($result->isValid()) {
            $output->writeln('PostgreSQL schema is compatible.');

            return ExitCode::Success->value;
        }

        foreach ($result->issues as $issue) {
            $output->writeln(\sprintf('<error>[%s]</error> %s', $issue->code, $issue->message));
        }

        return $result->hasIssueCode('schema.version_mismatch') || $result->hasIssueCode('schema.version_missing')
            ? ExitCode::SchemaMismatch->value
            : ExitCode::SchemaValidationFailed->value;
    }

    private function pdo(InputInterface $input): PDO
    {
        $dsn = $input->getOption('dsn') ?: \getenv('MESSAGE_BUS_POSTGRES_DSN');
        if ($dsn === false || $dsn === null || $dsn === '') {
            throw new \RuntimeException('Pass --dsn or set MESSAGE_BUS_POSTGRES_DSN.');
        }

        $user = $input->getOption('user') ?? (\getenv('MESSAGE_BUS_POSTGRES_USER') !== false ? \getenv('MESSAGE_BUS_POSTGRES_USER') : null);
        $password = $input->getOption('password') ?? (\getenv('MESSAGE_BUS_POSTGRES_PASSWORD') !== false ? \getenv('MESSAGE_BUS_POSTGRES_PASSWORD') : null);

        return new PDO((string) $dsn, $user !== false ? $user : null, $password !== false ? $password : null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** @return list<PostgresSchemaComponent> */
    private function components(string $with): array
    {
        $parts = \array_map('trim', \explode(',', $with));
        if (\in_array('all', $parts, true)) {
            return [PostgresSchemaComponent::Queue, PostgresSchemaComponent::WorkerControl];
        }

        $components = [];
        foreach ($parts as $part) {
            $components[] = match ($part) {
                'queue' => PostgresSchemaComponent::Queue,
                'worker-control' => PostgresSchemaComponent::WorkerControl,
                default => throw new \InvalidArgumentException('message-bus:postgres:schema:validate --with supports queue, worker-control or all.'),
            };
        }

        return $components;
    }
}
