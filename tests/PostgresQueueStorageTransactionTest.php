<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueStorage;

final class PostgresQueueStorageTransactionTest extends TestCase
{
    public function testEnqueueManyRollsBackOpenTransactionBeforeStartingBatch(): void
    {
        $pdo = new QueueStorageTransactionPdo(activeTransaction: true);
        $storage = new PostgresQueueStorage($pdo);

        $result = $storage->enqueueMany([]);

        self::assertSame([], $result->all());
        self::assertSame(1, $pdo->rollbackCount);
        self::assertSame(1, $pdo->beginCount);
        self::assertSame(1, $pdo->commitCount);
        self::assertFalse($pdo->inTransaction());
    }

    public function testNextRollsBackOpenTransactionBeforeLockingJob(): void
    {
        $pdo = new QueueStorageTransactionPdo(activeTransaction: true);
        $storage = new PostgresQueueStorage($pdo);

        $message = $storage->next(new ConsumerOptions('postgres', 'default'));

        self::assertNull($message);
        self::assertSame(1, $pdo->rollbackCount);
        self::assertSame(1, $pdo->beginCount);
        self::assertSame(1, $pdo->commitCount);
        self::assertFalse($pdo->inTransaction());
        self::assertCount(2, $pdo->preparedSql);
    }
}

final class QueueStorageTransactionPdo extends PDO
{
    /** @var list<string> */
    public array $preparedSql = [];
    public int $beginCount = 0;
    public int $commitCount = 0;
    public int $rollbackCount = 0;

    public function __construct(private bool $activeTransaction = false)
    {
    }

    public function beginTransaction(): bool
    {
        if ($this->activeTransaction) {
            throw new PDOException('There is already an active transaction');
        }

        ++$this->beginCount;
        $this->activeTransaction = true;

        return true;
    }

    public function commit(): bool
    {
        ++$this->commitCount;
        $this->activeTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        ++$this->rollbackCount;
        $this->activeTransaction = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->activeTransaction;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->preparedSql[] = $query;

        return new QueueStorageEmptyStatement();
    }
}

final class QueueStorageEmptyStatement extends PDOStatement
{
    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return false;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return [];
    }
}
