<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue\Postgres;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelopeNormalizer;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelopeNormalizerInterface;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\QueueBatchEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueJobControlInterface;
use Wolfcharaa\MessageBus\Queue\QueueJobState;
use Wolfcharaa\MessageBus\Queue\QueueJobStatus;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueStatusRepositoryInterface;
use Wolfcharaa\MessageBus\Queue\ReceivedQueueMessage;
use Wolfcharaa\MessageBus\Queue\RetryPolicySnapshot;

final class PostgresQueueStorage implements QueueStatusRepositoryInterface, QueueJobControlInterface
{
    private readonly string $table;
    private readonly SerializedEnvelopeNormalizerInterface $envelopeNormalizer;

    public function __construct(
        private readonly PDO $pdo,
        string $tableName = 'message_bus__queue_jobs',
        ?SerializedEnvelopeNormalizerInterface $envelopeNormalizer = null,
    ) {
        if (!\in_array('pgsql', PDO::getAvailableDrivers(), true)) {
            throw new RuntimeException('PostgreSQL queue requires PDO pgsql driver.');
        }

        $this->table = $this->quoteIdentifier($tableName);
        $this->envelopeNormalizer = $envelopeNormalizer ?? new SerializedEnvelopeNormalizer();
    }

    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        return $this->insert($message);
    }

    /**
     * @param iterable<QueueMessage> $messages
     */
    public function enqueueMany(iterable $messages): QueueBatchEnqueueResult
    {
        $results = [];
        $this->pdo->beginTransaction();
        try {
            foreach ($messages as $message) {
                $results[] = $this->insert($message);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return new QueueBatchEnqueueResult(...$results);
    }

    public function next(ConsumerOptions $options): ?ReceivedQueueMessage
    {
        $this->recoverStale($options);
        $now = $this->now();

        $this->pdo->beginTransaction();
        try {
            $where = [
                'status = :status',
                'transport = :transport',
                'queue = :queue',
                'available_at <= :now',
            ];
            $params = [
                ':status' => QueueJobState::Pending->value,
                ':transport' => $options->transport,
                ':queue' => $options->queue,
                ':now' => $this->formatDate($now),
            ];

            $this->applyFilters($where, $params, $options);

            $sql = 'SELECT id FROM ' . $this->table . ' WHERE ' . \implode(' AND ', $where)
                . ' ORDER BY priority DESC, available_at ASC, id ASC FOR UPDATE SKIP LOCKED LIMIT 1';
            $statement = $this->pdo->prepare($sql);
            $statement->execute($params);
            $id = $statement->fetchColumn();

            if ($id === false) {
                $this->pdo->commit();

                return null;
            }

            $update = $this->pdo->prepare(
                'UPDATE ' . $this->table . '
                SET status = :running,
                    attempts = attempts + 1,
                    locked_at = :now,
                    locked_by = :worker,
                    heartbeat_at = :now,
                    started_at = :now,
                    updated_at = :now
                WHERE id = :id
                RETURNING *'
            );
            $update->execute([
                ':running' => QueueJobState::Running->value,
                ':now' => $this->formatDate($now),
                ':worker' => $options->workerId,
                ':id' => $id,
            ]);
            $row = $update->fetch(PDO::FETCH_ASSOC);
            $this->pdo->commit();

            return $this->received($row);
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function ack(ReceivedQueueMessage $message): void
    {
        $now = $this->now();
        $this->updateState($message->queueMessageId, QueueJobState::Succeeded, [
            'finished_at' => $this->formatDate($now),
            'locked_at' => null,
            'locked_by' => null,
            'heartbeat_at' => null,
            'updated_at' => $this->formatDate($now),
        ]);
    }

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $now = $this->now();
        if ($message->attempts >= $message->message->retryPolicySnapshot->maxAttempts) {
            $this->fail($message->queueMessageId, $reason, $now);

            return;
        }

        $delay = $this->retryDelaySeconds($message->message->retryPolicySnapshot, $message->attempts);
        $availableAt = $now->modify('+' . $delay . ' seconds');
        $error = $this->errorDetails($reason);
        $this->updateState($message->queueMessageId, QueueJobState::Pending, [
            'available_at' => $this->formatDate($availableAt),
            'locked_at' => null,
            'locked_by' => null,
            'heartbeat_at' => null,
            'last_error' => $error['summary'],
            'last_error_details' => \json_encode($error['details'], JSON_THROW_ON_ERROR),
            'updated_at' => $this->formatDate($now),
        ]);
    }

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $this->fail($message->queueMessageId, $reason, $this->now());
    }

    public function cancelReceived(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        $error = $this->errorDetails($reason);
        $this->updateState($message->queueMessageId, QueueJobState::Cancelled, [
            'finished_at' => $this->formatDate($this->now()),
            'last_error' => $error['summary'],
            'last_error_details' => \json_encode($error['details'], JSON_THROW_ON_ERROR),
            'updated_at' => $this->formatDate($this->now()),
        ]);
    }

    public function get(string $queueMessageId): ?QueueJobStatus
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . $this->table . ' WHERE id = :id');
        $statement->execute([':id' => $queueMessageId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->status($row);
    }

    public function listByMessageId(string $messageId): array
    {
        return $this->listBy('message_id', $messageId);
    }

    public function listByCorrelationId(string $correlationId): array
    {
        return $this->listBy('correlation_id', $correlationId);
    }

    public function cancel(string $queueMessageId): void
    {
        $now = $this->now();
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table . '
            SET status = :cancelled, finished_at = :now, updated_at = :now
            WHERE id = :id AND status = :pending'
        );
        $statement->execute([
            ':cancelled' => QueueJobState::Cancelled->value,
            ':now' => $this->formatDate($now),
            ':id' => $queueMessageId,
            ':pending' => QueueJobState::Pending->value,
        ]);
    }

    public function requestCancellation(string $queueMessageId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table . '
            SET cancellation_requested = TRUE, updated_at = :now
            WHERE id = :id AND status = :running'
        );
        $statement->execute([
            ':now' => $this->formatDate($this->now()),
            ':id' => $queueMessageId,
            ':running' => QueueJobState::Running->value,
        ]);
    }

    public function heartbeat(string $queueMessageId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE ' . $this->table . ' SET heartbeat_at = :now WHERE id = :id AND status = :running'
        );
        $statement->execute([
            ':now' => $this->formatDate($this->now()),
            ':id' => $queueMessageId,
            ':running' => QueueJobState::Running->value,
        ]);
    }

    public function isCancellationRequested(string $queueMessageId): bool
    {
        $statement = $this->pdo->prepare('SELECT cancellation_requested FROM ' . $this->table . ' WHERE id = :id');
        $statement->execute([':id' => $queueMessageId]);

        $value = $statement->fetchColumn();

        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }

    public function recoverStale(ConsumerOptions $options): int
    {
        $threshold = $this->now()->modify('-' . $options->lockTtlSeconds . ' seconds');
        $statement = $this->pdo->prepare(
            'SELECT * FROM ' . $this->table . '
            WHERE status = :running
              AND transport = :transport
              AND queue = :queue
              AND COALESCE(heartbeat_at, locked_at) < :threshold'
        );
        $statement->execute([
            ':running' => QueueJobState::Running->value,
            ':transport' => $options->transport,
            ':queue' => $options->queue,
            ':threshold' => $this->formatDate($threshold),
        ]);

        $count = 0;
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $message = $this->received($row);
            $reason = new RuntimeException('Worker heartbeat expired after ' . $options->lockTtlSeconds . ' seconds.');
            $this->retry($message, $reason);
            ++$count;
        }

        return $count;
    }

    private function insert(QueueMessage $message): QueueEnqueueResult
    {
        $now = $this->now();
        $statement = $this->pdo->prepare(
            'INSERT INTO ' . $this->table . ' (
                transport, queue, status, message_name, message_id, correlation_id, flow, binding_id,
                priority, attempts, max_attempts, retry_policy_key, retry_policy_snapshot,
                available_at, serialized_envelope, created_at, updated_at
            ) VALUES (
                :transport, :queue, :status, :message_name, :message_id, :correlation_id, :flow, :binding_id,
                :priority, 0, :max_attempts, :retry_policy_key, :retry_policy_snapshot,
                :available_at, :serialized_envelope, :created_at, :updated_at
            ) RETURNING id, created_at'
        );
        $statement->execute([
            ':transport' => $message->transport,
            ':queue' => $message->queue,
            ':status' => QueueJobState::Pending->value,
            ':message_name' => $message->envelope->message->name,
            ':message_id' => $message->messageId,
            ':correlation_id' => $message->correlationId,
            ':flow' => $message->flow,
            ':binding_id' => $message->bindingId,
            ':priority' => $message->priority,
            ':max_attempts' => $message->retryPolicySnapshot->maxAttempts,
            ':retry_policy_key' => $message->retryPolicyKey,
            ':retry_policy_snapshot' => \json_encode($message->retryPolicySnapshot->toArray(), JSON_THROW_ON_ERROR),
            ':available_at' => $this->formatDate($message->availableAt),
            ':serialized_envelope' => \json_encode($this->envelopeNormalizer->toArray($message->envelope), JSON_THROW_ON_ERROR),
            ':created_at' => $this->formatDate($now),
            ':updated_at' => $this->formatDate($now),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return new QueueEnqueueResult((string) $row['id'], status: QueueJobState::Pending, createdAt: $this->date($row['created_at']));
    }

    /** @param array<string, mixed> $row */
    private function received(array $row): ReceivedQueueMessage
    {
        return new ReceivedQueueMessage(
            (string) $row['id'],
            new QueueMessage(
                $row['transport'],
                $row['queue'],
                $this->envelopeNormalizer->fromArray($this->json($row['serialized_envelope'])),
                $row['message_id'],
                $row['correlation_id'],
                $row['flow'],
                $row['binding_id'],
                $this->date($row['available_at']),
                (int) $row['priority'],
                $row['retry_policy_key'],
                $this->retrySnapshot($this->json($row['retry_policy_snapshot'])),
            ),
            (int) $row['attempts'],
            $row,
        );
    }

    /** @param array<string, mixed> $row */
    private function status(array $row): QueueJobStatus
    {
        return new QueueJobStatus(
            (string) $row['id'],
            QueueJobState::from($row['status']),
            $row['message_id'],
            $row['correlation_id'],
            $row['flow'],
            $row['binding_id'],
            $row['transport'],
            $row['queue'],
            (int) $row['attempts'],
            (int) $row['max_attempts'],
            $this->date($row['available_at']),
            $this->nullableDate($row['started_at']),
            $this->nullableDate($row['finished_at']),
            $this->date($row['created_at']),
            $this->date($row['updated_at']),
            $row['last_error'],
        );
    }

    /** @return list<QueueJobStatus> */
    private function listBy(string $column, string $value): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM ' . $this->table . ' WHERE ' . $column . ' = :value ORDER BY id ASC');
        $statement->execute([':value' => $value]);

        return \array_map(fn (array $row): QueueJobStatus => $this->status($row), $statement->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @param array<string, string> $fields */
    private function updateState(string $id, QueueJobState $state, array $fields): void
    {
        $assignments = ['status = :status'];
        $params = [':id' => $id, ':status' => $state->value];
        foreach ($fields as $field => $value) {
            $param = ':' . $field;
            $assignments[] = $field . ' = ' . $param;
            $params[$param] = $value;
        }

        $statement = $this->pdo->prepare('UPDATE ' . $this->table . ' SET ' . \implode(', ', $assignments) . ' WHERE id = :id');
        $statement->execute($params);
    }

    private function fail(string $id, \Throwable $reason, DateTimeImmutable $now): void
    {
        $error = $this->errorDetails($reason);
        $this->updateState($id, QueueJobState::Failed, [
            'finished_at' => $this->formatDate($now),
            'last_error' => $error['summary'],
            'last_error_details' => \json_encode($error['details'], JSON_THROW_ON_ERROR),
            'updated_at' => $this->formatDate($now),
        ]);
    }

    /**
     * @param list<string> $where
     * @param array<string, mixed> $params
     */
    private function applyFilters(array &$where, array &$params, ConsumerOptions $options): void
    {
        if ($options->flows !== []) {
            $where[] = 'flow IN (' . $this->placeholders('flow', $options->flows, $params) . ')';
        }

        if ($options->bindingIds !== []) {
            $where[] = 'binding_id IN (' . $this->placeholders('binding', $options->bindingIds, $params) . ')';
        }

        foreach ($options->bindingPatterns as $index => $pattern) {
            $param = ':binding_pattern_' . $index;
            $where[] = 'binding_id LIKE ' . $param;
            $params[$param] = \str_replace('*', '%', $pattern);
        }
    }

    /**
     * @param list<string> $values
     * @param array<string, mixed> $params
     */
    private function placeholders(string $prefix, array $values, array &$params): string
    {
        $placeholders = [];
        foreach ($values as $index => $value) {
            $param = ':' . $prefix . '_' . $index;
            $placeholders[] = $param;
            $params[$param] = $value;
        }

        return \implode(', ', $placeholders);
    }

    /** @return array{summary: string, details: array<string, mixed>} */
    private function errorDetails(\Throwable $reason): array
    {
        return [
            'summary' => $reason->getMessage(),
            'details' => [
                'class' => $reason::class,
                'message' => $reason->getMessage(),
                'code' => $reason->getCode(),
                'file' => $reason->getFile(),
                'line' => $reason->getLine(),
                'trace' => \substr($reason->getTraceAsString(), 0, 8000),
            ],
        ];
    }

    private function retryDelaySeconds(RetryPolicySnapshot $snapshot, int $attempt): int
    {
        if ($snapshot->strategy === 'fixed') {
            return (int) ($snapshot->parameters['delaySeconds'] ?? 60);
        }

        $initial = (int) ($snapshot->parameters['initialDelaySeconds'] ?? 30);
        $multiplier = (float) ($snapshot->parameters['multiplier'] ?? 2.0);
        $max = $snapshot->parameters['maxDelaySeconds'] ?? 300;
        $delay = (int) \round($initial * ($multiplier ** \max(0, $attempt - 1)));

        return $max === null ? $delay : \min($delay, (int) $max);
    }

    /** @param array<string, mixed> $data */
    private function retrySnapshot(array $data): RetryPolicySnapshot
    {
        return new RetryPolicySnapshot(
            (int) $data['maxAttempts'],
            $data['strategy'],
            $data['parameters'] ?? [],
        );
    }

    /** @return array<string, mixed> */
    private function json(string $json): array
    {
        return \json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function date(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private function nullableDate(?string $value): ?DateTimeImmutable
    {
        return $value === null ? null : $this->date($value);
    }

    private function formatDate(DateTimeImmutable $date): string
    {
        return $date->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }
}
