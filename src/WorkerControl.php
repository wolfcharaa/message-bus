<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

/**
 *  Desired claim state for the global runtime or one named profile.
 *
 * @api application
 */
enum WorkerDesiredState: string
{
    case Running = 'running';
    case Paused = 'paused';
}

/**
 *  Stable global or profile worker-control scope.
 *
 * @api application
 */
final readonly class WorkerControlScope
{
    private function __construct(public string $value) {}

    public static function global(): self
    {
        return new self('global');
    }

    public static function profile(string $profile): self
    {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{1,63}$/D', $profile) !== 1) {
            throw new \InvalidArgumentException(
                'WorkerControlScope profile must match [a-z0-9][a-z0-9._-]{1,63}.',
            );
        }

        return new self('profile:' . $profile);
    }
}

/**
 *  Revisioned desired-state snapshot.
 *
 * @api application
 */
final readonly class WorkerControlState
{
    public function __construct(
        public WorkerControlScope $scope,
        public WorkerDesiredState $desiredState,
        public int $revision,
        public \DateTimeImmutable $updatedAt,
    ) {}
}

/**
 *  Observable result of one idempotent pause/resume operation.
 *
 * @api application
 */
enum WorkerControlOutcome: string
{
    case Applied = 'applied';
    case AlreadyDesired = 'already_desired';
    case RevisionConflict = 'revision_conflict';
}

/**
 *  Receipt for a revisioned worker control change.
 *
 * @api application
 */
final readonly class WorkerControlReceipt
{
    public function __construct(
        public WorkerControlState $state,
        public WorkerControlOutcome $outcome,
        public ?string $operationId = null,
        public ?\DateTimeImmutable $acceptedAt = null,
        public ?WorkerDesiredState $previousDesiredState = null,
        public bool $duplicate = false,
    ) {}
}

/**
 *  Current liveness snapshot reported by one parent supervisor.
 *
 * @api application
 */
final readonly class WorkerStatus
{
    public function __construct(
        public string $workerId,
        public string $profile,
        public string $lane,
        public int $pid,
        public string $state,
        public int $activeAttempts,
        public \DateTimeImmutable $startedAt,
        public \DateTimeImmutable $heartbeatAt,
        public string $host = 'unknown',
        public string $buildId = 'unknown',
        public string $registryHash = '0000000000000000000000000000000000000000000000000000000000000000',
        public int $capacity = 1,
        public int $appliedGlobalRevision = 0,
        public int $appliedProfileRevision = 0,
        public ?\DateTimeImmutable $stoppedAt = null,
        public int $requiredGlobalRevision = 0,
        public int $requiredProfileRevision = 0,
        public WorkerDesiredState $effectiveDesiredState = WorkerDesiredState::Running,
        public bool $stale = false,
        public bool $drained = false,
    ) {
        if ($this->capacity < 1) {
            throw new \InvalidArgumentException('WorkerStatus capacity must be at least 1.');
        }
        if ($this->activeAttempts < 0) {
            throw new \InvalidArgumentException('WorkerStatus activeAttempts must not be negative.');
        }
        if ($this->requiredGlobalRevision < 0) {
            throw new \InvalidArgumentException('WorkerStatus requiredGlobalRevision must not be negative.');
        }
        if ($this->requiredProfileRevision < 0) {
            throw new \InvalidArgumentException('WorkerStatus requiredProfileRevision must not be negative.');
        }
        if ($this->drained && $this->effectiveDesiredState !== WorkerDesiredState::Paused) {
            throw new \InvalidArgumentException('WorkerStatus drained requires effectiveDesiredState paused.');
        }
        if ($this->drained && $this->activeAttempts !== 0) {
            throw new \InvalidArgumentException('WorkerStatus drained requires activeAttempts 0.');
        }
        if ($this->drained && $this->stale) {
            throw new \InvalidArgumentException('WorkerStatus drained cannot also be stale.');
        }
    }

    public function activity(): string
    {
        return $this->activeAttempts > 0 ? 'busy' : 'idle';
    }
}

/**
 * Read-only worker status filter and liveness horizon.
 *
 * @api application
 */
final readonly class WorkerStatusFilter
{
    public function __construct(
        public ?string $profile = null,
        public ?string $instance = null,
        public int $staleAfterSeconds = 30,
    ) {
        if ($this->profile === '') {
            throw new \InvalidArgumentException('WorkerStatusFilter profile must not be empty.');
        }
        if ($this->instance === '') {
            throw new \InvalidArgumentException('WorkerStatusFilter instance must not be empty.');
        }
        if ($this->profile !== null && $this->instance !== null) {
            throw new \InvalidArgumentException('WorkerStatusFilter accepts either profile or instance, not both.');
        }
        if ($this->staleAfterSeconds < 1) {
            throw new \InvalidArgumentException('WorkerStatusFilter staleAfterSeconds must be at least 1.');
        }
    }
}

/**
 *  Bounded dry-run/execute counters for stale worker facts.
 *
 * @api application
 */
final readonly class WorkerPruneReport
{
    public function __construct(
        public int $statuses,
        public int $operations,
        public bool $executed,
        public bool $hasMore,
    ) {}
}

/**
 *  Public pause/resume/status operations; process start/stop stays external.
 *
 * @api application
 */
interface WorkerControlOperations
{
    public function state(WorkerControlScope $scope): WorkerControlState;

    public function pause(
        WorkerControlScope $scope,
        string $actor,
        string $reason,
        string $idempotencyKey,
        ?int $expectedRevision = null,
    ): WorkerControlReceipt;

    public function resume(
        WorkerControlScope $scope,
        string $actor,
        string $reason,
        string $idempotencyKey,
        ?int $expectedRevision = null,
    ): WorkerControlReceipt;

    /** @return list<WorkerStatus> */
    public function statuses(WorkerStatusFilter $filter = new WorkerStatusFilter()): array;

    public function report(WorkerStatus $status): void;

    public function prune(\DateTimeImmutable $olderThan, int $limit, bool $execute): WorkerPruneReport;
}

namespace Wolfcharaa\MessageBus\Adapter\Postgres;

use Wolfcharaa\MessageBus\Internal\Postgres\QualifiedSql;
use Wolfcharaa\MessageBus\Internal\Runtime\FailurePreservingCleanup;
use Wolfcharaa\MessageBus\Worker\WorkerControlOperations;
use Wolfcharaa\MessageBus\Worker\WorkerControlOutcome;
use Wolfcharaa\MessageBus\Worker\WorkerControlReceipt;
use Wolfcharaa\MessageBus\Worker\WorkerControlScope;
use Wolfcharaa\MessageBus\Worker\WorkerControlState;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredState;
use Wolfcharaa\MessageBus\Worker\WorkerPruneReport;
use Wolfcharaa\MessageBus\Worker\WorkerStatus;
use Wolfcharaa\MessageBus\Worker\WorkerStatusFilter;

/**
 *  PostgreSQL revisioned worker control and status adapter.
 *
 * @api adapter
 */
final readonly class PostgresWorkerControl implements WorkerControlOperations
{
    public function __construct(private \PDO $pdo, private string $schema = 'public')
    {
        QualifiedSql::validateSchema($this->schema);
    }

    public function state(WorkerControlScope $scope): WorkerControlState
    {
        $statement = $this->prepare(<<<'SQL'
            SELECT desired_state, revision, updated_at
            FROM message_bus__worker_desired_states
            WHERE scope = :scope
            SQL);
        $statement->execute(['scope' => $scope->value]);
        $row = $this->row($statement);
        if ($row === null) {
            return new WorkerControlState(
                $scope,
                WorkerDesiredState::Running,
                0,
                new \DateTimeImmutable('@0'),
            );
        }

        return new WorkerControlState(
            $scope,
            WorkerDesiredState::from($this->string($row, 'desired_state')),
            $this->integer($row, 'revision'),
            new \DateTimeImmutable($this->string($row, 'updated_at')),
        );
    }

    public function pause(
        WorkerControlScope $scope,
        string $actor,
        string $reason,
        string $idempotencyKey,
        ?int $expectedRevision = null,
    ): WorkerControlReceipt {
        return $this->change($scope, WorkerDesiredState::Paused, $actor, $reason, $idempotencyKey, $expectedRevision);
    }

    public function resume(
        WorkerControlScope $scope,
        string $actor,
        string $reason,
        string $idempotencyKey,
        ?int $expectedRevision = null,
    ): WorkerControlReceipt {
        return $this->change($scope, WorkerDesiredState::Running, $actor, $reason, $idempotencyKey, $expectedRevision);
    }

    public function statuses(WorkerStatusFilter $filter = new WorkerStatusFilter()): array
    {
        $sql = <<<'SQL'
            SELECT wi.worker_instance_id, wi.profile, wi.lane, wi.host, wi.pid,
                   wi.build_id, wi.registry_hash, wi.lifecycle, wi.capacity,
                   wi.applied_global_revision, wi.applied_profile_revision,
                   wi.started_at, wi.heartbeat_at, wi.stopped_at,
                   clock_timestamp() AS database_now,
                   COALESCE(global_state.revision, 0) AS required_global_revision,
                   COALESCE(profile_state.revision, 0) AS required_profile_revision,
                   CASE WHEN COALESCE(global_state.desired_state, 'running') = 'paused'
                          OR COALESCE(profile_state.desired_state, 'running') = 'paused'
                        THEN 'paused' ELSE 'running' END AS effective_desired_state,
                   (SELECT count(*)
                    FROM message_bus__delivery_attempts a
                    JOIN message_bus__deliveries d ON d.current_attempt_id = a.attempt_id
                    WHERE a.worker_id = wi.worker_instance_id
                      AND a.finished_at IS NULL AND d.state = 'running') AS active_attempts
            FROM message_bus__worker_instances wi
            LEFT JOIN message_bus__worker_desired_states global_state
              ON global_state.scope = 'global'
            LEFT JOIN message_bus__worker_desired_states profile_state
              ON profile_state.scope = 'profile:' || wi.profile
            SQL;
        $parameters = [];
        if ($filter->profile !== null) {
            $sql .= ' WHERE wi.profile = :profile';
            $parameters['profile'] = $filter->profile;
        } elseif ($filter->instance !== null) {
            $sql .= ' WHERE wi.worker_instance_id = :instance';
            $parameters['instance'] = $filter->instance;
        }
        $sql .= ' ORDER BY wi.profile, wi.worker_instance_id';
        $statement = $this->prepare($sql);
        $statement->execute($parameters);

        $statuses = [];
        foreach ($this->rows($statement) as $row) {
            $activeAttempts = $this->integer($row, 'active_attempts');
            $requiredGlobal = $this->integer($row, 'required_global_revision');
            $requiredProfile = $this->integer($row, 'required_profile_revision');
            $effective = WorkerDesiredState::from($this->string($row, 'effective_desired_state'));
            $heartbeatAt = new \DateTimeImmutable($this->string($row, 'heartbeat_at'));
            $databaseNow = new \DateTimeImmutable($this->string($row, 'database_now'));
            $stale = $heartbeatAt < $databaseNow->modify('-' . $filter->staleAfterSeconds . ' seconds');
            $appliedGlobal = $this->integer($row, 'applied_global_revision');
            $appliedProfile = $this->integer($row, 'applied_profile_revision');
            $drained = !$stale && $effective === WorkerDesiredState::Paused && $activeAttempts === 0
                && $appliedGlobal >= $requiredGlobal && $appliedProfile >= $requiredProfile;
            $statuses[] = new WorkerStatus(
                $this->string($row, 'worker_instance_id'),
                $this->string($row, 'profile'),
                $this->string($row, 'lane'),
                $this->integer($row, 'pid'),
                $this->string($row, 'lifecycle'),
                $activeAttempts,
                new \DateTimeImmutable($this->string($row, 'started_at')),
                $heartbeatAt,
                $this->string($row, 'host'),
                $this->string($row, 'build_id'),
                $this->string($row, 'registry_hash'),
                $this->integer($row, 'capacity'),
                $appliedGlobal,
                $appliedProfile,
                ($stopped = $this->nullableString($row, 'stopped_at')) === null
                    ? null
                    : new \DateTimeImmutable($stopped),
                $requiredGlobal,
                $requiredProfile,
                $effective,
                $stale,
                $drained,
            );
        }

        return $statuses;
    }

    public function report(WorkerStatus $status): void
    {
        $statement = $this->prepare(<<<'SQL'
            INSERT INTO message_bus__worker_instances
                (worker_instance_id, profile, lane, host, pid, build_id, registry_hash,
                 lifecycle, capacity, active_attempts, applied_global_revision,
                 applied_profile_revision, started_at, heartbeat_at, stopped_at)
            VALUES
                (:worker_instance_id, :profile, :lane, :host, :pid, :build_id, :registry_hash,
                 :lifecycle, :capacity, :active_attempts, :applied_global_revision,
                 :applied_profile_revision, :started_at, clock_timestamp(), :stopped_at)
            ON CONFLICT (worker_instance_id) DO UPDATE SET
                profile = EXCLUDED.profile,
                lane = EXCLUDED.lane,
                host = EXCLUDED.host,
                pid = EXCLUDED.pid,
                build_id = EXCLUDED.build_id,
                registry_hash = EXCLUDED.registry_hash,
                lifecycle = EXCLUDED.lifecycle,
                capacity = EXCLUDED.capacity,
                active_attempts = EXCLUDED.active_attempts,
                applied_global_revision = EXCLUDED.applied_global_revision,
                applied_profile_revision = EXCLUDED.applied_profile_revision,
                stopped_at = EXCLUDED.stopped_at,
                heartbeat_at = clock_timestamp()
            SQL);
        $statement->execute([
            'worker_instance_id' => $status->workerId,
            'profile' => $status->profile,
            'lane' => $status->lane,
            'host' => $status->host,
            'pid' => $status->pid,
            'build_id' => $status->buildId,
            'registry_hash' => $status->registryHash,
            'lifecycle' => $status->state,
            'capacity' => $status->capacity,
            'active_attempts' => $status->activeAttempts,
            'applied_global_revision' => $status->appliedGlobalRevision,
            'applied_profile_revision' => $status->appliedProfileRevision,
            'started_at' => $status->startedAt->format(DATE_ATOM),
            'stopped_at' => $status->stoppedAt?->format(DATE_ATOM),
        ]);
    }

    public function prune(\DateTimeImmutable $olderThan, int $limit, bool $execute): WorkerPruneReport
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Worker status prune limit must be positive.');
        }
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Worker prune requires a connection without an open transaction.');
        }
        $this->pdo->beginTransaction();

        try {
            $statement = $this->prepare(<<<'SQL'
                SELECT worker_instance_id
                FROM message_bus__worker_instances wi
                WHERE wi.heartbeat_at < :older_than AND wi.lifecycle IN ('stopped', 'failed')
                  AND NOT EXISTS (
                      SELECT 1
                      FROM message_bus__delivery_attempts a
                      JOIN message_bus__deliveries d ON d.current_attempt_id = a.attempt_id
                      WHERE a.worker_id = wi.worker_instance_id
                        AND a.finished_at IS NULL AND d.state = 'running'
                  )
                ORDER BY wi.heartbeat_at, wi.worker_instance_id
                FOR UPDATE SKIP LOCKED
                LIMIT :limit
                SQL);
            $statement->bindValue('older_than', $olderThan->format(DATE_ATOM));
            $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
            $statement->execute();
            $statusIds = $statement->fetchAll(\PDO::FETCH_COLUMN);
            $operations = $this->prepare(<<<'SQL'
                SELECT operation_id
                FROM message_bus__worker_control_operations
                WHERE accepted_at < :older_than
                ORDER BY accepted_at, operation_id
                FOR UPDATE SKIP LOCKED
                LIMIT :limit
                SQL);
            $operations->bindValue('older_than', $olderThan->format(DATE_ATOM));
            $operations->bindValue('limit', $limit, \PDO::PARAM_INT);
            $operations->execute();
            $operationIds = $operations->fetchAll(\PDO::FETCH_COLUMN);
            if ($execute && $statusIds !== []) {
                $placeholders = implode(', ', array_fill(0, \count($statusIds), '?'));
                $delete = $this->prepare(
                    'DELETE FROM message_bus__worker_instances wi '
                    . "WHERE wi.lifecycle IN ('stopped', 'failed') "
                    . 'AND wi.worker_instance_id IN (' . $placeholders . ') '
                    . 'AND NOT EXISTS (SELECT 1 FROM message_bus__delivery_attempts a '
                    . 'JOIN message_bus__deliveries d ON d.current_attempt_id = a.attempt_id '
                    . 'WHERE a.worker_id = wi.worker_instance_id '
                    . "AND a.finished_at IS NULL AND d.state = 'running')",
                );
                $delete->execute($statusIds);
            }
            if ($execute && $operationIds !== []) {
                $placeholders = implode(', ', array_fill(0, \count($operationIds), '?'));
                $delete = $this->prepare(
                    'DELETE FROM message_bus__worker_control_operations WHERE operation_id IN (' . $placeholders . ')',
                );
                $delete->execute($operationIds);
            }
            $this->pdo->commit();

            return new WorkerPruneReport(
                \count($statusIds),
                \count($operationIds),
                $execute,
                \count($statusIds) === $limit || \count($operationIds) === $limit,
            );
        } catch (\Throwable $failure) {
            FailurePreservingCleanup::rethrowAfter(
                $failure,
                'PostgreSQL worker status pruning rollback',
                $this->rollBackOpenTransaction(...),
            );
        }
    }

    private function change(
        WorkerControlScope $scope,
        WorkerDesiredState $desired,
        string $actor,
        string $reason,
        string $idempotencyKey,
        ?int $expectedRevision,
    ): WorkerControlReceipt {
        if ($actor === '') {
            throw new \InvalidArgumentException('Worker control actor must not be empty.');
        }
        if ($reason === '') {
            throw new \InvalidArgumentException('Worker control reason must not be empty.');
        }
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}$/D', $idempotencyKey) !== 1) {
            throw new \InvalidArgumentException(
                'Worker control idempotencyKey must match [a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}.',
            );
        }
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Worker control mutation requires a connection without an open transaction.');
        }
        $this->pdo->beginTransaction();

        try {
            $lock = $this->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:scope, 0))');
            $lock->execute(['scope' => $scope->value]);
            $idempotencyLock = $this->prepare(
                'SELECT pg_advisory_xact_lock(hashtextextended(:idempotency_key, 1))',
            );
            $idempotencyLock->execute(['idempotency_key' => $idempotencyKey]);
            $operation = $this->prepare(<<<'SQL'
                SELECT operation_id, scope, previous_state, desired_state, outcome,
                       resulting_revision, accepted_at
                FROM message_bus__worker_control_operations
                WHERE idempotency_key = :idempotency_key
                SQL);
            $operation->execute(['idempotency_key' => $idempotencyKey]);
            $existing = $this->row($operation);
            if ($existing !== null) {
                if ($this->string($existing, 'scope') !== $scope->value
                    || $this->string($existing, 'desired_state') !== $desired->value) {
                    throw new \LogicException('Worker control idempotency key conflicts with another intent.');
                }
                $state = new WorkerControlState(
                    $scope,
                    WorkerDesiredState::from($this->string($existing, 'desired_state')),
                    $this->integer($existing, 'resulting_revision'),
                    new \DateTimeImmutable($this->string($existing, 'accepted_at')),
                );
                $this->pdo->commit();

                return new WorkerControlReceipt(
                    $state,
                    WorkerControlOutcome::from($this->string($existing, 'outcome')),
                    $this->string($existing, 'operation_id'),
                    new \DateTimeImmutable($this->string($existing, 'accepted_at')),
                    WorkerDesiredState::from($this->string($existing, 'previous_state')),
                    true,
                );
            }
            $current = $this->stateForUpdate($scope);
            if ($expectedRevision !== null && $current->revision !== $expectedRevision) {
                [$operationId, $acceptedAt] = $this->recordOperation(
                    $idempotencyKey,
                    $scope,
                    $current->desiredState,
                    $desired,
                    WorkerControlOutcome::RevisionConflict,
                    $current->revision,
                    $actor,
                    $reason,
                );
                $this->pdo->commit();

                return new WorkerControlReceipt(
                    $current,
                    WorkerControlOutcome::RevisionConflict,
                    $operationId,
                    $acceptedAt,
                    $current->desiredState,
                );
            }
            if ($current->desiredState === $desired && $current->revision > 0) {
                [$operationId, $acceptedAt] = $this->recordOperation(
                    $idempotencyKey,
                    $scope,
                    $current->desiredState,
                    $desired,
                    WorkerControlOutcome::AlreadyDesired,
                    $current->revision,
                    $actor,
                    $reason,
                );
                $this->pdo->commit();

                return new WorkerControlReceipt(
                    $current,
                    WorkerControlOutcome::AlreadyDesired,
                    $operationId,
                    $acceptedAt,
                    $current->desiredState,
                );
            }

            $revision = $current->revision + 1;
            $statement = $this->prepare(<<<'SQL'
                INSERT INTO message_bus__worker_desired_states
                    (scope, desired_state, revision, actor, reason)
                VALUES (:scope, :desired_state, :revision, :actor, :reason)
                ON CONFLICT (scope) DO UPDATE SET
                    desired_state = EXCLUDED.desired_state,
                    revision = EXCLUDED.revision,
                    actor = EXCLUDED.actor,
                    reason = EXCLUDED.reason,
                    updated_at = clock_timestamp()
                RETURNING updated_at
                SQL);
            $statement->execute([
                'scope' => $scope->value,
                'desired_state' => $desired->value,
                'revision' => $revision,
                'actor' => $actor,
                'reason' => $reason,
            ]);
            $updated = $statement->fetchColumn();
            if (!\is_string($updated)) {
                throw new \UnexpectedValueException('PostgreSQL worker control timestamp must be a string.');
            }
            $updatedAt = new \DateTimeImmutable($updated);
            $state = new WorkerControlState($scope, $desired, $revision, $updatedAt);
            [$operationId, $acceptedAt] = $this->recordOperation(
                $idempotencyKey,
                $scope,
                $current->desiredState,
                $desired,
                WorkerControlOutcome::Applied,
                $revision,
                $actor,
                $reason,
            );
            $this->pdo->commit();

            return new WorkerControlReceipt(
                $state,
                WorkerControlOutcome::Applied,
                $operationId,
                $acceptedAt,
                $current->desiredState,
            );
        } catch (\Throwable $failure) {
            FailurePreservingCleanup::rethrowAfter(
                $failure,
                'PostgreSQL worker control mutation rollback',
                $this->rollBackOpenTransaction(...),
            );
        }
    }

    private function stateForUpdate(WorkerControlScope $scope): WorkerControlState
    {
        $statement = $this->prepare(<<<'SQL'
            SELECT desired_state, revision, updated_at
            FROM message_bus__worker_desired_states
            WHERE scope = :scope
            FOR UPDATE
            SQL);
        $statement->execute(['scope' => $scope->value]);
        $row = $this->row($statement);

        return $row !== null
            ? new WorkerControlState(
                $scope,
                WorkerDesiredState::from($this->string($row, 'desired_state')),
                $this->integer($row, 'revision'),
                new \DateTimeImmutable($this->string($row, 'updated_at')),
            )
            : new WorkerControlState($scope, WorkerDesiredState::Running, 0, new \DateTimeImmutable('@0'));
    }

    /** @return array{string, \DateTimeImmutable} */
    private function recordOperation(
        string $idempotencyKey,
        WorkerControlScope $scope,
        WorkerDesiredState $previous,
        WorkerDesiredState $desired,
        WorkerControlOutcome $outcome,
        int $revision,
        string $actor,
        string $reason,
    ): array {
        $operationId = 'worker:' . substr(hash('sha256', $idempotencyKey), 0, 32);
        $statement = $this->prepare(<<<'SQL'
            INSERT INTO message_bus__worker_control_operations
                (operation_id, idempotency_key, scope, previous_state, desired_state,
                 outcome, resulting_revision, actor, reason)
            VALUES
                (:operation_id, :idempotency_key, :scope, :previous_state, :desired_state,
                 :outcome, :revision, :actor, :reason)
            RETURNING accepted_at
            SQL);
        $statement->execute([
            'operation_id' => $operationId,
            'idempotency_key' => $idempotencyKey,
            'scope' => $scope->value,
            'previous_state' => $previous->value,
            'desired_state' => $desired->value,
            'outcome' => $outcome->value,
            'revision' => $revision,
            'actor' => $actor,
            'reason' => $reason,
        ]);
        $acceptedAt = $statement->fetchColumn();
        if (!\is_string($acceptedAt)) {
            throw new \UnexpectedValueException('PostgreSQL worker operation timestamp must be a string.');
        }

        return [$operationId, new \DateTimeImmutable($acceptedAt)];
    }

    private function prepare(string $sql): \PDOStatement
    {
        return $this->pdo->prepare(QualifiedSql::qualify($sql, $this->schema));
    }

    private function rollBackOpenTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /** @return array<string, mixed>|null */
    private function row(\PDOStatement $statement): ?array
    {
        $value = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($value === false) {
            return null;
        }
        if (!\is_array($value)) {
            throw new \UnexpectedValueException('PostgreSQL row must be an array.');
        }
        $row = [];
        foreach ($value as $key => $field) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('PostgreSQL row has a non-string key.');
            }
            $row[$key] = $field;
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function rows(\PDOStatement $statement): array
    {
        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $value) {
            if (!\is_array($value)) {
                throw new \UnexpectedValueException('PostgreSQL row must be an array.');
            }
            $row = [];
            foreach ($value as $key => $field) {
                if (!\is_string($key)) {
                    throw new \UnexpectedValueException('PostgreSQL row has a non-string key.');
                }
                $row[$key] = $field;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('PostgreSQL field must be a string: ' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function nullableString(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;
        if ($value !== null && !\is_string($value)) {
            throw new \UnexpectedValueException('PostgreSQL field must be string|null: ' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!\is_int($value) && (!\is_string($value) || preg_match('/^-?[0-9]+$/D', $value) !== 1)) {
            throw new \UnexpectedValueException('PostgreSQL field must be an integer: ' . $key);
        }

        return (int) $value;
    }
}
