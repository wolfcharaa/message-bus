<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommand;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;
use Wolfcharaa\MessageBus\Worker\WorkerControlRequest;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

final class WorkerControlCommandTest extends TestCase
{
    public function testOneShotCommandRequiresExpiresAt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkerControlCommand(
            'command-1',
            WorkerControlCommandType::Kill,
            WorkerTarget::all(),
            new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
        );
    }

    public function testPauseCommandDoesNotRequireExpiresAt(): void
    {
        $command = new WorkerControlCommand(
            'command-1',
            WorkerControlCommandType::Pause,
            new WorkerTarget(workerGroup: 'emails'),
            new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
        );

        self::assertFalse($command->isExpired(new DateTimeImmutable('2026-08-20T10:01:00+00:00')));
    }

    public function testCommandCanBeCreatedFromAuditRequest(): void
    {
        $command = WorkerControlCommand::fromRequest(
            'command-1',
            WorkerControlCommandType::Stop,
            new WorkerTarget(workerGroup: 'emails'),
            new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
            new WorkerControlRequest(
                createdBy: 'root',
                source: 'ui',
                reason: 'deploy',
                requestId: 'request-1',
                correlationId: 'maintenance-1',
                expiresAt: new DateTimeImmutable('2026-08-20T10:05:00+00:00'),
                idempotencyKey: 'stop-emails-deploy',
            ),
        );

        self::assertSame('root', $command->createdBy);
        self::assertSame('ui', $command->source);
        self::assertSame('deploy', $command->reason);
        self::assertSame('request-1', $command->requestId);
        self::assertSame('maintenance-1', $command->correlationId);
        self::assertSame('stop-emails-deploy', $command->idempotencyKey);
        self::assertFalse($command->isExpired(new DateTimeImmutable('2026-08-20T10:04:59+00:00')));
        self::assertTrue($command->isExpired(new DateTimeImmutable('2026-08-20T10:05:00+00:00')));
    }
}
