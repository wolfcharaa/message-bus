<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue\Postgres;

use PDO;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelopeNormalizerInterface;
use Wolfcharaa\MessageBus\Postgres\OperationSafety;
use Wolfcharaa\MessageBus\Postgres\PdoConnectionProviderInterface;
use Wolfcharaa\MessageBus\Postgres\PostgresRetryingExecutor;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\QueueBatchEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueJobStatus;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\ReceivedQueueMessage;

final class ResilientPostgresQueueStorage implements PostgresQueueStorageInterface
{
    private readonly PostgresRetryingExecutor $executor;

    public function __construct(
        private readonly PdoConnectionProviderInterface $connectionProvider,
        private readonly string $tableName = 'message_bus__queue_jobs',
        private readonly ?SerializedEnvelopeNormalizerInterface $envelopeNormalizer = null,
        ?PostgresRetryingExecutor $executor = null,
    ) {
        $this->executor = $executor ?? new PostgresRetryingExecutor($connectionProvider);
    }

    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        return $this->executor->execute(
            'queue.enqueue',
            OperationSafety::NonIdempotent,
            fn (PDO $pdo): QueueEnqueueResult => $this->storage($pdo)->enqueue($message),
        );
    }

    public function enqueueMany(iterable $messages): QueueBatchEnqueueResult
    {
        return $this->executor->execute(
            'queue.enqueue_many',
            OperationSafety::NonIdempotent,
            fn (PDO $pdo): QueueBatchEnqueueResult => $this->storage($pdo)->enqueueMany($messages),
        );
    }

    public function next(ConsumerOptions $options): ?ReceivedQueueMessage
    {
        return $this->executor->execute(
            'queue.next',
            OperationSafety::NonIdempotent,
            fn (PDO $pdo): ?ReceivedQueueMessage => $this->storage($pdo)->next($options),
        );
    }

    public function ack(ReceivedQueueMessage $message): void
    {
        $this->executor->execute(
            'queue.ack',
            OperationSafety::Idempotent,
            function (PDO $pdo) use ($message): null {
                $this->storage($pdo)->ack($message);

                return null;
            },
        );
    }

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->executor->execute(
            'queue.retry',
            OperationSafety::Idempotent,
            function (PDO $pdo) use ($message, $reason): null {
                $this->storage($pdo)->retry($message, $reason);

                return null;
            },
        );
    }

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->executor->execute(
            'queue.reject',
            OperationSafety::Idempotent,
            function (PDO $pdo) use ($message, $reason): null {
                $this->storage($pdo)->reject($message, $reason);

                return null;
            },
        );
    }

    public function cancelReceived(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->executor->execute(
            'queue.cancel_received',
            OperationSafety::Idempotent,
            function (PDO $pdo) use ($message, $reason): null {
                $this->storage($pdo)->cancelReceived($message, $reason);

                return null;
            },
        );
    }

    public function get(string $queueMessageId): ?QueueJobStatus
    {
        return $this->executor->execute(
            'queue.get',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): ?QueueJobStatus => $this->storage($pdo)->get($queueMessageId),
        );
    }

    public function listByMessageId(string $messageId): array
    {
        return $this->executor->execute(
            'queue.list_by_message_id',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): array => $this->storage($pdo)->listByMessageId($messageId),
        );
    }

    public function listByCorrelationId(string $correlationId): array
    {
        return $this->executor->execute(
            'queue.list_by_correlation_id',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): array => $this->storage($pdo)->listByCorrelationId($correlationId),
        );
    }

    public function cancel(string $queueMessageId): void
    {
        $this->executor->execute(
            'queue.cancel',
            OperationSafety::Idempotent,
            function (PDO $pdo) use ($queueMessageId): null {
                $this->storage($pdo)->cancel($queueMessageId);

                return null;
            },
        );
    }

    public function requestCancellation(string $queueMessageId): void
    {
        $this->executor->execute(
            'queue.request_cancellation',
            OperationSafety::Idempotent,
            function (PDO $pdo) use ($queueMessageId): null {
                $this->storage($pdo)->requestCancellation($queueMessageId);

                return null;
            },
        );
    }

    public function heartbeat(string $queueMessageId): void
    {
        $this->executor->execute(
            'queue.heartbeat',
            OperationSafety::Idempotent,
            function (PDO $pdo) use ($queueMessageId): null {
                $this->storage($pdo)->heartbeat($queueMessageId);

                return null;
            },
        );
    }

    public function isCancellationRequested(string $queueMessageId): bool
    {
        return $this->executor->execute(
            'queue.is_cancellation_requested',
            OperationSafety::ReadOnly,
            fn (PDO $pdo): bool => $this->storage($pdo)->isCancellationRequested($queueMessageId),
        );
    }

    public function recoverStale(ConsumerOptions $options): int
    {
        return $this->executor->execute(
            'queue.recover_stale',
            OperationSafety::Idempotent,
            fn (PDO $pdo): int => $this->storage($pdo)->recoverStale($options),
        );
    }

    private function storage(PDO $pdo): PostgresQueueStorage
    {
        return new PostgresQueueStorage($pdo, $this->tableName, $this->envelopeNormalizer);
    }
}
