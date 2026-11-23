<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Adapter\Postgres;

use Wolfcharaa\MessageBus\Codec\EncodedPayload;
use Wolfcharaa\MessageBus\Delivery\CancellationOutcome;
use Wolfcharaa\MessageBus\Delivery\CancellationReceipt;
use Wolfcharaa\MessageBus\Delivery\ClaimedDelivery;
use Wolfcharaa\MessageBus\Delivery\ControlActor;
use Wolfcharaa\MessageBus\Delivery\DeliveryAcceptance;
use Wolfcharaa\MessageBus\Delivery\DeliveryAttempt;
use Wolfcharaa\MessageBus\Delivery\DeliveryDraft;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\Delivery\DeliveryState;
use Wolfcharaa\MessageBus\Delivery\DeliveryStatus;
use Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend;
use Wolfcharaa\MessageBus\Delivery\FailureCategory;
use Wolfcharaa\MessageBus\Delivery\MaintenanceReport;
use Wolfcharaa\MessageBus\Delivery\ReplayReceipt;
use Wolfcharaa\MessageBus\Identity\CorrelationId;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\IdentityGenerator;
use Wolfcharaa\MessageBus\Identity\MessageId;
use Wolfcharaa\MessageBus\Identity\RandomIdentityGenerator;
use Wolfcharaa\MessageBus\Internal\Postgres\QualifiedSql;
use Wolfcharaa\MessageBus\Metadata\MessageMetadata;
use Wolfcharaa\MessageBus\Registry\MessageRole;

/**
 *  PostgreSQL durable backend using database time, SKIP LOCKED and fenced CAS.
 *
 * @api adapter
 */
final readonly class PostgresDeliveryBackend implements DurableDeliveryBackend
{
    public function __construct(
        private \PDO $pdo,
        private IdentityGenerator $identities = new RandomIdentityGenerator(),
        private string $schema = 'public',
    ) {
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        QualifiedSql::validateSchema($this->schema);
    }

    public function accept(DeliveryDraft $delivery): DeliveryAcceptance
    {
        $staged = $this->pdo->inTransaction();

        return $this->transaction(function () use ($delivery, $staged): DeliveryAcceptance {
            $metadata = json_encode((object) $delivery->metadata->application, JSON_THROW_ON_ERROR);
            $payloadDigest = hash('sha256', $delivery->payload->bytes);
            $message = $this->prepare(<<<'SQL'
                INSERT INTO message_bus__messages
                    (message_id, correlation_id, causation_id, role, message_name,
                     payload_version, codec, content_type, object_graph_fingerprint,
                     payload, payload_digest, metadata, recorded_at)
                VALUES
                    (:message_id, :correlation_id, :causation_id, :role, :message_name,
                     :payload_version, :codec, :content_type, :object_graph_fingerprint,
                     decode(:payload, 'base64'), :payload_digest, CAST(:metadata AS jsonb), :recorded_at)
                ON CONFLICT (message_id) DO NOTHING
                SQL);
            $message->execute([
                'message_id' => $delivery->metadata->messageId->value,
                'correlation_id' => $delivery->metadata->correlationId->value,
                'causation_id' => $delivery->metadata->causationId?->value,
                'role' => $delivery->role->value,
                'message_name' => $delivery->payload->messageName,
                'payload_version' => $delivery->payload->version,
                'codec' => $delivery->payload->codec,
                'content_type' => $delivery->payload->contentType(),
                'object_graph_fingerprint' => $delivery->payload->objectGraphFingerprint,
                'payload' => base64_encode($delivery->payload->bytes),
                'payload_digest' => $payloadDigest,
                'metadata' => $metadata,
                'recorded_at' => $delivery->metadata->createdAt->format(DATE_ATOM),
            ]);
            $storedMessage = $this->prepare(<<<'SQL'
                SELECT correlation_id, causation_id, role, message_name, payload_version,
                       codec, content_type, object_graph_fingerprint, payload_digest,
                       encode(payload, 'base64') AS payload_base64, metadata
                FROM message_bus__messages
                WHERE message_id = :message_id
                SQL);
            $storedMessage->execute(['message_id' => $delivery->metadata->messageId->value]);
            $stored = $this->row($storedMessage);
            if ($stored === null
                || $this->string($stored, 'message_name') !== $delivery->payload->messageName
                || $this->string($stored, 'correlation_id') !== $delivery->metadata->correlationId->value
                || $this->nullableString($stored, 'causation_id') !== $delivery->metadata->causationId?->value
                || $this->string($stored, 'role') !== $delivery->role->value
                || $this->integer($stored, 'payload_version') !== $delivery->payload->version
                || $this->string($stored, 'codec') !== $delivery->payload->codec
                || $this->string($stored, 'content_type') !== $delivery->payload->contentType()
                || $this->nullableString($stored, 'object_graph_fingerprint')
                    !== $delivery->payload->objectGraphFingerprint
                || $this->string($stored, 'payload_digest') !== $payloadDigest
                || base64_decode($this->string($stored, 'payload_base64'), true) !== $delivery->payload->bytes
                || $this->jsonObject($this->string($stored, 'metadata'))
                    !== $this->jsonObject($metadata)) {
                throw new \LogicException('MessageId already exists with different canonical content.');
            }

            $statement = $this->prepare(<<<'SQL'
                INSERT INTO message_bus__deliveries
                    (delivery_id, message_id, target_id, handler_class, handler_method,
                     lane, policy, state, priority, replay_of)
                VALUES
                    (:delivery_id, :message_id, :target_id, :handler_class, :handler_method,
                     :lane, CAST(:policy AS jsonb), 'accepted', :priority, :replay_of)
                SQL);
            $statement->execute([
                'delivery_id' => $delivery->deliveryId->value,
                'message_id' => $delivery->metadata->messageId->value,
                'target_id' => $delivery->targetId,
                'handler_class' => $delivery->handlerClass,
                'handler_method' => $delivery->handlerMethod,
                'lane' => $delivery->lane,
                'policy' => $this->policyJson($delivery->policy),
                'priority' => $delivery->policy->priority,
                'replay_of' => $delivery->replayOf?->value,
            ]);

            return new DeliveryAcceptance(
                $delivery->deliveryId,
                $staged ? DeliveryState::Staged : DeliveryState::Accepted,
            );
        }, joinOuter: true);
    }

    public function claim(string $lane, string $workerId): ?ClaimedDelivery
    {
        return $this->transaction(function () use ($lane, $workerId): ?ClaimedDelivery {
            $statement = $this->prepare(<<<'SQL'
                SELECT d.*, m.message_name, m.payload_version, m.codec,
                       encode(m.payload, 'base64') AS payload_base64,
                       m.content_type, m.object_graph_fingerprint,
                       m.correlation_id, m.causation_id, m.role,
                       m.metadata, m.recorded_at AS message_recorded_at,
                       clock_timestamp() AS database_now
                FROM message_bus__deliveries d
                JOIN message_bus__messages m ON m.message_id = d.message_id
                WHERE d.lane = :lane
                  AND d.state IN ('accepted', 'retry_wait')
                  AND d.available_at <= clock_timestamp()
                  AND d.cancellation_requested = false
                ORDER BY d.priority DESC, d.available_at, d.enqueue_sequence
                FOR UPDATE OF d SKIP LOCKED
                LIMIT 1
                SQL);
            $statement->execute(['lane' => $lane]);
            $row = $this->row($statement);
            if ($row === null) {
                return null;
            }

            $attemptId = $this->identities->attemptId();
            $leaseToken = $this->identities->leaseToken();
            $policy = $this->policyFromJson($this->string($row, 'policy'));
            $attemptNumber = $this->integer($row, 'attempt_count') + 1;
            $attempt = $this->prepare(<<<'SQL'
                INSERT INTO message_bus__delivery_attempts
                    (attempt_id, delivery_id, attempt_number, worker_id, lease_token, lease_expires_at)
                VALUES
                    (:attempt_id, :delivery_id, :attempt_number, :worker_id, :lease_token,
                     clock_timestamp() + make_interval(secs => :lease_seconds))
                RETURNING started_at, lease_expires_at
                SQL);
            $attempt->execute([
                'attempt_id' => $attemptId->value,
                'delivery_id' => $this->string($row, 'delivery_id'),
                'attempt_number' => $attemptNumber,
                'worker_id' => $workerId,
                'lease_token' => $leaseToken->value,
                'lease_seconds' => $policy->leaseSeconds,
            ]);
            $attemptRow = $this->row($attempt)
                ?? throw new \RuntimeException('PostgreSQL did not return the inserted delivery attempt.');

            $update = $this->prepare(<<<'SQL'
                UPDATE message_bus__deliveries
                SET state = 'running', attempt_count = :attempt_number,
                    current_attempt_id = :attempt_id,
                    started_at = COALESCE(started_at, clock_timestamp()),
                    updated_at = clock_timestamp()
                WHERE delivery_id = :delivery_id
                SQL);
            $update->execute([
                'attempt_number' => $attemptNumber,
                'attempt_id' => $attemptId->value,
                'delivery_id' => $this->string($row, 'delivery_id'),
            ]);

            return new ClaimedDelivery(
                $this->draftFromRow($row, $policy),
                new DeliveryAttempt(
                    $attemptId,
                    new DeliveryId($this->string($row, 'delivery_id')),
                    $attemptNumber,
                    new \DateTimeImmutable($this->string($attemptRow, 'started_at')),
                ),
                $leaseToken,
                new \DateTimeImmutable($this->string($attemptRow, 'lease_expires_at')),
            );
        });
    }

    public function heartbeat(ClaimedDelivery $claim): bool
    {
        return $this->transaction(function () use ($claim): bool {
            $statement = $this->prepare(<<<'SQL'
                UPDATE message_bus__delivery_attempts a
                SET heartbeat_at = clock_timestamp(),
                    lease_expires_at = clock_timestamp() + make_interval(secs => :lease_seconds)
                FROM message_bus__deliveries d
                WHERE a.attempt_id = :attempt_id
                  AND a.delivery_id = :delivery_id
                  AND a.lease_token = :lease_token
                  AND a.finished_at IS NULL
                  AND d.delivery_id = a.delivery_id
                  AND d.current_attempt_id = a.attempt_id
                  AND d.state = 'running'
                SQL);
            $statement->execute($this->fence($claim) + [
                'lease_seconds' => $claim->delivery->policy->leaseSeconds,
            ]);

            return $statement->rowCount() === 1;
        });
    }

    public function cancellationRequested(ClaimedDelivery $claim): bool
    {
        $statement = $this->prepare(<<<'SQL'
            SELECT d.cancellation_requested
            FROM message_bus__deliveries d
            JOIN message_bus__delivery_attempts a ON a.attempt_id = d.current_attempt_id
            WHERE d.delivery_id = :delivery_id
              AND d.state = 'running'
              AND a.attempt_id = :attempt_id
              AND a.lease_token = :lease_token
              AND a.finished_at IS NULL
            SQL);
        $statement->execute($this->fence($claim));
        $value = $statement->fetchColumn();

        return \in_array($value, [true, 't', '1'], true);
    }

    public function markHandlerStarted(ClaimedDelivery $claim, ?int $childPid = null): bool
    {
        return $this->transaction(function () use ($claim, $childPid): bool {
            $statement = $this->prepare(<<<'SQL'
                UPDATE message_bus__delivery_attempts a
                SET handler_started_at = COALESCE(a.handler_started_at, clock_timestamp()),
                    child_pid = COALESCE(a.child_pid, :child_pid)
                FROM message_bus__deliveries d
                WHERE a.attempt_id = :attempt_id
                  AND a.delivery_id = :delivery_id
                  AND a.lease_token = :lease_token
                  AND a.finished_at IS NULL
                  AND d.delivery_id = a.delivery_id
                  AND d.current_attempt_id = a.attempt_id
                  AND d.state = 'running'
                SQL);
            $statement->bindValue('child_pid', $childPid, $childPid === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT);
            foreach ($this->fence($claim) as $name => $value) {
                $statement->bindValue($name, $value);
            }
            $statement->execute();

            return $statement->rowCount() === 1;
        });
    }

    public function succeed(ClaimedDelivery $claim): bool
    {
        return $this->settle($claim, DeliveryState::Succeeded, null, null);
    }

    public function fail(ClaimedDelivery $claim, FailureCategory $category, string $summary): bool
    {
        $next = match (true) {
            $category === FailureCategory::Cancelled => DeliveryState::Cancelled,
            $category === FailureCategory::OutcomeUnknown => DeliveryState::OutcomeUnknown,
            $category === FailureCategory::Retryable
                && $claim->attempt->number < $claim->delivery->policy->maxAttempts => DeliveryState::RetryWait,
            default => DeliveryState::Failed,
        };

        return $this->settle($claim, $next, $category, $summary);
    }

    public function status(DeliveryId $deliveryId): ?DeliveryStatus
    {
        $statement = $this->prepare(<<<'SQL'
            SELECT delivery_id, message_id, target_id, state, attempt_count,
                   updated_at, last_failure_category
            FROM message_bus__deliveries
            WHERE delivery_id = :delivery_id
            SQL);
        $statement->execute(['delivery_id' => $deliveryId->value]);
        $row = $this->row($statement);

        return $row === null ? null : $this->statusFromRow($row);
    }

    public function cancel(DeliveryId $deliveryId, ControlActor $actor): CancellationReceipt
    {
        return $this->transaction(function () use ($deliveryId, $actor): CancellationReceipt {
            $statement = $this->prepare(<<<'SQL'
                SELECT state, cancellation_requested
                FROM message_bus__deliveries
                WHERE delivery_id = :delivery_id
                FOR UPDATE
                SQL);
            $statement->execute(['delivery_id' => $deliveryId->value]);
            $row = $this->row($statement);
            if ($row === null) {
                return new CancellationReceipt($deliveryId, CancellationOutcome::NotFound, $this->databaseNow());
            }

            $state = DeliveryState::from($this->string($row, 'state'));
            $requested = $row['cancellation_requested'] === true || $row['cancellation_requested'] === 't';
            $outcome = match (true) {
                $state->isTerminal() => CancellationOutcome::AlreadyTerminal,
                $requested => CancellationOutcome::AlreadyRequested,
                $state === DeliveryState::Running => CancellationOutcome::RequestedRunning,
                default => CancellationOutcome::CancelledBeforeStart,
            };
            if (!$state->isTerminal() && !$requested) {
                $terminal = $outcome === CancellationOutcome::CancelledBeforeStart;
                $update = $this->prepare(<<<'SQL'
                    UPDATE message_bus__deliveries
                    SET cancellation_requested = true,
                        cancellation_requested_at = clock_timestamp(),
                        state = CASE WHEN :terminal THEN 'cancelled' ELSE state END,
                        finished_at = CASE WHEN :terminal THEN clock_timestamp() ELSE finished_at END,
                        updated_at = clock_timestamp()
                    WHERE delivery_id = :delivery_id
                    SQL);
                $update->bindValue('terminal', $terminal, \PDO::PARAM_BOOL);
                $update->bindValue('delivery_id', $deliveryId->value);
                $update->execute();
            }
            $this->controlOperation($deliveryId, 'cancel', $outcome->value, $actor);

            return new CancellationReceipt($deliveryId, $outcome, $this->databaseNow());
        });
    }

    public function recoverExpired(int $limit): MaintenanceReport
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Recovery limit must be positive.');
        }

        return $this->transaction(function () use ($limit): MaintenanceReport {
            $statement = $this->prepare(<<<'SQL'
                SELECT d.delivery_id, d.current_attempt_id, d.attempt_count, d.policy,
                       a.lease_token
                FROM message_bus__deliveries d
                JOIN message_bus__delivery_attempts a ON a.attempt_id = d.current_attempt_id
                WHERE d.state = 'running'
                  AND a.finished_at IS NULL
                  AND a.lease_expires_at < clock_timestamp()
                ORDER BY a.lease_expires_at, d.delivery_id
                FOR UPDATE OF d, a SKIP LOCKED
                LIMIT :limit
                SQL);
            $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
            $statement->execute();
            $rows = $this->rows($statement);
            $changed = 0;
            foreach ($rows as $row) {
                $policy = $this->policyFromJson($this->string($row, 'policy'));
                $state = $policy->retryLostAttempts && $this->integer($row, 'attempt_count') < $policy->maxAttempts
                    ? DeliveryState::RetryWait
                    : DeliveryState::OutcomeUnknown;
                $attempt = $this->prepare(<<<'SQL'
                    UPDATE message_bus__delivery_attempts
                    SET state = 'lost', finished_at = clock_timestamp(), lost_at = clock_timestamp(),
                        outcome = 'lost', failure_category = 'lost',
                        scheduling_decision = :scheduling_decision
                    WHERE attempt_id = :attempt_id AND lease_token = :lease_token AND finished_at IS NULL
                    SQL);
                $attempt->execute([
                    'attempt_id' => $this->string($row, 'current_attempt_id'),
                    'lease_token' => $this->string($row, 'lease_token'),
                    'scheduling_decision' => $state->value,
                ]);
                if ($attempt->rowCount() !== 1) {
                    continue;
                }
                $terminal = $state->isTerminal();
                $delivery = $this->prepare(<<<'SQL'
                    UPDATE message_bus__deliveries
                    SET state = :state, current_attempt_id = NULL,
                        available_at = clock_timestamp(), last_failure_category = 'lost',
                        updated_at = clock_timestamp(),
                        finished_at = CASE WHEN :terminal THEN clock_timestamp() ELSE NULL END
                    WHERE delivery_id = :delivery_id AND current_attempt_id = :attempt_id AND state = 'running'
                    SQL);
                $delivery->bindValue('state', $state->value);
                $delivery->bindValue('terminal', $terminal, \PDO::PARAM_BOOL);
                $delivery->bindValue('delivery_id', $this->string($row, 'delivery_id'));
                $delivery->bindValue('attempt_id', $this->string($row, 'current_attempt_id'));
                $delivery->execute();
                $changed += $delivery->rowCount();
            }

            return new MaintenanceReport(\count($rows), $changed, \count($rows) === $limit);
        });
    }

    public function replay(DeliveryId $deliveryId, DeliveryPolicy $policy, ControlActor $actor): ReplayReceipt
    {
        return $this->transaction(function () use ($deliveryId, $policy, $actor): ReplayReceipt {
            $statement = $this->prepare(<<<'SQL'
                SELECT * FROM message_bus__deliveries
                WHERE delivery_id = :delivery_id
                FOR SHARE
                SQL);
            $statement->execute(['delivery_id' => $deliveryId->value]);
            $row = $this->row($statement);
            if ($row === null) {
                throw new \LogicException('Delivery is not retained.');
            }
            $state = DeliveryState::from($this->string($row, 'state'));
            if (!$state->isTerminal() || $state === DeliveryState::Succeeded) {
                throw new \LogicException('Only unsuccessful terminal deliveries can be replayed.');
            }
            if ($state === DeliveryState::OutcomeUnknown && !$policy->idempotent) {
                throw new \LogicException('Outcome-unknown delivery requires an idempotent replay policy.');
            }
            if ($state === DeliveryState::OutcomeUnknown) {
                $originalPolicy = $this->policyFromJson($this->string($row, 'policy'));
                $acceptedAt = new \DateTimeImmutable($this->string($row, 'accepted_at'));
                if ($originalPolicy->replayWindowSeconds < 1
                    || $acceptedAt->modify('+' . $originalPolicy->replayWindowSeconds . ' seconds')
                        < $this->databaseNow()) {
                    throw new \LogicException('Outcome-unknown delivery is outside its persisted replay safety window.');
                }
            }

            $newId = $this->identities->deliveryId();
            $insert = $this->prepare(<<<'SQL'
                INSERT INTO message_bus__deliveries
                    (delivery_id, message_id, target_id, handler_class, handler_method,
                     lane, policy, state, priority, replay_of)
                VALUES
                    (:delivery_id, :message_id, :target_id, :handler_class, :handler_method,
                     :lane, CAST(:policy AS jsonb), 'accepted', :priority, :replay_of)
                SQL);
            $insert->execute([
                'delivery_id' => $newId->value,
                'message_id' => $this->string($row, 'message_id'),
                'target_id' => $this->string($row, 'target_id'),
                'handler_class' => $this->string($row, 'handler_class'),
                'handler_method' => $this->string($row, 'handler_method'),
                'lane' => $this->string($row, 'lane'),
                'policy' => $this->policyJson($policy),
                'priority' => $policy->priority,
                'replay_of' => $deliveryId->value,
            ]);
            $this->controlOperation($deliveryId, 'replay', 'accepted', $actor);

            return new ReplayReceipt($deliveryId, $newId, $policy);
        });
    }

    public function prune(\DateTimeImmutable $terminalBefore, int $limit, bool $execute): MaintenanceReport
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Prune limit must be positive.');
        }

        return $this->transaction(function () use ($terminalBefore, $limit, $execute): MaintenanceReport {
            $statement = $this->prepare(<<<'SQL'
                SELECT delivery_id
                FROM message_bus__deliveries
                WHERE state IN ('succeeded', 'failed', 'cancelled', 'outcome_unknown')
                  AND finished_at < :terminal_before
                ORDER BY finished_at, delivery_id
                FOR UPDATE SKIP LOCKED
                LIMIT :limit
                SQL);
            $statement->bindValue('terminal_before', $terminalBefore->format(DATE_ATOM));
            $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
            $statement->execute();
            $ids = $this->strings($statement);
            if ($execute && $ids !== []) {
                $placeholders = implode(', ', array_fill(0, \count($ids), '?'));
                $delete = $this->prepare(
                    'DELETE FROM message_bus__deliveries WHERE delivery_id IN (' . $placeholders . ')',
                );
                $delete->execute($ids);
                $this->pdo->exec(QualifiedSql::qualify(<<<'SQL'
                    DELETE FROM message_bus__messages m
                    WHERE NOT EXISTS (
                        SELECT 1 FROM message_bus__deliveries d WHERE d.message_id = m.message_id
                    )
                    SQL, $this->schema));
            }

            return new MaintenanceReport(\count($ids), $execute ? \count($ids) : 0, \count($ids) === $limit);
        });
    }

    private function settle(
        ClaimedDelivery $claim,
        DeliveryState $state,
        ?FailureCategory $category,
        ?string $summary,
    ): bool {
        return $this->transaction(function () use ($claim, $state, $category, $summary): bool {
            $attempt = $this->prepare(<<<'SQL'
                UPDATE message_bus__delivery_attempts
                SET state = :attempt_state, finished_at = clock_timestamp(), outcome = :outcome,
                    failure_category = :failure_category, failure_summary = :failure_summary
                WHERE attempt_id = :attempt_id
                  AND delivery_id = :delivery_id
                  AND lease_token = :lease_token
                  AND finished_at IS NULL
                SQL);
            $attempt->execute([
                'attempt_state' => match ($state) {
                    DeliveryState::Succeeded => 'succeeded',
                    DeliveryState::Cancelled => 'cancelled',
                    DeliveryState::OutcomeUnknown => 'outcome_unknown',
                    default => 'failed',
                },
                'outcome' => $state === DeliveryState::Succeeded ? 'succeeded' : 'failed',
                'failure_category' => $category?->value,
                'failure_summary' => $summary,
            ] + $this->fence($claim));
            if ($attempt->rowCount() !== 1) {
                return false;
            }

            $terminal = $state->isTerminal();
            $delivery = $this->prepare(<<<'SQL'
                UPDATE message_bus__deliveries
                SET state = :state, current_attempt_id = NULL,
                    available_at = CASE
                        WHEN :state = 'retry_wait'
                        THEN clock_timestamp() + make_interval(secs => :retry_delay)
                        ELSE available_at
                    END,
                    last_failure_category = :failure_category,
                    updated_at = clock_timestamp(),
                    finished_at = CASE WHEN :terminal THEN clock_timestamp() ELSE NULL END
                WHERE delivery_id = :delivery_id
                  AND current_attempt_id = :attempt_id
                  AND state = 'running'
                SQL);
            $delivery->bindValue('state', $state->value);
            $delivery->bindValue('retry_delay', $claim->delivery->policy->retryDelaySeconds, \PDO::PARAM_INT);
            $delivery->bindValue('failure_category', $category?->value);
            $delivery->bindValue('terminal', $terminal, \PDO::PARAM_BOOL);
            $delivery->bindValue('delivery_id', $claim->delivery->deliveryId->value);
            $delivery->bindValue('attempt_id', $claim->attempt->attemptId->value);
            $delivery->execute();

            return $delivery->rowCount() === 1;
        });
    }

    /** @param array<string, mixed> $row */
    private function draftFromRow(array $row, DeliveryPolicy $policy): DeliveryDraft
    {
        $metadata = $this->jsonObject($this->string($row, 'metadata'));
        $messageId = new MessageId($this->string($row, 'message_id'));
        $correlationId = $this->string($row, 'correlation_id');
        $causationId = $this->nullableString($row, 'causation_id');
        $application = $this->metadataApplication($metadata);
        $payload = base64_decode($this->string($row, 'payload_base64'), true);
        if (!\is_string($payload)) {
            throw new \UnexpectedValueException('PostgreSQL payload is not valid base64.');
        }

        return new DeliveryDraft(
            new DeliveryId($this->string($row, 'delivery_id')),
            new MessageMetadata(
                $messageId,
                new CorrelationId($correlationId),
                $causationId === null ? null : new MessageId($causationId),
                new \DateTimeImmutable($this->string($row, 'message_recorded_at')),
                $application,
            ),
            new EncodedPayload(
                $this->string($row, 'message_name'),
                $this->integer($row, 'payload_version'),
                $this->string($row, 'codec'),
                $payload,
                $this->nullableString($row, 'object_graph_fingerprint'),
            ),
            $this->string($row, 'target_id'),
            $this->string($row, 'handler_class'),
            $this->string($row, 'handler_method'),
            $this->string($row, 'lane'),
            $policy,
            ($replay = $this->nullableString($row, 'replay_of')) === null ? null : new DeliveryId($replay),
            MessageRole::from($this->string($row, 'role')),
        );
    }

    /** @param array<string, mixed> $row */
    private function statusFromRow(array $row): DeliveryStatus
    {
        return new DeliveryStatus(
            new DeliveryId($this->string($row, 'delivery_id')),
            new MessageId($this->string($row, 'message_id')),
            $this->string($row, 'target_id'),
            DeliveryState::from($this->string($row, 'state')),
            $this->integer($row, 'attempt_count'),
            new \DateTimeImmutable($this->string($row, 'updated_at')),
            $this->nullableString($row, 'last_failure_category'),
        );
    }

    /** @return array{attempt_id: string, delivery_id: string, lease_token: string} */
    private function fence(ClaimedDelivery $claim): array
    {
        return [
            'attempt_id' => $claim->attempt->attemptId->value,
            'delivery_id' => $claim->delivery->deliveryId->value,
            'lease_token' => $claim->leaseToken->value,
        ];
    }

    private function policyJson(DeliveryPolicy $policy): string
    {
        return json_encode([
            'name' => $policy->name,
            'max_attempts' => $policy->maxAttempts,
            'retry_delay_seconds' => $policy->retryDelaySeconds,
            'lease_seconds' => $policy->leaseSeconds,
            'max_elapsed_seconds' => $policy->maxElapsedSeconds,
            'priority' => $policy->priority,
            'idempotent' => $policy->idempotent,
            'idempotency_retention_seconds' => $policy->idempotencyRetentionSeconds,
            'replay_window_seconds' => $policy->replayWindowSeconds,
            'idempotency_safety_margin_seconds' => $policy->idempotencySafetyMarginSeconds,
            'retry_lost_attempts' => $policy->retryLostAttempts,
        ], JSON_THROW_ON_ERROR);
    }

    private function policyFromJson(string $json): DeliveryPolicy
    {
        $policy = $this->jsonObject($json);

        return new DeliveryPolicy(
            $this->string($policy, 'name'),
            $this->integer($policy, 'max_attempts'),
            $this->integer($policy, 'retry_delay_seconds'),
            $this->integer($policy, 'lease_seconds'),
            $this->integer($policy, 'max_elapsed_seconds'),
            $this->integer($policy, 'priority'),
            $this->boolean($policy, 'idempotent'),
            $this->integer($policy, 'idempotency_retention_seconds'),
            $this->integer($policy, 'replay_window_seconds'),
            $this->integer($policy, 'idempotency_safety_margin_seconds'),
            $this->boolean($policy, 'retry_lost_attempts'),
        );
    }

    private function controlOperation(
        DeliveryId $deliveryId,
        string $kind,
        string $outcome,
        ControlActor $actor,
    ): void {
        $statement = $this->prepare(<<<'SQL'
            INSERT INTO message_bus__delivery_operations
                (operation_id, delivery_id, idempotency_key, kind, actor, reason,
                 reference, initiator_metadata, outcome)
            VALUES
                (:operation_id, :delivery_id, :idempotency_key, :kind, :actor, :reason,
                 :reference, CAST(:initiator_metadata AS jsonb), :outcome)
            SQL);
        $statement->execute([
            'operation_id' => $actor->operationId ?? $this->identities->attemptId()->value,
            'delivery_id' => $deliveryId->value,
            'idempotency_key' => $actor->idempotencyKey,
            'kind' => $kind,
            'actor' => $actor->actor,
            'reason' => $actor->reason,
            'reference' => $actor->reference,
            'initiator_metadata' => json_encode($actor->metadata, JSON_THROW_ON_ERROR),
            'outcome' => $outcome,
        ]);
    }

    private function databaseNow(): \DateTimeImmutable
    {
        $statement = $this->pdo->query('SELECT clock_timestamp()');
        if (!$statement instanceof \PDOStatement) {
            throw new \RuntimeException('Unable to read PostgreSQL database time.');
        }
        $value = $statement->fetchColumn();
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('PostgreSQL database time must be a string.');
        }

        return new \DateTimeImmutable($value);
    }

    private function prepare(string $sql): \PDOStatement
    {
        return $this->pdo->prepare(QualifiedSql::qualify($sql, $this->schema));
    }

    /** @return array<string, mixed>|null */
    private function row(\PDOStatement $statement): ?array
    {
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (!\is_array($row)) {
            throw new \UnexpectedValueException('PostgreSQL row must be an array.');
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function rows(\PDOStatement $statement): array
    {
        $values = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $rows = [];
        foreach ($values as $value) {
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

    /** @return list<string> */
    private function strings(\PDOStatement $statement): array
    {
        $values = $statement->fetchAll(\PDO::FETCH_COLUMN);
        $strings = [];
        foreach ($values as $value) {
            if (!\is_string($value)) {
                throw new \UnexpectedValueException('PostgreSQL column must be a string.');
            }
            $strings[] = $value;
        }

        return $strings;
    }

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value) && !\is_int($value)) {
            throw new \UnexpectedValueException('PostgreSQL field must be string-compatible: ' . $key);
        }

        return (string) $value;
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

    /** @param array<string, mixed> $row */
    private function boolean(array $row, string $key): bool
    {
        $value = $row[$key] ?? null;
        if (\is_bool($value)) {
            return $value;
        }
        if ($value === 't' || $value === '1') {
            return true;
        }
        if ($value === 'f' || $value === '0') {
            return false;
        }

        throw new \UnexpectedValueException('PostgreSQL field must be boolean: ' . $key);
    }

    /** @return array<string, mixed> */
    private function jsonObject(string $json): array
    {
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$decoded instanceof \stdClass) {
            throw new \UnexpectedValueException('PostgreSQL JSON value must be an object.');
        }
        $value = get_object_vars($decoded);
        $object = [];
        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('PostgreSQL JSON object has a non-string key.');
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, bool|float|int|string|null>
     */
    private function metadataApplication(array $metadata): array
    {
        $result = [];
        foreach ($metadata as $key => $value) {
            if (!\is_string($key)
                || (!\is_bool($value) && !\is_float($value) && !\is_int($value) && !\is_string($value) && $value !== null)) {
                throw new \UnexpectedValueException('Persisted application metadata contains an invalid field.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @template TResult
     * @param callable(): TResult $operation
     * @return TResult
     */
    private function transaction(callable $operation, bool $joinOuter = false): mixed
    {
        $owns = !$this->pdo->inTransaction();
        if (!$owns && !$joinOuter) {
            throw new \LogicException('Runtime PostgreSQL operations require a connection without an open host transaction.');
        }
        if ($owns) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT message_bus_accept');
        }

        try {
            $result = $operation();
            if ($owns) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec('RELEASE SAVEPOINT message_bus_accept');
            }

            return $result;
        } catch (\Throwable $failure) {
            if ($owns && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            } elseif (!$owns && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK TO SAVEPOINT message_bus_accept');
                $this->pdo->exec('RELEASE SAVEPOINT message_bus_accept');
            }

            throw $failure;
        }
    }
}
