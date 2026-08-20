<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueSchemaGenerator;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueJobState;
use Wolfcharaa\MessageBus\Queue\QueueJobStatus;
use Wolfcharaa\MessageBus\Queue\QueueTableDefinition;

final class PostgresQueueContractV5Test extends TestCase
{
    public function testSchemaGeneratorIncludesOperationalColumnsAndIndexes(): void
    {
        $sql = (new PostgresQueueSchemaGenerator())->generate();

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__queue_jobs"', $sql);
        self::assertStringContainsString('id BIGSERIAL PRIMARY KEY', $sql);
        self::assertStringContainsString('status TEXT NOT NULL', $sql);
        self::assertStringContainsString('attempts INTEGER NOT NULL DEFAULT 0', $sql);
        self::assertStringContainsString('max_attempts INTEGER NOT NULL', $sql);
        self::assertStringContainsString('retry_policy_snapshot JSONB NOT NULL', $sql);
        self::assertStringContainsString('heartbeat_at TIMESTAMPTZ NULL', $sql);
        self::assertStringContainsString('cancellation_requested BOOLEAN NOT NULL DEFAULT FALSE', $sql);
        self::assertStringContainsString('serialized_envelope JSONB NOT NULL', $sql);
        self::assertStringContainsString('message_bus__queue_jobs_pending_idx', $sql);
        self::assertStringContainsString('message_bus__queue_jobs_running_heartbeat_idx', $sql);
    }

    public function testSchemaGeneratorUsesExplicitTableNamePrefixContract(): void
    {
        $sql = (new PostgresQueueSchemaGenerator())->generate(new QueueTableDefinition('message_bus__queue_jobs_archive'));

        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "message_bus__queue_jobs_archive"', $sql);
        self::assertStringContainsString('message_bus__queue_jobs_archive_pending_idx', $sql);
        self::assertStringContainsString('message_bus__queue_jobs_archive_binding_id_idx', $sql);
    }

    public function testQueueStatusAndEnqueueResultExposeFrontendPollingFields(): void
    {
        $now = new DateTimeImmutable('2026-08-20T10:00:00+00:00');
        $status = new QueueJobStatus(
            '42',
            QueueJobState::Pending,
            'message-1',
            'correlation-1',
            'async',
            'binding.id',
            'postgres',
            'default',
            1,
            3,
            $now,
            null,
            null,
            $now,
            $now,
            'last error',
        );
        $enqueue = new QueueEnqueueResult(
            '42',
            backendId: 'postgres-42',
            status: QueueJobState::Pending,
            createdAt: $now,
            metadata: ['table' => 'message_bus__queue_jobs'],
        );

        self::assertSame('42', $status->queueMessageId);
        self::assertSame(QueueJobState::Pending, $status->status);
        self::assertSame('correlation-1', $status->correlationId);
        self::assertSame('last error', $status->lastError);
        self::assertSame('postgres-42', $enqueue->backendId);
        self::assertSame(QueueJobState::Pending, $enqueue->status);
        self::assertSame(['table' => 'message_bus__queue_jobs'], $enqueue->metadata);
    }
}
