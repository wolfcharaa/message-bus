<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaComponent;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaValidator;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaVersion;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaVersionTableDefinition;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueSchemaGenerator;
use Wolfcharaa\MessageBus\Queue\QueueJobState;
use Wolfcharaa\MessageBus\Queue\QueueTableDefinition;
use Wolfcharaa\MessageBus\Worker\Postgres\PostgresWorkerControlSchemaGenerator;
use Wolfcharaa\MessageBus\Worker\WorkerControlTableDefinition;

final class PostgresSchemaFoundationTest extends TestCase
{
    public function testQueueSchemaContainsInterruptedStatusAndSchemaVersion(): void
    {
        $sql = (new PostgresQueueSchemaGenerator())->generate();

        self::assertSame('interrupted', QueueJobState::Interrupted->value);
        self::assertStringContainsString("status IN ('pending', 'running', 'succeeded', 'failed', 'cancelled', 'interrupted')", $sql);
        self::assertStringContainsString('message_bus__queue_jobs_interrupted_idx', $sql);
        self::assertStringContainsString('message_bus__schema_versions', $sql);
        self::assertStringContainsString("VALUES ('queue', '5.1'", $sql);
    }

    public function testWorkerControlSchemaContainsV51ControlPlaneTables(): void
    {
        $sql = (new PostgresWorkerControlSchemaGenerator())->generate();

        self::assertStringContainsString('message_bus__worker_control_commands', $sql);
        self::assertStringContainsString('message_bus__worker_control_command_deliveries', $sql);
        self::assertStringContainsString('message_bus__worker_control_command_acknowledgements', $sql);
        self::assertStringContainsString('message_bus__worker_control_command_audit', $sql);
        self::assertStringContainsString('message_bus__worker_desired_state', $sql);
        self::assertStringContainsString('concurrency_override', $sql);
        self::assertStringContainsString('runtime_overrides', $sql);
        self::assertStringContainsString("VALUES ('worker_control', '5.1'", $sql);
    }

    public function testSchemaValidatorAcceptsCompleteConfiguredSchema(): void
    {
        $queue = new QueueTableDefinition('queue_jobs');
        $worker = new WorkerControlTableDefinition(
            commandsTable: 'worker_commands',
            desiredStatesTable: 'worker_desired_states',
            workerInstancesTable: 'worker_instances',
            childInstancesTable: 'worker_children',
            acknowledgementsTable: 'worker_acks',
            commandDeliveriesTable: 'worker_deliveries',
            commandAuditTable: 'worker_audit',
            schemaVersionsTable: 'schema_versions',
        );
        $pdo = PostgresSchemaValidatorFakePdo::complete($queue, $worker);

        $result = (new PostgresSchemaValidator(
            $pdo,
            new PostgresSchemaVersionTableDefinition('schema_versions'),
            $queue,
            $worker,
        ))->validate();

        self::assertTrue($result->isValid());
        self::assertSame([
            'queue' => PostgresSchemaVersion::QUEUE,
            'worker_control' => PostgresSchemaVersion::WORKER_CONTROL,
        ], $result->requiredVersions);
        self::assertSame($result->requiredVersions, $result->currentVersions);
    }

    public function testSchemaValidatorReportsMissingObjectsAndVersionMismatch(): void
    {
        $queue = new QueueTableDefinition('queue_jobs');
        $pdo = new PostgresSchemaValidatorFakePdo(
            relations: ['schema_versions', 'queue_jobs'],
            columns: ['queue_jobs' => ['id', 'transport']],
            versions: ['queue' => '5.0'],
        );

        $result = (new PostgresSchemaValidator(
            $pdo,
            new PostgresSchemaVersionTableDefinition('schema_versions'),
            $queue,
            new WorkerControlTableDefinition(schemaVersionsTable: 'schema_versions'),
        ))->validate([PostgresSchemaComponent::Queue]);

        self::assertFalse($result->isValid());
        self::assertTrue($result->hasIssueCode('schema.version_mismatch'));
        self::assertTrue($result->hasIssueCode('schema.missing_column'));
        self::assertTrue($result->hasIssueCode('schema.missing_index'));
        self::assertSame(['queue' => '5.0'], $result->currentVersions);
    }
}

final class PostgresSchemaValidatorFakePdo extends PDO
{
    /**
     * @param list<string> $relations
     * @param array<string, list<string>> $columns
     * @param array<string, string> $versions
     */
    public function __construct(
        private readonly array $relations,
        private readonly array $columns,
        private readonly array $versions,
    ) {
    }

    public static function complete(QueueTableDefinition $queue, WorkerControlTableDefinition $worker): self
    {
        return new self(
            relations: [
                'schema_versions',
                $queue->tableName,
                $queue->tableName . '_pending_idx',
                $queue->tableName . '_interrupted_idx',
                $worker->commandsTable,
                $worker->desiredStatesTable,
                $worker->workerInstancesTable,
                $worker->childInstancesTable,
                $worker->commandDeliveriesTable,
                $worker->acknowledgementsTable,
                $worker->commandAuditTable,
                $worker->commandDeliveriesTable . '_command_idx',
                $worker->acknowledgementsTable . '_actor_stage_uidx',
                $worker->commandAuditTable . '_command_idx',
            ],
            columns: [
                $queue->tableName => [
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
                ],
                $worker->commandsTable => ['command_id', 'type', 'target_type', 'source', 'reason', 'idempotency_key'],
                $worker->desiredStatesTable => ['desired_state_id', 'state', 'scope_type', 'concurrency_override', 'runtime_overrides', 'updated_at'],
                $worker->workerInstancesTable => ['worker_instance_id', 'actor_id', 'actor_type', 'state', 'activity', 'heartbeat_at'],
                $worker->childInstancesTable => ['child_instance_id', 'actor_id', 'actor_type', 'parent_actor_id', 'state', 'heartbeat_at'],
                $worker->commandDeliveriesTable => ['delivery_id', 'command_id', 'target_type', 'target_actor_id', 'status', 'updated_at'],
                $worker->acknowledgementsTable => ['command_id', 'worker_instance_id', 'actor_id', 'stage', 'state', 'acknowledged_at'],
                $worker->commandAuditTable => ['command_id', 'actor_id', 'event', 'level', 'sanitized_context', 'created_at'],
            ],
            versions: [
                'queue' => PostgresSchemaVersion::QUEUE,
                'worker_control' => PostgresSchemaVersion::WORKER_CONTROL,
            ],
        );
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new PostgresSchemaValidatorFakeStatement($this, $query);
    }

    public function hasRelation(string $name): bool
    {
        return \in_array($name, $this->relations, true);
    }

    public function hasColumn(string $table, string $column): bool
    {
        return \in_array($column, $this->columns[$table] ?? [], true);
    }

    public function version(string $component): string|false
    {
        return $this->versions[$component] ?? false;
    }
}

final class PostgresSchemaValidatorFakeStatement extends PDOStatement
{
    /** @var array<string, mixed> */
    private array $params = [];

    public function __construct(
        private readonly PostgresSchemaValidatorFakePdo $pdo,
        private readonly string $query,
    ) {
    }

    public function execute(?array $params = null): bool
    {
        $this->params = $params ?? [];

        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        if (\str_contains($this->query, 'SELECT version FROM')) {
            return $this->pdo->version((string) $this->params[':component']);
        }

        if (\str_contains($this->query, 'SELECT to_regclass')) {
            return $this->pdo->hasRelation((string) $this->params[':name']);
        }

        if (\str_contains($this->query, 'FROM pg_attribute')) {
            return $this->pdo->hasColumn((string) $this->params[':table'], (string) $this->params[':column']);
        }

        return false;
    }
}
