<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use Wolfcharaa\MessageBus\Contract\Message;
use Wolfcharaa\MessageBus\Delivery\MaintenanceReport;
use Wolfcharaa\MessageBus\Identity\DeliveryId;

/**
 * Normalized application idempotency key; persistence stores only its digest.
 *
 * @api application
 */
final readonly class IdempotencyKey
{
    public function __construct(public string $value)
    {
        if ($this->value === '' || \strlen($this->value) > 512) {
            throw new \InvalidArgumentException('Idempotency key must contain 1..512 bytes.');
        }
    }

    public function digest(): string
    {
        return hash('sha256', $this->value);
    }
}

/**
 * Versioned digest of canonical business intent.
 *
 * @api application
 */
final readonly class IntentFingerprint
{
    public function __construct(
        public string $algorithm,
        public int $version,
        public string $digest,
    ) {
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $this->algorithm) !== 1) {
            throw new \InvalidArgumentException(
                'IntentFingerprint algorithm must match [a-z][a-z0-9._-]{1,31}.',
            );
        }
        if ($this->version < 1) {
            throw new \InvalidArgumentException('IntentFingerprint version must be at least 1.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->digest) !== 1) {
            throw new \InvalidArgumentException('IntentFingerprint digest must be a lowercase SHA-256 digest.');
        }
    }
}

/**
 * Stable target-scoped idempotency identity and intent fingerprint.
 *
 * @api application
 */
final readonly class IdempotencyIdentity
{
    public function __construct(
        public string $targetId,
        public IdempotencyKey $key,
        public IntentFingerprint $fingerprint,
    ) {
        if ($this->targetId === '') {
            throw new \InvalidArgumentException('IdempotencyIdentity targetId must not be empty.');
        }
    }
}

/**
 * One retained target execution protected by the atomic boundary.
 *
 * @api application
 */
final readonly class IdempotencyAttempt
{
    public function __construct(
        public IdempotencyIdentity $identity,
        public DeliveryId $deliveryId,
        public \DateTimeImmutable $retainUntil,
    ) {}
}

/**
 *  Resolves application identity only for explicitly idempotent Command/Event targets.
 *
 * @api spi
 */
interface IdempotencyIdentityResolver
{
    /** @param Message<mixed> $message */
    public function resolve(Message $message, string $targetId): IdempotencyIdentity;
}

/**
 * One exact target-to-resolver declaration used by registry validation.
 *
 * @api application
 */
final readonly class TargetIdempotencyResolver
{
    public function __construct(
        public string $targetId,
        public IdempotencyIdentityResolver $resolver,
    ) {
        if ($this->targetId === '') {
            throw new \InvalidArgumentException('Idempotency resolver target must not be empty.');
        }
    }
}

/**
 * Complete target-scoped resolver map; duplicate declarations fail closed.
 *
 * @api application
 */
final readonly class IdempotencyIdentityResolvers
{
    /** @var array<string, IdempotencyIdentityResolver> */
    private array $resolvers;

    /** @param list<TargetIdempotencyResolver> $resolvers */
    public function __construct(array $resolvers)
    {
        $map = [];
        foreach ($resolvers as $declaration) {
            if (isset($map[$declaration->targetId])) {
                throw new \InvalidArgumentException(
                    'Duplicate idempotency resolver for target ' . $declaration->targetId . '.',
                );
            }
            $map[$declaration->targetId] = $declaration->resolver;
        }
        $this->resolvers = $map;
    }

    public function has(string $targetId): bool
    {
        return isset($this->resolvers[$targetId]);
    }

    /** @return list<string> */
    public function targetIds(): array
    {
        $targets = array_keys($this->resolvers);
        sort($targets, SORT_STRING);

        return $targets;
    }

    /** @param Message<mixed> $message */
    public function resolve(Message $message, string $targetId): IdempotencyIdentity
    {
        $resolver = $this->resolvers[$targetId]
            ?? throw new \LogicException('No idempotency resolver is registered for target ' . $targetId . '.');

        return $resolver->resolve($message, $targetId);
    }
}

/**
 *  Atomic idempotency outcome.
 *
 * @api application
 */
enum IdempotencyOutcome: string
{
    case Executed = 'executed';
    case Replayed = 'replayed';
}

/**
 *  Result of an atomic target effect.
 *
 * @api application
 */
final readonly class IdempotencyResult
{
    public function __construct(public IdempotencyOutcome $outcome) {}
}

/**
 *  Transaction boundary coupling idempotency claim, domain effect and completion.
 *
 * @api spi
 */
interface AtomicIdempotencyBoundary
{
    /** @param callable(): void $effect */
    public function execute(
        IdempotencyAttempt $attempt,
        callable $effect,
    ): IdempotencyResult;
}

/**
 * Bounded retention for completed records whose safety horizon has expired.
 *
 * @api application
 */
interface IdempotencyMaintenance
{
    public function pruneExpired(\DateTimeImmutable $at, int $limit, bool $execute): MaintenanceReport;
}

/**
 *  Complete runtime capability enabled only for targets declared idempotent.
 *
 * @api application
 */
final readonly class IdempotencyRuntime
{
    public function __construct(
        public IdempotencyIdentityResolvers $identities,
        public AtomicIdempotencyBoundary $boundary,
    ) {}
}

namespace Wolfcharaa\MessageBus\Adapter\Postgres;

use Wolfcharaa\MessageBus\Delivery\MaintenanceReport;
use Wolfcharaa\MessageBus\Idempotency\AtomicIdempotencyBoundary;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyAttempt;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyMaintenance;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyOutcome;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyResult;
use Wolfcharaa\MessageBus\Internal\Postgres\QualifiedSql;
use Wolfcharaa\MessageBus\Internal\Runtime\FailurePreservingCleanup;

/**
 *  PostgreSQL atomic idempotency boundary sharing the application's PDO transaction.
 *
 * @api adapter
 */
final readonly class PostgresAtomicIdempotencyBoundary implements AtomicIdempotencyBoundary
{
    public function __construct(private \PDO $pdo, private string $schema = 'public')
    {
        QualifiedSql::validateSchema($this->schema);
    }

    public function execute(
        IdempotencyAttempt $attempt,
        callable $effect,
    ): IdempotencyResult {
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Atomic idempotency boundary must own its outer transaction.');
        }
        $this->pdo->beginTransaction();

        try {
            $claim = $this->prepare(<<<'SQL'
                INSERT INTO message_bus__idempotency_records
                    (target_id, key_digest, fingerprint_algorithm, fingerprint_version,
                     fingerprint_digest, delivery_id, state, retain_until)
                VALUES (:target_id, :key_digest, :fingerprint_algorithm, :fingerprint_version,
                        :fingerprint_digest, :delivery_id, 'claimed', :retain_until)
                ON CONFLICT (target_id, key_digest) DO NOTHING
                SQL);
            $claim->execute([
                'target_id' => $attempt->identity->targetId,
                'key_digest' => $attempt->identity->key->digest(),
                'fingerprint_algorithm' => $attempt->identity->fingerprint->algorithm,
                'fingerprint_version' => $attempt->identity->fingerprint->version,
                'fingerprint_digest' => $attempt->identity->fingerprint->digest,
                'delivery_id' => $attempt->deliveryId->value,
                'retain_until' => $attempt->retainUntil->format(DATE_ATOM),
            ]);

            $statement = $this->prepare(<<<'SQL'
                SELECT fingerprint_algorithm, fingerprint_version, fingerprint_digest, state
                FROM message_bus__idempotency_records
                WHERE target_id = :target_id AND key_digest = :key_digest
                FOR UPDATE
                SQL);
            $statement->execute([
                'target_id' => $attempt->identity->targetId,
                'key_digest' => $attempt->identity->key->digest(),
            ]);
            $existing = $statement->fetch(\PDO::FETCH_ASSOC);
            if (\is_array($existing)) {
                if ($this->string($existing, 'fingerprint_algorithm') !== $attempt->identity->fingerprint->algorithm
                    || $this->integer($existing, 'fingerprint_version') !== $attempt->identity->fingerprint->version
                    || $this->string($existing, 'fingerprint_digest') !== $attempt->identity->fingerprint->digest) {
                    throw new \LogicException('Idempotency key conflicts with a different intent.');
                }
                if ($this->string($existing, 'state') === 'completed') {
                    $extend = $this->prepare(<<<'SQL'
                        UPDATE message_bus__idempotency_records
                        SET retain_until = GREATEST(retain_until, :retain_until)
                        WHERE target_id = :target_id AND key_digest = :key_digest
                          AND state = 'completed'
                        SQL);
                    $extend->execute([
                        'target_id' => $attempt->identity->targetId,
                        'key_digest' => $attempt->identity->key->digest(),
                        'retain_until' => $attempt->retainUntil->format(DATE_ATOM),
                    ]);
                    $this->pdo->commit();

                    return new IdempotencyResult(IdempotencyOutcome::Replayed);
                }
                if ($claim->rowCount() !== 1) {
                    throw new \LogicException('Idempotency execution is already in progress.');
                }
            } elseif ($claim->rowCount() !== 1) {
                throw new \RuntimeException('Idempotency claim could not be loaded.');
            }

            $effect();
            $complete = $this->prepare(<<<'SQL'
                UPDATE message_bus__idempotency_records
                SET state = 'completed', completed_at = clock_timestamp()
                WHERE target_id = :target_id AND key_digest = :key_digest AND state = 'claimed'
                SQL);
            $complete->execute([
                'target_id' => $attempt->identity->targetId,
                'key_digest' => $attempt->identity->key->digest(),
            ]);
            if ($complete->rowCount() !== 1) {
                throw new \RuntimeException('Idempotency completion lost its claim.');
            }
            $this->pdo->commit();

            return new IdempotencyResult(IdempotencyOutcome::Executed);
        } catch (\Throwable $failure) {
            FailurePreservingCleanup::rethrowAfter(
                $failure,
                'PostgreSQL idempotency boundary rollback',
                $this->rollBackOpenTransaction(...),
            );
        }
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

    /** @param array<string, mixed> $row */
    private function string(array $row, string $key): string
    {
        $value = $row[$key] ?? null;
        if (!\is_string($value)) {
            throw new \UnexpectedValueException('PostgreSQL row field is not a string: ' . $key);
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function integer(array $row, string $key): int
    {
        $value = $row[$key] ?? null;
        if (!\is_int($value) && (!\is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1)) {
            throw new \UnexpectedValueException('PostgreSQL row field is not an integer: ' . $key);
        }

        return (int) $value;
    }
}

/**
 * PostgreSQL bounded retention adapter; dry-run is selected by the caller.
 *
 * @api adapter
 */
final readonly class PostgresIdempotencyMaintenance implements IdempotencyMaintenance
{
    public function __construct(private \PDO $pdo, private string $schema = 'public')
    {
        QualifiedSql::validateSchema($this->schema);
    }

    public function pruneExpired(\DateTimeImmutable $at, int $limit, bool $execute): MaintenanceReport
    {
        if ($limit < 1) {
            throw new \InvalidArgumentException('Idempotency prune limit must be positive.');
        }
        if ($this->pdo->inTransaction()) {
            throw new \LogicException('Idempotency maintenance requires a connection without an open transaction.');
        }
        $this->pdo->beginTransaction();

        try {
            $statement = $this->pdo->prepare(QualifiedSql::qualify(<<<'SQL'
                SELECT target_id, key_digest
                FROM message_bus__idempotency_records
                WHERE state = 'completed' AND retain_until < :at
                ORDER BY retain_until, target_id, key_digest
                FOR UPDATE SKIP LOCKED
                LIMIT :limit
                SQL, $this->schema));
            $statement->bindValue('at', $at->format(DATE_ATOM));
            $statement->bindValue('limit', $limit, \PDO::PARAM_INT);
            $statement->execute();
            $records = $statement->fetchAll(\PDO::FETCH_ASSOC);
            if ($execute) {
                $delete = $this->pdo->prepare(QualifiedSql::qualify(<<<'SQL'
                    DELETE FROM message_bus__idempotency_records
                    WHERE target_id = :target_id AND key_digest = :key_digest
                      AND state = 'completed' AND retain_until < :at
                    SQL, $this->schema));
                foreach ($records as $index => $record) {
                    if (!\is_array($record)
                        || !\is_string($record['target_id'] ?? null)
                        || !\is_string($record['key_digest'] ?? null)) {
                        throw new \UnexpectedValueException(
                            'PostgreSQL idempotency record at index ' . $index
                                . ' requires string target_id and key_digest fields.',
                        );
                    }
                    $delete->execute([
                        'target_id' => $record['target_id'],
                        'key_digest' => $record['key_digest'],
                        'at' => $at->format(DATE_ATOM),
                    ]);
                }
            }
            $this->pdo->commit();

            return new MaintenanceReport(
                \count($records),
                $execute ? \count($records) : 0,
                \count($records) === $limit,
            );
        } catch (\Throwable $failure) {
            FailurePreservingCleanup::rethrowAfter(
                $failure,
                'PostgreSQL idempotency maintenance rollback',
                $this->rollBackOpenTransaction(...),
            );
        }
    }

    private function rollBackOpenTransaction(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}
