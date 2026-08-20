<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker\Postgres;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Wolfcharaa\MessageBus\Worker\WorkerActivityState;
use Wolfcharaa\MessageBus\Worker\WorkerChildInstance;
use Wolfcharaa\MessageBus\Worker\WorkerChildState;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgement;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgementState;
use Wolfcharaa\MessageBus\Worker\WorkerControlBatch;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommand;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandReceipt;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;
use Wolfcharaa\MessageBus\Worker\WorkerControlCursor;
use Wolfcharaa\MessageBus\Worker\WorkerControlInboxInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlTableDefinition;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredState;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredStateRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredStateType;
use Wolfcharaa\MessageBus\Worker\WorkerIdentity;
use Wolfcharaa\MessageBus\Worker\WorkerInstance;
use Wolfcharaa\MessageBus\Worker\WorkerLifecycleState;
use Wolfcharaa\MessageBus\Worker\WorkerMode;
use Wolfcharaa\MessageBus\Worker\WorkerRegistryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerStatusRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

final class PostgresWorkerControlStorage implements
    WorkerControlCommandRepositoryInterface,
    WorkerDesiredStateRepositoryInterface,
    WorkerControlInboxInterface,
    WorkerRegistryInterface,
    WorkerStatusRepositoryInterface
{
    private readonly string $commandsTable;
    private readonly string $desiredStatesTable;
    private readonly string $workerInstancesTable;
    private readonly string $childInstancesTable;
    private readonly string $acknowledgementsTable;

    public function __construct(
        private readonly PDO $pdo,
        WorkerControlTableDefinition $definition = new WorkerControlTableDefinition(),
    ) {
        if (!\in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PostgreSQL worker control storage requires PDO pgsql driver.');
        }

        $this->commandsTable = $this->quoteIdentifier($definition->commandsTable);
        $this->desiredStatesTable = $this->quoteIdentifier($definition->desiredStatesTable);
        $this->workerInstancesTable = $this->quoteIdentifier($definition->workerInstancesTable);
        $this->childInstancesTable = $this->quoteIdentifier($definition->childInstancesTable);
        $this->acknowledgementsTable = $this->quoteIdentifier($definition->acknowledgementsTable);
    }

    public function append(WorkerControlCommand $command): WorkerControlCommandReceipt
    {
        if ($command->idempotencyKey !== null) {
            $existing = $this->findByIdempotencyKey($command->idempotencyKey);
            if ($existing !== null) {
                return new WorkerControlCommandReceipt(
                    $existing->commandId,
                    $existing->type,
                    $existing->target,
                    $existing->createdAt,
                    $existing->idempotencyKey,
                    duplicate: true,
                );
            }
        }

        $existing = $this->findById($command->commandId);
        if ($existing !== null) {
            return new WorkerControlCommandReceipt(
                $existing->commandId,
                $existing->type,
                $existing->target,
                $existing->createdAt,
                $existing->idempotencyKey,
                duplicate: true,
            );
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->commandsTable . ' (
                command_id, type,
                target_worker_id, target_worker_name, target_worker_instance_id, target_worker_group,
                target_transport, target_queue, target_flows, target_binding_ids, target_binding_patterns,
                target_mode, target_host, target_all, target_specificity,
                created_at, created_by, source, reason, request_id, correlation_id, expires_at, idempotency_key, override
            ) VALUES (
                :command_id, :type,
                :target_worker_id, :target_worker_name, :target_worker_instance_id, :target_worker_group,
                :target_transport, :target_queue, :target_flows, :target_binding_ids, :target_binding_patterns,
                :target_mode, :target_host, :target_all, :target_specificity,
                :created_at, :created_by, :source, :reason, :request_id, :correlation_id, :expires_at, :idempotency_key, :override
            )'
        );
        $statement->execute($this->commandParams($command));

        return new WorkerControlCommandReceipt(
            $command->commandId,
            $command->type,
            $command->target,
            $command->createdAt,
            $command->idempotencyKey,
        );
    }

    public function findById(string $commandId): ?WorkerControlCommand
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . $this->commandsTable . ' WHERE command_id = :command_id');
        $statement->execute([':command_id' => $commandId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->command($row);
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?WorkerControlCommand
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . $this->commandsTable . ' WHERE idempotency_key = :idempotency_key');
        $statement->execute([':idempotency_key' => $idempotencyKey]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->command($row);
    }

    public function pendingFor(WorkerIdentity $identity, WorkerControlCursor $cursor): array
    {
        $rows = $this->commandRowsAfter($cursor, $this->now());
        $commands = [];
        foreach ($rows as $row) {
            $command = $this->command($row);
            if ($command->target->matches($identity)) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    public function acknowledge(WorkerControlAcknowledgement $acknowledgement): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->acknowledgementsTable . ' (
                command_id, worker_instance_id, state, acknowledged_at, message, error_class, error_message
            ) VALUES (
                :command_id, :worker_instance_id, :state, :acknowledged_at, :message, :error_class, :error_message
            )
            ON CONFLICT (command_id, worker_instance_id) DO UPDATE SET
                state = EXCLUDED.state,
                acknowledged_at = EXCLUDED.acknowledged_at,
                message = EXCLUDED.message,
                error_class = EXCLUDED.error_class,
                error_message = EXCLUDED.error_message'
        );
        $statement->execute([
            ':command_id' => $acknowledgement->commandId,
            ':worker_instance_id' => $acknowledgement->workerInstanceId,
            ':state' => $acknowledgement->state->value,
            ':acknowledged_at' => $this->formatDate($acknowledgement->acknowledgedAt),
            ':message' => $acknowledgement->message,
            ':error_class' => $acknowledgement->errorClass,
            ':error_message' => $acknowledgement->errorMessage,
        ]);
    }

    public function apply(WorkerDesiredState $state): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->desiredStatesTable . ' (
                desired_state_id, state,
                target_worker_id, target_worker_name, target_worker_instance_id, target_worker_group,
                target_transport, target_queue, target_flows, target_binding_ids, target_binding_patterns,
                target_mode, target_host, target_all, target_specificity,
                created_at, created_by, source, reason, request_id, correlation_id, override, active
            ) VALUES (
                :desired_state_id, :state,
                :target_worker_id, :target_worker_name, :target_worker_instance_id, :target_worker_group,
                :target_transport, :target_queue, :target_flows, :target_binding_ids, :target_binding_patterns,
                :target_mode, :target_host, :target_all, :target_specificity,
                :created_at, :created_by, :source, :reason, :request_id, :correlation_id, :override, :active
            )
            ON CONFLICT (desired_state_id) DO UPDATE SET
                state = EXCLUDED.state,
                target_worker_id = EXCLUDED.target_worker_id,
                target_worker_name = EXCLUDED.target_worker_name,
                target_worker_instance_id = EXCLUDED.target_worker_instance_id,
                target_worker_group = EXCLUDED.target_worker_group,
                target_transport = EXCLUDED.target_transport,
                target_queue = EXCLUDED.target_queue,
                target_flows = EXCLUDED.target_flows,
                target_binding_ids = EXCLUDED.target_binding_ids,
                target_binding_patterns = EXCLUDED.target_binding_patterns,
                target_mode = EXCLUDED.target_mode,
                target_host = EXCLUDED.target_host,
                target_all = EXCLUDED.target_all,
                target_specificity = EXCLUDED.target_specificity,
                created_at = EXCLUDED.created_at,
                created_by = EXCLUDED.created_by,
                source = EXCLUDED.source,
                reason = EXCLUDED.reason,
                request_id = EXCLUDED.request_id,
                correlation_id = EXCLUDED.correlation_id,
                override = EXCLUDED.override,
                active = EXCLUDED.active'
        );
        $statement->execute($this->desiredStateParams($state));
    }

    public function resolveFor(WorkerIdentity $identity): ?WorkerDesiredState
    {
        $states = \array_filter(
            $this->allDesiredStates(),
            static fn (WorkerDesiredState $state): bool => $state->matches($identity),
        );

        \usort($states, static function (WorkerDesiredState $left, WorkerDesiredState $right): int {
            $score = $right->specificityScore() <=> $left->specificityScore();
            if ($score !== 0) {
                return $score;
            }

            return $right->createdAt <=> $left->createdAt;
        });

        return $states[0] ?? null;
    }

    public function list(WorkerTarget $target): array
    {
        return \array_values(\array_filter(
            $this->allDesiredStates(),
            fn (WorkerDesiredState $state): bool => $this->targetsOverlap($state->target, $target),
        ));
    }

    public function receive(WorkerIdentity $identity, WorkerControlCursor $cursor): WorkerControlBatch
    {
        $rows = $this->commandRowsAfter($cursor, $this->now());
        $commands = [];
        $lastSeenCommandId = $cursor->lastCommandId;

        foreach ($rows as $row) {
            $lastSeenCommandId = $row['command_id'];
            $command = $this->command($row);
            if ($command->target->matches($identity)) {
                $commands[] = $command;
            }
        }

        return new WorkerControlBatch(
            $commands,
            new WorkerControlCursor($lastSeenCommandId),
            $this->resolveFor($identity),
        );
    }

    public function registerWorker(WorkerInstance $worker): void
    {
        $identity = $worker->identity;
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->workerInstancesTable . ' (
                worker_instance_id, worker_id, worker_name, worker_group, host, pid, started_at,
                mode, transport, queue, flows, binding_ids, binding_patterns,
                state, activity, heartbeat_at, children_count,
                last_command_id, last_command_at, stopped_at, failure_message, updated_at
            ) VALUES (
                :worker_instance_id, :worker_id, :worker_name, :worker_group, :host, :pid, :started_at,
                :mode, :transport, :queue, :flows, :binding_ids, :binding_patterns,
                :state, :activity, :heartbeat_at, :children_count,
                :last_command_id, :last_command_at, :stopped_at, :failure_message, :updated_at
            )
            ON CONFLICT (worker_instance_id) DO UPDATE SET
                worker_id = EXCLUDED.worker_id,
                worker_name = EXCLUDED.worker_name,
                worker_group = EXCLUDED.worker_group,
                host = EXCLUDED.host,
                pid = EXCLUDED.pid,
                started_at = EXCLUDED.started_at,
                mode = EXCLUDED.mode,
                transport = EXCLUDED.transport,
                queue = EXCLUDED.queue,
                flows = EXCLUDED.flows,
                binding_ids = EXCLUDED.binding_ids,
                binding_patterns = EXCLUDED.binding_patterns,
                state = EXCLUDED.state,
                activity = EXCLUDED.activity,
                heartbeat_at = EXCLUDED.heartbeat_at,
                children_count = EXCLUDED.children_count,
                last_command_id = EXCLUDED.last_command_id,
                last_command_at = EXCLUDED.last_command_at,
                stopped_at = EXCLUDED.stopped_at,
                failure_message = EXCLUDED.failure_message,
                updated_at = EXCLUDED.updated_at'
        );
        $statement->execute([
            ':worker_instance_id' => $identity->workerInstanceId,
            ':worker_id' => $identity->workerId,
            ':worker_name' => $identity->workerName,
            ':worker_group' => $identity->workerGroup,
            ':host' => $identity->host,
            ':pid' => $identity->pid,
            ':started_at' => $this->formatDate($identity->startedAt),
            ':mode' => $identity->mode->value,
            ':transport' => $identity->transport,
            ':queue' => $identity->queue,
            ':flows' => $this->json($identity->flows),
            ':binding_ids' => $this->json($identity->bindingIds),
            ':binding_patterns' => $this->json($identity->bindingPatterns),
            ':state' => $worker->state->value,
            ':activity' => $worker->activity->value,
            ':heartbeat_at' => $this->formatDate($worker->heartbeatAt),
            ':children_count' => $worker->childrenCount,
            ':last_command_id' => $worker->lastCommandId,
            ':last_command_at' => $this->nullableDate($worker->lastCommandAt),
            ':stopped_at' => $this->nullableDate($worker->stoppedAt),
            ':failure_message' => $worker->failureMessage,
            ':updated_at' => $this->formatDate($worker->heartbeatAt),
        ]);
    }

    public function heartbeatWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        WorkerActivityState $activity,
        DateTimeImmutable $heartbeatAt,
        int $childrenCount = 0,
        ?string $lastCommandId = null,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->workerInstancesTable . '
            SET state = :state,
                activity = :activity,
                heartbeat_at = :heartbeat_at,
                children_count = :children_count,
                last_command_id = COALESCE(:last_command_id, last_command_id),
                last_command_at = CASE WHEN :last_command_id IS NULL THEN last_command_at ELSE :heartbeat_at END,
                updated_at = :heartbeat_at
            WHERE worker_instance_id = :worker_instance_id'
        );
        $statement->execute([
            ':worker_instance_id' => $workerInstanceId,
            ':state' => $state->value,
            ':activity' => $activity->value,
            ':heartbeat_at' => $this->formatDate($heartbeatAt),
            ':children_count' => $childrenCount,
            ':last_command_id' => $lastCommandId,
        ]);
    }

    public function stopWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        DateTimeImmutable $stoppedAt,
        ?string $failureMessage = null,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->workerInstancesTable . '
            SET state = :state,
                activity = :activity,
                heartbeat_at = :stopped_at,
                stopped_at = :stopped_at,
                failure_message = :failure_message,
                updated_at = :stopped_at
            WHERE worker_instance_id = :worker_instance_id'
        );
        $statement->execute([
            ':worker_instance_id' => $workerInstanceId,
            ':state' => $state->value,
            ':activity' => WorkerActivityState::Idle->value,
            ':stopped_at' => $this->formatDate($stoppedAt),
            ':failure_message' => $failureMessage,
        ]);
    }

    public function registerChild(WorkerChildInstance $child): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->childInstancesTable . ' (
                child_instance_id, parent_worker_instance_id, pid, state, started_at, heartbeat_at,
                queue_message_id, message_id, correlation_id, binding_id, finished_at, failure_message, updated_at
            ) VALUES (
                :child_instance_id, :parent_worker_instance_id, :pid, :state, :started_at, :heartbeat_at,
                :queue_message_id, :message_id, :correlation_id, :binding_id, :finished_at, :failure_message, :updated_at
            )
            ON CONFLICT (child_instance_id) DO UPDATE SET
                parent_worker_instance_id = EXCLUDED.parent_worker_instance_id,
                pid = EXCLUDED.pid,
                state = EXCLUDED.state,
                started_at = EXCLUDED.started_at,
                heartbeat_at = EXCLUDED.heartbeat_at,
                queue_message_id = EXCLUDED.queue_message_id,
                message_id = EXCLUDED.message_id,
                correlation_id = EXCLUDED.correlation_id,
                binding_id = EXCLUDED.binding_id,
                finished_at = EXCLUDED.finished_at,
                failure_message = EXCLUDED.failure_message,
                updated_at = EXCLUDED.updated_at'
        );
        $statement->execute([
            ':child_instance_id' => $child->childInstanceId,
            ':parent_worker_instance_id' => $child->parentWorkerInstanceId,
            ':pid' => $child->pid,
            ':state' => $child->state->value,
            ':started_at' => $this->formatDate($child->startedAt),
            ':heartbeat_at' => $this->formatDate($child->heartbeatAt),
            ':queue_message_id' => $child->queueMessageId,
            ':message_id' => $child->messageId,
            ':correlation_id' => $child->correlationId,
            ':binding_id' => $child->bindingId,
            ':finished_at' => $this->nullableDate($child->finishedAt),
            ':failure_message' => $child->failureMessage,
            ':updated_at' => $this->formatDate($child->heartbeatAt),
        ]);
    }

    public function heartbeatChild(string $childInstanceId, WorkerChildState $state, DateTimeImmutable $heartbeatAt): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->childInstancesTable . '
            SET state = :state, heartbeat_at = :heartbeat_at, updated_at = :heartbeat_at
            WHERE child_instance_id = :child_instance_id'
        );
        $statement->execute([
            ':child_instance_id' => $childInstanceId,
            ':state' => $state->value,
            ':heartbeat_at' => $this->formatDate($heartbeatAt),
        ]);
    }

    public function finishChild(
        string $childInstanceId,
        WorkerChildState $state,
        DateTimeImmutable $finishedAt,
        ?string $failureMessage = null,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->childInstancesTable . '
            SET state = :state,
                heartbeat_at = :finished_at,
                finished_at = :finished_at,
                failure_message = :failure_message,
                updated_at = :finished_at
            WHERE child_instance_id = :child_instance_id'
        );
        $statement->execute([
            ':child_instance_id' => $childInstanceId,
            ':state' => $state->value,
            ':finished_at' => $this->formatDate($finishedAt),
            ':failure_message' => $failureMessage,
        ]);
    }

    public function getWorker(string $workerInstanceId): ?WorkerInstance
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . $this->workerInstancesTable . ' WHERE worker_instance_id = :worker_instance_id');
        $statement->execute([':worker_instance_id' => $workerInstanceId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->worker($row);
    }

    public function listWorkers(WorkerTarget $target): array
    {
        $statement = $this->pdo->query('SELECT * FROM ' . $this->workerInstancesTable . ' ORDER BY started_at ASC');
        $workers = \array_map(fn (array $row): WorkerInstance => $this->worker($row), $statement->fetchAll(PDO::FETCH_ASSOC));

        return \array_values(\array_filter(
            $workers,
            static fn (WorkerInstance $worker): bool => $target->matches($worker->identity),
        ));
    }

    public function listChildren(string $workerInstanceId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . $this->childInstancesTable . ' WHERE parent_worker_instance_id = :parent_worker_instance_id ORDER BY started_at ASC');
        $statement->execute([':parent_worker_instance_id' => $workerInstanceId]);

        return \array_map(fn (array $row): WorkerChildInstance => $this->child($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    public function acknowledgementsForCommand(string $commandId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . $this->acknowledgementsTable . ' WHERE command_id = :command_id ORDER BY acknowledged_at ASC');
        $statement->execute([':command_id' => $commandId]);

        return \array_map(fn (array $row): WorkerControlAcknowledgement => $this->acknowledgement($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed> */
    private function commandParams(WorkerControlCommand $command): array
    {
        return $this->targetParams($command->target) + [
            ':command_id' => $command->commandId,
            ':type' => $command->type->value,
            ':created_at' => $this->formatDate($command->createdAt),
            ':created_by' => $command->createdBy,
            ':source' => $command->source,
            ':reason' => $command->reason,
            ':request_id' => $command->requestId,
            ':correlation_id' => $command->correlationId,
            ':expires_at' => $this->nullableDate($command->expiresAt),
            ':idempotency_key' => $command->idempotencyKey,
            ':override' => $this->sqlBool($command->override),
        ];
    }

    /** @return array<string, mixed> */
    private function desiredStateParams(WorkerDesiredState $state): array
    {
        return $this->targetParams($state->target) + [
            ':desired_state_id' => $state->desiredStateId,
            ':state' => $state->type->value,
            ':created_at' => $this->formatDate($state->createdAt),
            ':created_by' => $state->createdBy,
            ':source' => $state->source,
            ':reason' => $state->reason,
            ':request_id' => $state->requestId,
            ':correlation_id' => $state->correlationId,
            ':override' => $this->sqlBool($state->override),
            ':active' => $this->sqlBool($state->active),
        ];
    }

    /** @return array<string, mixed> */
    private function targetParams(WorkerTarget $target): array
    {
        return [
            ':target_worker_id' => $target->workerId,
            ':target_worker_name' => $target->workerName,
            ':target_worker_instance_id' => $target->workerInstanceId,
            ':target_worker_group' => $target->workerGroup,
            ':target_transport' => $target->transport,
            ':target_queue' => $target->queue,
            ':target_flows' => $this->json($target->flows),
            ':target_binding_ids' => $this->json($target->bindingIds),
            ':target_binding_patterns' => $this->json($target->bindingPatterns),
            ':target_mode' => $target->mode?->value,
            ':target_host' => $target->host,
            ':target_all' => $this->sqlBool($target->all),
            ':target_specificity' => $target->specificityScore(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function commandRowsAfter(WorkerControlCursor $cursor, DateTimeImmutable $now): array
    {
        $lastId = null;
        if ($cursor->lastCommandId !== null) {
            $statement = $this->pdo->prepare('SELECT id FROM ' . $this->commandsTable . ' WHERE command_id = :command_id');
            $statement->execute([':command_id' => $cursor->lastCommandId]);
            $value = $statement->fetchColumn();
            $lastId = $value === false ? null : (int) $value;
        }

        $sql = 'SELECT * FROM ' . $this->commandsTable . '
            WHERE (expires_at IS NULL OR expires_at > :now)';
        $params = [':now' => $this->formatDate($now)];

        if ($lastId !== null) {
            $sql .= ' AND id > :last_id';
            $params[':last_id'] = $lastId;
        }

        $sql .= ' ORDER BY id ASC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return list<WorkerDesiredState>
     */
    private function allDesiredStates(): array
    {
        $statement = $this->pdo->query('SELECT * FROM ' . $this->desiredStatesTable . ' WHERE active = TRUE ORDER BY target_specificity DESC, created_at DESC');

        return \array_map(fn (array $row): WorkerDesiredState => $this->desiredState($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, mixed> $row */
    private function command(array $row): WorkerControlCommand
    {
        return new WorkerControlCommand(
            $row['command_id'],
            WorkerControlCommandType::from($row['type']),
            $this->target($row),
            $this->date($row['created_at']),
            $row['created_by'],
            $row['source'],
            $row['reason'],
            $row['request_id'],
            $row['correlation_id'],
            $this->nullableDateTime($row['expires_at']),
            $row['idempotency_key'],
            $this->bool($row['override']),
        );
    }

    /** @param array<string, mixed> $row */
    private function desiredState(array $row): WorkerDesiredState
    {
        return new WorkerDesiredState(
            $row['desired_state_id'],
            WorkerDesiredStateType::from($row['state']),
            $this->target($row),
            $this->date($row['created_at']),
            $row['created_by'],
            $row['source'],
            $row['reason'],
            $row['request_id'],
            $row['correlation_id'],
            $this->bool($row['override']),
            $this->bool($row['active']),
        );
    }

    /** @param array<string, mixed> $row */
    private function target(array $row): WorkerTarget
    {
        return new WorkerTarget(
            workerId: $row['target_worker_id'],
            workerName: $row['target_worker_name'],
            workerInstanceId: $row['target_worker_instance_id'],
            workerGroup: $row['target_worker_group'],
            transport: $row['target_transport'],
            queue: $row['target_queue'],
            flows: $this->jsonArray($row['target_flows']),
            bindingIds: $this->jsonArray($row['target_binding_ids']),
            bindingPatterns: $this->jsonArray($row['target_binding_patterns']),
            mode: $row['target_mode'] === null ? null : WorkerMode::from($row['target_mode']),
            host: $row['target_host'],
            all: $this->bool($row['target_all']),
        );
    }

    /** @param array<string, mixed> $row */
    private function worker(array $row): WorkerInstance
    {
        return new WorkerInstance(
            new WorkerIdentity(
                workerName: $row['worker_name'],
                workerInstanceId: $row['worker_instance_id'],
                workerGroup: $row['worker_group'],
                host: $row['host'],
                pid: (int) $row['pid'],
                startedAt: $this->date($row['started_at']),
                mode: WorkerMode::from($row['mode']),
                transport: $row['transport'],
                queue: $row['queue'],
                flows: $this->jsonArray($row['flows']),
                bindingIds: $this->jsonArray($row['binding_ids']),
                bindingPatterns: $this->jsonArray($row['binding_patterns']),
                workerId: $row['worker_id'],
            ),
            WorkerLifecycleState::from($row['state']),
            WorkerActivityState::from($row['activity']),
            $this->date($row['heartbeat_at']),
            (int) $row['children_count'],
            $row['last_command_id'],
            $this->nullableDateTime($row['last_command_at']),
            $this->nullableDateTime($row['stopped_at']),
            $row['failure_message'],
        );
    }

    /** @param array<string, mixed> $row */
    private function child(array $row): WorkerChildInstance
    {
        return new WorkerChildInstance(
            $row['child_instance_id'],
            $row['parent_worker_instance_id'],
            (int) $row['pid'],
            WorkerChildState::from($row['state']),
            $this->date($row['started_at']),
            $this->date($row['heartbeat_at']),
            $row['queue_message_id'],
            $row['message_id'],
            $row['correlation_id'],
            $row['binding_id'],
            $this->nullableDateTime($row['finished_at']),
            $row['failure_message'],
        );
    }

    /** @param array<string, mixed> $row */
    private function acknowledgement(array $row): WorkerControlAcknowledgement
    {
        return new WorkerControlAcknowledgement(
            $row['command_id'],
            $row['worker_instance_id'],
            WorkerControlAcknowledgementState::from($row['state']),
            $this->date($row['acknowledged_at']),
            $row['message'],
            $row['error_class'],
            $row['error_message'],
        );
    }

    private function targetsOverlap(WorkerTarget $left, WorkerTarget $right): bool
    {
        if ($left->all || $right->all) {
            return true;
        }

        foreach ([
            'workerId',
            'workerName',
            'workerInstanceId',
            'workerGroup',
            'transport',
            'queue',
            'host',
        ] as $field) {
            if ($left->{$field} !== null && $right->{$field} !== null && $left->{$field} !== $right->{$field}) {
                return false;
            }
        }

        if ($left->mode !== null && $right->mode !== null && $left->mode !== $right->mode) {
            return false;
        }

        return $this->arraysOverlap($left->flows, $right->flows)
            && $this->arraysOverlap($left->bindingIds, $right->bindingIds)
            && $this->arraysOverlap($left->bindingPatterns, $right->bindingPatterns);
    }

    /** @param list<string> $left */
    private function arraysOverlap(array $left, array $right): bool
    {
        return $left === [] || $right === [] || \array_intersect($left, $right) !== [];
    }

    /** @param list<mixed> $data */
    private function json(array $data): string
    {
        return \json_encode($data, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function jsonArray(string $json): array
    {
        $data = \json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return \is_array($data) ? \array_values(\array_map('strval', $data)) : [];
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function nullableDate(?DateTimeImmutable $date): ?string
    {
        return $date === null ? null : $this->formatDate($date);
    }

    private function nullableDateTime(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->date($value);
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private function bool(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    private function sqlBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }
}
