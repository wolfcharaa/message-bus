<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Postgres\DefaultPostgresTransientFailureDetector;
use Wolfcharaa\MessageBus\Postgres\OperationSafety;
use Wolfcharaa\MessageBus\Postgres\PdoConnectionProviderInterface;
use Wolfcharaa\MessageBus\Postgres\PostgresRetryConfig;
use Wolfcharaa\MessageBus\Postgres\PostgresRetryingExecutor;
use Wolfcharaa\MessageBus\Postgres\StaticPdoConnectionProvider;

final class PostgresRetryingExecutorTest extends TestCase
{
    public function testRetriesIdempotentOperationAfterTransientDisconnect(): void
    {
        $provider = new RetryExecutorTestConnectionProvider();
        $executor = new PostgresRetryingExecutor($provider, config: new PostgresRetryConfig(
            attempts: 2,
            initialDelayMilliseconds: 0,
            jitter: false,
        ));
        $calls = 0;

        $result = $executor->execute(
            'worker_control.heartbeat_worker',
            OperationSafety::Idempotent,
            static function () use (&$calls): string {
                ++$calls;
                if ($calls === 1) {
                    throw new PDOException('SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly');
                }

                return 'ok';
            },
        );

        self::assertSame('ok', $result);
        self::assertSame(2, $calls);
        self::assertSame(1, $provider->resetCount);
    }

    public function testDoesNotRetryNonIdempotentOperation(): void
    {
        $provider = new RetryExecutorTestConnectionProvider();
        $executor = new PostgresRetryingExecutor($provider, config: new PostgresRetryConfig(
            attempts: 2,
            initialDelayMilliseconds: 0,
            jitter: false,
        ));
        $calls = 0;

        $this->expectException(PDOException::class);

        try {
            $executor->execute(
                'queue.enqueue',
                OperationSafety::NonIdempotent,
                static function () use (&$calls): never {
                    ++$calls;
                    throw new PDOException('SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly');
                },
            );
        } finally {
            self::assertSame(1, $calls);
            self::assertSame(1, $provider->resetCount);
        }
    }

    public function testRequiresIdempotencyKeyForUniqueKeyOperation(): void
    {
        $executor = new PostgresRetryingExecutor(
            new RetryExecutorTestConnectionProvider(),
            config: new PostgresRetryConfig(initialDelayMilliseconds: 0, jitter: false),
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('IdempotentWithUniqueKey PostgreSQL operation requires stable idempotency key.');

        $executor->execute(
            'worker_control.append',
            OperationSafety::IdempotentWithUniqueKey,
            static fn (): string => 'never',
        );
    }

    public function testRetriesTransactionalOperationAsWholeCallback(): void
    {
        $provider = new RetryExecutorTestConnectionProvider();
        $executor = new PostgresRetryingExecutor($provider, config: new PostgresRetryConfig(
            attempts: 2,
            initialDelayMilliseconds: 0,
            jitter: false,
        ));
        $calls = 0;

        $result = $executor->transactional(
            'queue.mark_succeeded',
            OperationSafety::Idempotent,
            static function (PDO $pdo) use (&$calls): string {
                self::assertTrue($pdo->inTransaction());
                ++$calls;
                if ($calls === 1) {
                    throw new PDOException('SQLSTATE[08006] connection failure');
                }

                return 'done';
            },
        );

        self::assertSame('done', $result);
        self::assertSame(2, $calls);
        self::assertSame(1, $provider->resetCount);
    }

    public function testDefaultDetectorRecognizesKnownPostgresDisconnects(): void
    {
        $detector = new DefaultPostgresTransientFailureDetector();

        self::assertTrue($detector->isTransient(new PDOException('SQLSTATE[08006] connection failure')));
        self::assertTrue($detector->isTransient(new PDOException('SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly')));
        self::assertTrue($detector->isTransient(new PDOException('terminating connection due to administrator command')));
        self::assertFalse($detector->isTransient(new PDOException('SQLSTATE[23505] unique violation')));
    }

    public function testStaticProviderResetFailsExplicitly(): void
    {
        $provider = new StaticPdoConnectionProvider(new PDO('sqlite::memory:'));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('StaticPdoConnectionProvider cannot reset PDO connection');

        $provider->reset();
    }

    public function testStaticProviderFailsExplicitlyAfterTransientDisconnect(): void
    {
        $executor = new PostgresRetryingExecutor(
            new StaticPdoConnectionProvider(new PDO('sqlite::memory:')),
            config: new PostgresRetryConfig(
                attempts: 2,
                initialDelayMilliseconds: 0,
                jitter: false,
            ),
        );

        try {
            $executor->execute(
                'worker_control.heartbeat_worker',
                OperationSafety::Idempotent,
                static function (): never {
                    throw new PDOException('SQLSTATE[HY000]: General error: 7 server closed the connection unexpectedly');
                },
            );

            self::fail('Expected runtime exception.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('PostgreSQL connection provider reset failed after transient failure', $error->getMessage());
            self::assertStringContainsString('StaticPdoConnectionProvider cannot reset PDO connection', $error->getMessage());
            self::assertInstanceOf(PDOException::class, $error->getPrevious());
        }
    }
}

final class RetryExecutorTestConnectionProvider implements PdoConnectionProviderInterface
{
    public int $resetCount = 0;
    private ?PDO $connection = null;

    public function connection(): PDO
    {
        return $this->connection ??= new PDO('sqlite::memory:');
    }

    public function reset(): void
    {
        ++$this->resetCount;
        $this->connection = null;
    }
}
