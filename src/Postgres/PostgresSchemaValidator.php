<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

use PDO;
use Wolfcharaa\MessageBus\Queue\QueueTableDefinition;
use Wolfcharaa\MessageBus\Worker\WorkerControlTableDefinition;

final class PostgresSchemaValidator implements PostgresSchemaValidatorInterface
{
    private readonly PostgresSchemaVersionTableDefinition $schemaVersions;
    private readonly QueueTableDefinition $queue;
    private readonly WorkerControlTableDefinition $workerControl;

    public function __construct(
        private readonly PDO $pdo,
        ?PostgresSchemaVersionTableDefinition $schemaVersions = null,
        ?QueueTableDefinition $queue = null,
        ?WorkerControlTableDefinition $workerControl = null,
    ) {
        $this->schemaVersions = $schemaVersions ?? new PostgresSchemaVersionTableDefinition();
        $this->queue = $queue ?? new QueueTableDefinition();
        $this->workerControl = $workerControl ?? new WorkerControlTableDefinition();
    }

    /**
     * @param list<PostgresSchemaComponent> $components
     */
    public function validate(?array $components = null): PostgresSchemaValidationResult
    {
        $components ??= [PostgresSchemaComponent::Queue, PostgresSchemaComponent::WorkerControl];
        $issues = [];
        $requiredVersions = [];
        $currentVersions = [];

        if (!$this->relationExists($this->schemaVersions->tableName)) {
            $issues[] = new PostgresSchemaValidationIssue(
                'schema_versions.missing_table',
                \sprintf('Schema version table `%s` was not found.', $this->schemaVersions->tableName),
            );
        }

        foreach ($components as $component) {
            $required = $this->requiredVersion($component);
            $requiredVersions[$component->value] = $required;
            $current = $this->relationExists($this->schemaVersions->tableName)
                ? $this->currentVersion($component)
                : null;
            $currentVersions[$component->value] = $current;

            if ($current === null) {
                $issues[] = new PostgresSchemaValidationIssue(
                    'schema.version_missing',
                    \sprintf('Schema version for component `%s` is missing. Required version: %s.', $component->value, $required),
                    $component,
                );
            } elseif (\version_compare($current, $required, '<')) {
                $issues[] = new PostgresSchemaValidationIssue(
                    'schema.version_mismatch',
                    \sprintf('Schema component `%s` version `%s` is lower than required `%s`.', $component->value, $current, $required),
                    $component,
                );
            }

            match ($component) {
                PostgresSchemaComponent::Queue => $this->validateQueue($issues),
                PostgresSchemaComponent::WorkerControl => $this->validateWorkerControl($issues),
            };
        }

        return new PostgresSchemaValidationResult($requiredVersions, $currentVersions, $issues);
    }

    /** @param list<PostgresSchemaValidationIssue> $issues */
    private function validateQueue(array &$issues): void
    {
        $this->requireTable($issues, PostgresSchemaComponent::Queue, $this->queue->tableName);
        foreach ([
            'id',
            'transport',
            'queue',
            'status',
            'message_id',
            'correlation_id',
            'binding_id',
            'heartbeat_at',
            'last_error_details',
            'serialized_envelope',
            'updated_at',
        ] as $column) {
            $this->requireColumn($issues, PostgresSchemaComponent::Queue, $this->queue->tableName, $column);
        }

        $this->requireIndex($issues, PostgresSchemaComponent::Queue, $this->queue->tableName . '_pending_idx');
        $this->requireIndex($issues, PostgresSchemaComponent::Queue, $this->queue->tableName . '_interrupted_idx');
    }

    /** @param list<PostgresSchemaValidationIssue> $issues */
    private function validateWorkerControl(array &$issues): void
    {
        $tables = [
            $this->workerControl->commandsTable => ['command_id', 'type', 'target_type', 'source', 'reason', 'idempotency_key'],
            $this->workerControl->desiredStatesTable => ['desired_state_id', 'state', 'scope_type', 'concurrency_override', 'runtime_overrides', 'updated_at'],
            $this->workerControl->workerInstancesTable => ['worker_instance_id', 'actor_id', 'actor_type', 'state', 'activity', 'heartbeat_at'],
            $this->workerControl->childInstancesTable => ['child_instance_id', 'actor_id', 'actor_type', 'parent_actor_id', 'state', 'heartbeat_at'],
            $this->workerControl->commandDeliveriesTable => ['delivery_id', 'command_id', 'target_type', 'target_actor_id', 'status', 'updated_at'],
            $this->workerControl->acknowledgementsTable => ['command_id', 'worker_instance_id', 'actor_id', 'stage', 'state', 'acknowledged_at'],
            $this->workerControl->commandAuditTable => ['command_id', 'actor_id', 'event', 'level', 'sanitized_context', 'created_at'],
        ];

        foreach ($tables as $table => $columns) {
            $this->requireTable($issues, PostgresSchemaComponent::WorkerControl, $table);
            foreach ($columns as $column) {
                $this->requireColumn($issues, PostgresSchemaComponent::WorkerControl, $table, $column);
            }
        }

        $this->requireIndex($issues, PostgresSchemaComponent::WorkerControl, $this->workerControl->commandDeliveriesTable . '_command_idx');
        $this->requireIndex($issues, PostgresSchemaComponent::WorkerControl, $this->workerControl->acknowledgementsTable . '_actor_stage_uidx');
        $this->requireIndex($issues, PostgresSchemaComponent::WorkerControl, $this->workerControl->commandAuditTable . '_command_idx');
    }

    /** @param list<PostgresSchemaValidationIssue> $issues */
    private function requireTable(array &$issues, PostgresSchemaComponent $component, string $table): void
    {
        if ($this->relationExists($table)) {
            return;
        }

        $issues[] = new PostgresSchemaValidationIssue(
            'schema.missing_table',
            \sprintf('Required table `%s` was not found.', $table),
            $component,
        );
    }

    /** @param list<PostgresSchemaValidationIssue> $issues */
    private function requireColumn(array &$issues, PostgresSchemaComponent $component, string $table, string $column): void
    {
        if ($this->columnExists($table, $column)) {
            return;
        }

        $issues[] = new PostgresSchemaValidationIssue(
            'schema.missing_column',
            \sprintf('Required column `%s.%s` was not found.', $table, $column),
            $component,
        );
    }

    /** @param list<PostgresSchemaValidationIssue> $issues */
    private function requireIndex(array &$issues, PostgresSchemaComponent $component, string $index): void
    {
        if ($this->relationExists($index)) {
            return;
        }

        $issues[] = new PostgresSchemaValidationIssue(
            'schema.missing_index',
            \sprintf('Required index `%s` was not found.', $index),
            $component,
        );
    }

    private function requiredVersion(PostgresSchemaComponent $component): string
    {
        return match ($component) {
            PostgresSchemaComponent::Queue => PostgresSchemaVersion::QUEUE,
            PostgresSchemaComponent::WorkerControl => PostgresSchemaVersion::WORKER_CONTROL,
        };
    }

    private function currentVersion(PostgresSchemaComponent $component): ?string
    {
        $statement = $this->pdo->prepare('SELECT version FROM ' . $this->quoteIdentifier($this->schemaVersions->tableName) . ' WHERE component = :component');
        $statement->execute([':component' => $component->value]);
        $version = $statement->fetchColumn();

        return $version === false ? null : (string) $version;
    }

    private function relationExists(string $name): bool
    {
        $statement = $this->pdo->prepare('SELECT to_regclass(:name) IS NOT NULL');
        $statement->execute([':name' => $name]);

        return (bool) $statement->fetchColumn();
    }

    private function columnExists(string $table, string $column): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT EXISTS (
                SELECT 1
                FROM pg_attribute
                WHERE attrelid = to_regclass(:table)
                  AND attname = :column
                  AND NOT attisdropped
            )'
        );
        $statement->execute([':table' => $table, ':column' => $column]);

        return (bool) $statement->fetchColumn();
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }
}
