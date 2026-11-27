<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresAtomicIdempotencyBoundary;
use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresDeliveryBackend;
use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresIdempotencyMaintenance;
use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresSchemaManager;
use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresWorkerControl;
use Wolfcharaa\MessageBus\Codec\EncodedPayload;
use Wolfcharaa\MessageBus\Delivery\CancellationOutcome;
use Wolfcharaa\MessageBus\Delivery\ControlActor;
use Wolfcharaa\MessageBus\Delivery\DeliveryDraft;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\Delivery\DeliveryState;
use Wolfcharaa\MessageBus\Delivery\FailureCategory;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyAttempt;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentity;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyKey;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyOutcome;
use Wolfcharaa\MessageBus\Idempotency\IntentFingerprint;
use Wolfcharaa\MessageBus\Identity\CorrelationId;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\MessageId;
use Wolfcharaa\MessageBus\Metadata\MessageMetadata;
use Wolfcharaa\MessageBus\Observability\FailureDiagnostic;
use Wolfcharaa\MessageBus\Observability\FailureStage;
use Wolfcharaa\MessageBus\Worker\WorkerControlOutcome;
use Wolfcharaa\MessageBus\Worker\WorkerControlScope;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredState;
use Wolfcharaa\MessageBus\Worker\WorkerStatus;
use Wolfcharaa\MessageBus\Worker\WorkerStatusFilter;

#[Group('integration')]
final class PostgresBackendIntegrationTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        if (!\extension_loaded('pdo_pgsql')) {
            self::markTestSkipped('ext-pdo_pgsql is unavailable.');
        }
        $this->pdo = new \PDO(
            (string) getenv('MESSAGE_BUS_TEST_PGSQL_DSN'),
            (string) getenv('MESSAGE_BUS_TEST_PGSQL_USER'),
            (string) getenv('MESSAGE_BUS_TEST_PGSQL_PASSWORD'),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $this->pdo->exec(<<<'SQL'
            DROP TABLE IF EXISTS message_bus_test_effects CASCADE;
            DROP TABLE IF EXISTS message_bus__idempotency_records CASCADE;
            DROP TABLE IF EXISTS message_bus__worker_instances CASCADE;
            DROP TABLE IF EXISTS message_bus__worker_control_operations CASCADE;
            DROP TABLE IF EXISTS message_bus__worker_desired_states CASCADE;
            DROP TABLE IF EXISTS message_bus__legacy_delivery_map CASCADE;
            DROP TABLE IF EXISTS message_bus__delivery_operations CASCADE;
            DROP TABLE IF EXISTS message_bus__delivery_attempts CASCADE;
            DROP TABLE IF EXISTS message_bus__deliveries CASCADE;
            DROP TABLE IF EXISTS message_bus__messages CASCADE;
            DROP TABLE IF EXISTS message_bus__schema_migrations CASCADE;
            SQL);
    }

    public function testManifestMigrationAndFencedLifecycle(): void
    {
        $schema = new PostgresSchemaManager($this->pdo);
        $schema->migrate();
        $schema->migrate();
        $schema->validate();
        $schema->validateCatalog();
        $migrations = $this->pdo->query(
            'SELECT count(*) FROM message_bus__schema_migrations',
        );
        self::assertInstanceOf(\PDOStatement::class, $migrations);
        self::assertSame('2', (string) $migrations->fetchColumn());

        $identities = new SequenceIdentities();
        $backend = new PostgresDeliveryBackend($this->pdo, $identities);
        $deliveryId = new DeliveryId('delivery:postgres-0001');
        $policy = new DeliveryPolicy(
            'integration',
            maxAttempts: 2,
            retryDelaySeconds: 0,
            leaseSeconds: 30,
            idempotent: true,
            retryLostAttempts: true,
        );
        $acceptance = $backend->accept(new DeliveryDraft(
            $deliveryId,
            new MessageMetadata(
                new MessageId('message:postgres-0001'),
                new CorrelationId('correlation:postgres-0001'),
                null,
                new \DateTimeImmutable('2026-11-22T10:00:00+00:00'),
                ['tenant' => 'one'],
            ),
            new EncodedPayload('test.command', 1, 'json', '{"value":1}'),
            'test.command',
            'TestHandler',
            '__invoke',
            'default',
            $policy,
        ));
        self::assertSame(DeliveryState::Accepted, $acceptance->state);
        $status = $backend->status($deliveryId);
        self::assertNotNull($status);
        self::assertSame(DeliveryState::Accepted, $status->state);

        $first = $backend->claim('default', 'worker:integration');
        self::assertNotNull($first);
        self::assertTrue($backend->heartbeat($first));
        self::assertFalse($backend->cancellationRequested($first));
        $failure = new FailureDiagnostic(FailureStage::HandlerResolution, \RuntimeException::class);
        self::assertTrue($backend->fail($first, FailureCategory::Retryable, $failure->encode()));
        $status = $backend->status($deliveryId);
        self::assertNotNull($status);
        self::assertSame(DeliveryState::RetryWait, $status->state);
        self::assertEquals($first->attempt->attemptId, $status->lastFailureAttemptId);
        self::assertEquals($failure, $status->lastFailure);
        self::assertFalse($backend->succeed($first), 'A settled lease cannot settle again.');

        $second = $backend->claim('default', 'worker:integration');
        self::assertNotNull($second);
        self::assertTrue($backend->succeed($second));
        $status = $backend->status($deliveryId);
        self::assertNotNull($status);
        self::assertSame(DeliveryState::Succeeded, $status->state);
        self::assertSame(FailureCategory::Retryable->value, $status->lastFailureCategory);
        self::assertEquals($first->attempt->attemptId, $status->lastFailureAttemptId);
        self::assertEquals($failure, $status->lastFailure);
        self::assertSame(
            CancellationOutcome::AlreadyTerminal,
            $backend->cancel($deliveryId, new ControlActor('tester', 'late cancel'))->outcome,
        );

        $this->pdo->beginTransaction();
        $stagedId = new DeliveryId('delivery:postgres-0002');
        $staged = $backend->accept(new DeliveryDraft(
            $stagedId,
            new MessageMetadata(
                new MessageId('message:postgres-0002'),
                new CorrelationId('correlation:postgres-0002'),
                null,
                new \DateTimeImmutable('2026-11-22T10:00:00+00:00'),
            ),
            new EncodedPayload('test.command', 1, 'json', '{"value":2}'),
            'test.command',
            'TestHandler',
            '__invoke',
            'default',
            $policy,
        ));
        self::assertSame(DeliveryState::Staged, $staged->state);
        self::assertNotNull($backend->status($stagedId));
        $this->pdo->rollBack();
        self::assertNull($backend->status($stagedId));
    }

    public function testAtomicIdempotencyOwnsEffectTransactionAndRetention(): void
    {
        (new PostgresSchemaManager($this->pdo))->migrate();
        $deliveryId = new DeliveryId('delivery:idempotency-0001');
        $this->acceptDelivery($deliveryId);
        $this->pdo->exec('CREATE TABLE message_bus_test_effects (effect_key text PRIMARY KEY)');

        $boundary = new PostgresAtomicIdempotencyBoundary($this->pdo);
        $first = $this->idempotencyAttempt($deliveryId, 'same-key', 'same-intent');
        $insert = function (): void {
            $this->pdo->exec("INSERT INTO message_bus_test_effects (effect_key) VALUES ('same-key')");
        };
        self::assertSame(IdempotencyOutcome::Executed, $boundary->execute($first, $insert)->outcome);

        $replayedEffect = false;
        self::assertSame(
            IdempotencyOutcome::Replayed,
            $boundary->execute($first, static function () use (&$replayedEffect): void {
                $replayedEffect = true;
            })->outcome,
        );
        self::assertFalse($replayedEffect);

        try {
            $boundary->execute(
                $this->idempotencyAttempt($deliveryId, 'same-key', 'different-intent'),
                static function (): void {},
            );
            self::fail('A conflicting idempotency intent was accepted.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        $rollback = $this->idempotencyAttempt($deliveryId, 'rollback-key', 'rollback-intent');

        try {
            $boundary->execute($rollback, function (): never {
                $this->pdo->exec(
                    "INSERT INTO message_bus_test_effects (effect_key) VALUES ('rollback-key')",
                );

                throw new \RuntimeException('expected rollback');
            });
            self::fail('A failing protected effect was committed.');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }
        $rolledBackEffects = $this->pdo->query(
            "SELECT count(*) FROM message_bus_test_effects WHERE effect_key = 'rollback-key'",
        );
        self::assertInstanceOf(\PDOStatement::class, $rolledBackEffects);
        self::assertSame('0', (string) $rolledBackEffects->fetchColumn());
        self::assertSame(
            IdempotencyOutcome::Executed,
            $boundary->execute($rollback, function (): void {
                $this->pdo->exec(
                    "INSERT INTO message_bus_test_effects (effect_key) VALUES ('rollback-key')",
                );
            })->outcome,
        );

        $maintenance = new PostgresIdempotencyMaintenance($this->pdo);
        $cutoff = new \DateTimeImmutable('2100-01-01T00:00:00+00:00');
        self::assertSame(2, $maintenance->pruneExpired($cutoff, 10, false)->examined);
        self::assertSame(2, $maintenance->pruneExpired($cutoff, 10, true)->changed);
    }

    public function testWorkerControlIsRevisionedObservableAndPrunable(): void
    {
        (new PostgresSchemaManager($this->pdo))->migrate();
        $control = new PostgresWorkerControl($this->pdo);
        $scope = WorkerControlScope::global();
        self::assertSame(0, $control->state($scope)->revision);

        $paused = $control->pause($scope, 'tester', 'maintenance', 'worker-pause-0001', 0);
        self::assertSame(WorkerControlOutcome::Applied, $paused->outcome);
        self::assertSame(1, $paused->state->revision);
        $duplicate = $control->pause($scope, 'tester', 'maintenance', 'worker-pause-0001', 0);
        self::assertTrue($duplicate->duplicate);
        self::assertSame($paused->operationId, $duplicate->operationId);
        self::assertSame(
            WorkerControlOutcome::RevisionConflict,
            $control->pause($scope, 'tester', 'stale request', 'worker-conflict-0001', 0)->outcome,
        );

        $startedAt = new \DateTimeImmutable('-1 hour');
        $control->report(new WorkerStatus(
            'worker:postgres-0001',
            'default',
            'default',
            1_234,
            'paused',
            0,
            $startedAt,
            new \DateTimeImmutable(),
            appliedGlobalRevision: 1,
        ));
        $statuses = $control->statuses(new WorkerStatusFilter(profile: 'default'));
        self::assertCount(1, $statuses);
        self::assertSame(WorkerDesiredState::Paused, $statuses[0]->effectiveDesiredState);
        self::assertTrue($statuses[0]->drained);
        self::assertSame('idle', $statuses[0]->activity());

        $resumed = $control->resume($scope, 'tester', 'maintenance complete', 'worker-resume-0001', 1);
        self::assertSame(WorkerControlOutcome::Applied, $resumed->outcome);
        self::assertSame(2, $resumed->state->revision);
        $control->report(new WorkerStatus(
            'worker:postgres-0001',
            'default',
            'default',
            1_234,
            'stopped',
            0,
            $startedAt,
            new \DateTimeImmutable(),
            appliedGlobalRevision: 2,
            stoppedAt: new \DateTimeImmutable(),
        ));
        self::assertFalse($control->statuses(new WorkerStatusFilter(instance: 'worker:postgres-0001'))[0]->drained);

        $cutoff = new \DateTimeImmutable('2100-01-01T00:00:00+00:00');
        $plan = $control->prune($cutoff, 10, false);
        self::assertSame(1, $plan->statuses);
        self::assertSame(3, $plan->operations);
        self::assertFalse($plan->executed);
        $pruned = $control->prune($cutoff, 10, true);
        self::assertSame(1, $pruned->statuses);
        self::assertSame(3, $pruned->operations);
        self::assertTrue($pruned->executed);
        self::assertSame([], $control->statuses());
    }

    private function acceptDelivery(DeliveryId $deliveryId): void
    {
        (new PostgresDeliveryBackend($this->pdo, new SequenceIdentities()))->accept(new DeliveryDraft(
            $deliveryId,
            new MessageMetadata(
                new MessageId('message:' . substr(hash('sha256', $deliveryId->value), 0, 24)),
                new CorrelationId('correlation:' . substr(hash('sha256', $deliveryId->value), 0, 24)),
                null,
                new \DateTimeImmutable(),
            ),
            new EncodedPayload('test.command', 1, 'json', '{}'),
            'test.command',
            'TestHandler',
            '__invoke',
            'default',
            new DeliveryPolicy('integration'),
        ));
    }

    private function idempotencyAttempt(
        DeliveryId $deliveryId,
        string $key,
        string $intent,
    ): IdempotencyAttempt {
        return new IdempotencyAttempt(
            new IdempotencyIdentity(
                'test.command',
                new IdempotencyKey($key),
                new IntentFingerprint('sha256', 1, hash('sha256', $intent)),
            ),
            $deliveryId,
            new \DateTimeImmutable('2099-01-01T00:00:00+00:00'),
        );
    }
}
