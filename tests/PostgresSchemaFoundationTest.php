<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueSchemaGenerator;
use Wolfcharaa\MessageBus\Queue\QueueJobState;
use Wolfcharaa\MessageBus\Worker\Postgres\PostgresWorkerControlSchemaGenerator;

final class PostgresSchemaFoundationTest extends TestCase
{
    public function testQueueSchemaContainsInterruptedStatusAndSchemaVersion(): void
    {
        $sql = (new PostgresQueueSchemaGenerator())->generate();

        self::assertSame('interrupted', QueueJobState::Interrupted->value);
        self::assertStringContainsString("status IN ('pending', 'running', 'succeeded', 'failed', 'cancelled', 'interrupted')", $sql);
        self::assertStringContainsString('message_bus__queue_jobs_interrupted_idx', $sql);
        self::assertStringContainsString('message_bus__schema_versions', $sql);
        self::assertStringContainsString("VALUES ('queue', '5.1'", $sql);
    }

    public function testWorkerControlSchemaContainsV51ControlPlaneTables(): void
    {
        $sql = (new PostgresWorkerControlSchemaGenerator())->generate();

        self::assertStringContainsString('message_bus__worker_control_commands', $sql);
        self::assertStringContainsString('message_bus__worker_control_command_deliveries', $sql);
        self::assertStringContainsString('message_bus__worker_control_command_acknowledgements', $sql);
        self::assertStringContainsString('message_bus__worker_control_command_audit', $sql);
        self::assertStringContainsString('message_bus__worker_desired_state', $sql);
        self::assertStringContainsString('concurrency_override', $sql);
        self::assertStringContainsString('runtime_overrides', $sql);
        self::assertStringContainsString("VALUES ('worker_control', '5.1'", $sql);
    }
}
