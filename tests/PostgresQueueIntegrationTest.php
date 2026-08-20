<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresMessageConsumer;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueProvider;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueSchemaGenerator;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueStorage;
use Wolfcharaa\MessageBus\Queue\QueueJobState;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueTableDefinition;
use Wolfcharaa\MessageBus\Queue\RetryPolicySnapshot;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

#[RequiresPhpExtension('pdo_pgsql')]
final class PostgresQueueIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?string $tableName = null;

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->tableName !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($this->tableName));
        }
    }

    public function testPostgresQueueLifecycleWithRealDatabase(): void
    {
        $storage = $this->storage();
        $provider = new PostgresQueueProvider($storage);
        $consumer = new PostgresMessageConsumer($storage);
        $message = $this->message('message-1', 'binding.lifecycle', maxAttempts: 3);

        $enqueue = $provider->enqueue($message);
        $status = $storage->get($enqueue->queueMessageId);

        self::assertSame(QueueJobState::Pending, $enqueue->status);
        self::assertNotNull($status);
        self::assertSame(QueueJobState::Pending, $status->status);
        self::assertSame('message-1', $status->messageId);
        self::assertCount(1, $storage->listByMessageId('message-1'));
        self::assertCount(1, $storage->listByCorrelationId('correlation-message-1'));

        $received = $consumer->next(new ConsumerOptions('postgres', 'default', workerId: 'integration-worker'));

        self::assertNotNull($received);
        self::assertSame($enqueue->queueMessageId, $received->queueMessageId);
        self::assertSame(1, $received->attempts);
        self::assertSame('binding.lifecycle', $received->message->bindingId);
        self::assertSame(QueueJobState::Running, $storage->get($enqueue->queueMessageId)?->status);

        $storage->heartbeat($received->queueMessageId);
        self::assertFalse($storage->isCancellationRequested($received->queueMessageId));

        $storage->requestCancellation($received->queueMessageId);
        self::assertTrue($storage->isCancellationRequested($received->queueMessageId));

        $consumer->ack($received);
        self::assertSame(QueueJobState::Succeeded, $storage->get($enqueue->queueMessageId)?->status);
    }

    public function testPostgresQueueRetryAndFinalRejectWithRealDatabase(): void
    {
        $storage = $this->storage();
        $provider = new PostgresQueueProvider($storage);
        $consumer = new PostgresMessageConsumer($storage);
        $enqueue = $provider->enqueue($this->message('message-retry', 'binding.retry', maxAttempts: 2));

        $first = $consumer->next(new ConsumerOptions('postgres', 'default', workerId: 'integration-worker'));
        self::assertNotNull($first);
        self::assertSame(1, $first->attempts);

        $consumer->retry($first, new RuntimeException('first failure'));
        $afterRetry = $storage->get($enqueue->queueMessageId);
        self::assertNotNull($afterRetry);
        self::assertSame(QueueJobState::Pending, $afterRetry->status);
        self::assertSame('first failure', $afterRetry->lastError);

        $second = $consumer->next(new ConsumerOptions('postgres', 'default', workerId: 'integration-worker'));
        self::assertNotNull($second);
        self::assertSame(2, $second->attempts);

        $consumer->retry($second, new RuntimeException('final failure'));
        $afterFinalRetry = $storage->get($enqueue->queueMessageId);
        self::assertNotNull($afterFinalRetry);
        self::assertSame(QueueJobState::Failed, $afterFinalRetry->status);
        self::assertSame('final failure', $afterFinalRetry->lastError);
    }

    public function testPostgresQueueBatchEnqueueAndPendingCancelWithRealDatabase(): void
    {
        $storage = $this->storage();
        $provider = new PostgresQueueProvider($storage);

        $batch = $provider->enqueueMany([
            $this->message('message-batch-1', 'binding.batch.1', maxAttempts: 3),
            $this->message('message-batch-2', 'binding.batch.2', maxAttempts: 3),
        ]);
        $results = $batch->all();

        self::assertCount(2, $results);
        self::assertSame(QueueJobState::Pending, $storage->get($results[0]->queueMessageId)?->status);
        self::assertSame(QueueJobState::Pending, $storage->get($results[1]->queueMessageId)?->status);

        $storage->cancel($results[0]->queueMessageId);

        self::assertSame(QueueJobState::Cancelled, $storage->get($results[0]->queueMessageId)?->status);
        self::assertSame(QueueJobState::Pending, $storage->get($results[1]->queueMessageId)?->status);
    }

    private function storage(): PostgresQueueStorage
    {
        if ($this->pdo === null) {
            $dsn = \getenv('MESSAGE_BUS_TEST_PGSQL_DSN');
            if ($dsn === false || $dsn === '') {
                self::markTestSkipped('Set MESSAGE_BUS_TEST_PGSQL_DSN to run PostgreSQL integration tests.');
            }

            $this->pdo = new PDO(
                $dsn,
                \getenv('MESSAGE_BUS_TEST_PGSQL_USER') !== false ? (string) \getenv('MESSAGE_BUS_TEST_PGSQL_USER') : null,
                \getenv('MESSAGE_BUS_TEST_PGSQL_PASSWORD') !== false ? (string) \getenv('MESSAGE_BUS_TEST_PGSQL_PASSWORD') : null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        }

        if ($this->tableName === null) {
            $this->tableName = 'message_bus__queue_jobs_test_' . \bin2hex(\random_bytes(4));
            $this->pdo->exec((new PostgresQueueSchemaGenerator())->generate(new QueueTableDefinition($this->tableName)));
        }

        return new PostgresQueueStorage($this->pdo, $this->tableName);
    }

    private function message(string $messageId, string $bindingId, int $maxAttempts): QueueMessage
    {
        $createdAt = new DateTimeImmutable('-1 minute');
        $envelope = new SerializedEnvelope(
            new SerializedMessage('postgres.integration.message', 'application/json', '{"id":"' . $messageId . '"}'),
            ['source' => 'integration-test'],
            $messageId,
            null,
            'correlation-' . $messageId,
            'async',
            $bindingId,
            $createdAt,
        );

        return new QueueMessage(
            'postgres',
            'default',
            $envelope,
            $messageId,
            'correlation-' . $messageId,
            'async',
            $bindingId,
            $createdAt,
            retryPolicySnapshot: new RetryPolicySnapshot($maxAttempts, 'fixed', ['delaySeconds' => 0]),
        );
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }
}
