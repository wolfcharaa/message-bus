<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tooling\Migration;

use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresDeliveryBackend;
use Wolfcharaa\MessageBus\Codec\MessageCodecs;
use Wolfcharaa\MessageBus\Contract\Message;
use Wolfcharaa\MessageBus\Delivery\DeliveryDraft;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryState;
use Wolfcharaa\MessageBus\Identity\CorrelationId;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\IdentityGenerator;
use Wolfcharaa\MessageBus\Identity\MessageId;
use Wolfcharaa\MessageBus\Internal\Postgres\QualifiedSql;
use Wolfcharaa\MessageBus\Metadata\MessageMetadata;
use Wolfcharaa\MessageBus\Registry\CompiledRegistry;
use Wolfcharaa\MessageBus\Registry\HandlerBinding;

/**
 *  Explicit legacy decoder for one v6 wire name.
 *
 * @api tooling
 */
final readonly class V6MessageMigration
{
    /** @var \Closure(string, string): Message<mixed> */
    public \Closure $decode;

    /** @param callable(string, string): Message<mixed> $decode Payload and content type. */
    public function __construct(callable $decode)
    {
        $this->decode = \Closure::fromCallable($decode);
    }
}

/**
 *  Explicit legacy binding to current target/lane/policy mapping.
 *
 * @api tooling
 */
final readonly class V6TargetMigration
{
    public function __construct(
        public string $targetId,
        public string $lane,
        public string $policy,
    ) {
        if ($this->targetId === '' || $this->lane === '' || $this->policy === '') {
            throw new \InvalidArgumentException('V6 target migration values must not be empty.');
        }
    }
}

/**
 *  Complete fail-closed migration map; no legacy name or binding fallback exists.
 *
 * @api tooling
 */
final readonly class V6ImportConfiguration
{
    /**
     * @param array<string, V6MessageMigration> $messages legacy MessageName to decoder
     * @param array<string, V6TargetMigration> $targets legacy binding ID to current target
     */
    public function __construct(
        private array $messages,
        private array $targets,
    ) {}

    public function message(string $legacyName): V6MessageMigration
    {
        return $this->messages[$legacyName]
            ?? throw new \LogicException('No v6 message migration for ' . $legacyName . '.');
    }

    public function target(string $legacyBindingId): V6TargetMigration
    {
        return $this->targets[$legacyBindingId]
            ?? throw new \LogicException('No v6 target migration for ' . $legacyBindingId . '.');
    }
}

/**
 *  One bounded offline import result.
 *
 * @api tooling
 */
final readonly class V6ImportReport
{
    public function __construct(
        public int $examined,
        public int $imported,
        public int $alreadyImported,
        public bool $hasMore,
        public bool $dryRun,
    ) {}
}

/**
 *  Row-specific fail-closed importer diagnostic.
 *
 * @api tooling
 */
final class V6ImportFailed extends \RuntimeException
{
    public function __construct(public readonly string $legacyQueueJobId, \Throwable $previous)
    {
        parent::__construct(
            'V6 queue row ' . $legacyQueueJobId . ' cannot be imported: ' . $previous->getMessage(),
            0,
            $previous,
        );
    }
}

/**
 *  Offline standard-v6 importer; it never deletes or dual-writes the legacy table.
 *
 * @api tooling
 */
final readonly class V6QueueImporter
{
    public function __construct(
        private \PDO $pdo,
        private PostgresDeliveryBackend $backend,
        private CompiledRegistry $registry,
        private MessageCodecs $codecs,
        private DeliveryPolicies $policies,
        private IdentityGenerator $identities,
        private V6ImportConfiguration $configuration,
        private string $schema = 'public',
    ) {
        QualifiedSql::validateSchema($this->schema);
    }

    public function import(
        \DateTimeImmutable $terminalCutoff,
        int $limit = 100,
        bool $execute = false,
    ): V6ImportReport {
        if ($limit < 1 || $limit > 10_000) {
            throw new \InvalidArgumentException('V6 import limit must be between 1 and 10000.');
        }
        $this->assertOffline();
        $statement = $this->prepare(<<<'SQL'
            SELECT *
            FROM message_bus__queue_jobs
            WHERE status = 'pending' OR updated_at >= :terminal_cutoff
            ORDER BY id
            LIMIT :limit
            SQL);
        $statement->bindValue('terminal_cutoff', $terminalCutoff->format(DATE_ATOM));
        $statement->bindValue('limit', $limit + 1, \PDO::PARAM_INT);
        $statement->execute();
        $rows = $this->rows($statement);
        $hasMore = \count($rows) > $limit;
        $rows = \array_slice($rows, 0, $limit);
        $imported = 0;
        $already = 0;

        foreach ($rows as $row) {
            $legacyId = $this->string($row, 'id');

            try {
                if ($this->existing($legacyId) instanceof DeliveryId) {
                    ++$already;

                    continue;
                }
                $draft = $this->draft($row);
                if (!$execute) {
                    ++$imported;

                    continue;
                }
                $this->pdo->beginTransaction();

                try {
                    $this->backend->accept($draft);
                    $this->restoreState($draft->deliveryId, $row);
                    $mapping = $this->prepare(<<<'SQL'
                        INSERT INTO message_bus__legacy_delivery_map (legacy_queue_job_id, delivery_id)
                        VALUES (:legacy_id, :delivery_id)
                        SQL);
                    $mapping->execute([
                        'legacy_id' => $legacyId,
                        'delivery_id' => $draft->deliveryId->value,
                    ]);
                    $this->pdo->commit();
                    ++$imported;
                } catch (\Throwable $failure) {
                    if ($this->pdo->inTransaction()) {
                        $this->pdo->rollBack();
                    }

                    throw $failure;
                }
            } catch (\Throwable $failure) {
                throw new V6ImportFailed($legacyId, $failure);
            }
        }

        return new V6ImportReport(\count($rows), $imported, $already, $hasMore, !$execute);
    }

    private function assertOffline(): void
    {
        $statement = $this->prepare(
            "SELECT count(*) FROM message_bus__queue_jobs WHERE status = 'running'",
        );
        $statement->execute();
        if ((int) $statement->fetchColumn() !== 0) {
            throw new \LogicException('V6 import is blocked while running legacy jobs exist.');
        }
    }

    /** @param array<string, mixed> $row */
    private function draft(array $row): DeliveryDraft
    {
        $envelope = $this->jsonObject($row['serialized_envelope'] ?? null, 'serialized_envelope');
        $wire = $this->objectField($envelope, 'message');
        $legacyName = $this->string($wire, 'name');
        $contentType = $this->eitherString($wire, 'contentType', 'content_type');
        $payload = $this->string($wire, 'payload');
        $encoding = $this->eitherString($wire, 'payloadEncoding', 'payload_encoding', 'plain');
        if ($encoding === 'base64') {
            $decoded = base64_decode($payload, true);
            if (!\is_string($decoded)) {
                throw new \UnexpectedValueException('Legacy payload has invalid base64 encoding.');
            }
            $payload = $decoded;
        } elseif ($encoding !== 'plain') {
            throw new \UnexpectedValueException('Legacy payload encoding is unsupported: ' . $encoding);
        }
        $message = ($this->configuration->message($legacyName)->decode)($payload, $contentType);
        $canonical = $this->codecs->encode($message);
        $target = $this->configuration->target($this->string($row, 'binding_id'));
        $binding = $this->binding($message, $target->targetId);

        $legacyMessageId = $this->string($row, 'message_id');
        $legacyCorrelationId = $this->string($row, 'correlation_id');
        $causation = $envelope['causationId'] ?? $envelope['causation_id'] ?? null;
        $createdAt = $this->eitherString(
            $envelope,
            'createdAt',
            'created_at',
            $this->string($row, 'created_at'),
        );

        return new DeliveryDraft(
            $this->identities->deliveryId(),
            new MessageMetadata(
                new MessageId($this->opaque('legacy-msg', $legacyMessageId)),
                new CorrelationId($this->opaque('legacy-cor', $legacyCorrelationId)),
                \is_string($causation) && $causation !== ''
                    ? new MessageId($this->opaque('legacy-msg', $causation))
                    : null,
                new \DateTimeImmutable($createdAt),
                [
                    'legacy_history_incomplete' => true,
                ],
            ),
            $canonical,
            $binding->id,
            $binding->handlerClass,
            $binding->method,
            $target->lane,
            $this->policies->get($target->policy),
            role: $binding->role,
        );
    }

    /** @param array<string, mixed> $row */
    private function restoreState(DeliveryId $deliveryId, array $row): void
    {
        $attempts = $this->integer($row, 'attempts');
        $legacyState = $this->string($row, 'status');
        $state = match ($legacyState) {
            'pending' => $attempts === 0 ? DeliveryState::Accepted : DeliveryState::RetryWait,
            'succeeded' => DeliveryState::Succeeded,
            'failed' => DeliveryState::Failed,
            'cancelled' => DeliveryState::Cancelled,
            'interrupted' => DeliveryState::OutcomeUnknown,
            default => throw new \LogicException('Unsupported legacy state: ' . $legacyState),
        };
        $statement = $this->prepare(<<<'SQL'
            UPDATE message_bus__deliveries
            SET state = :state,
                attempt_count = :attempt_count,
                available_at = :available_at,
                cancellation_requested = :cancellation_requested,
                last_failure_category = :last_failure_category,
                accepted_at = :accepted_at,
                updated_at = :updated_at,
                finished_at = :finished_at
            WHERE delivery_id = :delivery_id
            SQL);
        $statement->execute([
            'state' => $state->value,
            'attempt_count' => $attempts,
            'available_at' => $this->string($row, 'available_at'),
            'cancellation_requested' => $this->postgresBool($row['cancellation_requested'] ?? false),
            'last_failure_category' => ($row['last_error'] ?? null) === null ? null : 'legacy',
            'accepted_at' => $this->string($row, 'created_at'),
            'updated_at' => $this->string($row, 'updated_at'),
            'finished_at' => $state->isTerminal()
                ? $this->nullableString($row, 'finished_at') ?? $this->string($row, 'updated_at')
                : null,
            'delivery_id' => $deliveryId->value,
        ]);
    }

    /** @param Message<mixed> $message */
    private function binding(Message $message, string $targetId): HandlerBinding
    {
        foreach ($this->registry->handlersFor($message) as $binding) {
            if ($binding->id === $targetId) {
                return $binding;
            }
        }

        throw new \LogicException('Current registry has no mapped target ' . $targetId . '.');
    }

    private function existing(string $legacyId): ?DeliveryId
    {
        $statement = $this->prepare(<<<'SQL'
            SELECT delivery_id FROM message_bus__legacy_delivery_map
            WHERE legacy_queue_job_id = :legacy_id
            SQL);
        $statement->execute(['legacy_id' => $legacyId]);
        $value = $statement->fetchColumn();

        return \is_string($value) ? new DeliveryId($value) : null;
    }

    /** @return array<string, mixed> */
    private function jsonObject(mixed $value, string $field): array
    {
        $decoded = \is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;
        if (!\is_array($decoded) || array_is_list($decoded)) {
            throw new \UnexpectedValueException('Legacy ' . $field . ' must be a JSON object.');
        }
        $object = [];
        foreach ($decoded as $key => $item) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('Legacy ' . $field . ' has a non-string key.');
            }
            $object[$key] = $item;
        }

        return $object;
    }

    /**
     * @param array<string, mixed> $value
     * @return array<string, mixed>
     */
    private function objectField(array $value, string $field): array
    {
        $fieldValue = $value[$field] ?? null;
        if (!\is_array($fieldValue) || array_is_list($fieldValue)) {
            throw new \UnexpectedValueException('Legacy envelope has no message object.');
        }
        $object = [];
        foreach ($fieldValue as $key => $item) {
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('Legacy envelope message has a non-string key.');
            }
            $object[$key] = $item;
        }

        return $object;
    }

    private function opaque(string $prefix, string $legacy): string
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}$/D', $legacy) === 1) {
            return $legacy;
        }

        return $prefix . ':' . hash('sha256', $legacy);
    }

    private function postgresBool(mixed $value): bool
    {
        return \in_array($value, [true, 1, '1', 't', 'true'], true);
    }

    private function prepare(string $sql): \PDOStatement
    {
        return $this->pdo->prepare(QualifiedSql::qualify($sql, $this->schema));
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
    private function eitherString(
        array $row,
        string $first,
        string $second,
        ?string $default = null,
    ): string {
        $value = $row[$first] ?? $row[$second] ?? $default;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('Legacy field must be a string: ' . $first);
        }

        return $value;
    }
}
