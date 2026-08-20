<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Worker\WorkerIdentity;
use Wolfcharaa\MessageBus\Worker\WorkerMode;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

final class WorkerTargetTest extends TestCase
{
    public function testEmptyTargetRequiresExplicitAllScope(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkerTarget();
    }

    public function testAllTargetCannotBeCombinedWithFilters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new WorkerTarget(workerGroup: 'emails', all: true);
    }

    public function testSpecificWorkerInstanceTargetMatchesIdentity(): void
    {
        $identity = $this->identity();

        self::assertTrue((new WorkerTarget(workerInstanceId: 'instance-1'))->matches($identity));
        self::assertFalse((new WorkerTarget(workerInstanceId: 'instance-2'))->matches($identity));
    }

    public function testBindingPatternTargetMatchesWorkerAcceptingConcreteBinding(): void
    {
        $identity = $this->identity(bindingIds: ['user.created.send_welcome_email']);

        self::assertTrue((new WorkerTarget(bindingPatterns: ['user.created.*']))->matches($identity));
        self::assertFalse((new WorkerTarget(bindingPatterns: ['order.*']))->matches($identity));
    }

    public function testMoreSpecificTargetHasHigherScore(): void
    {
        $group = new WorkerTarget(workerGroup: 'emails');
        $instance = new WorkerTarget(workerInstanceId: 'instance-1');

        self::assertGreaterThan($group->specificityScore(), $instance->specificityScore());
    }

    private function identity(array $bindingIds = []): WorkerIdentity
    {
        return new WorkerIdentity(
            workerName: 'emails-worker',
            workerInstanceId: 'instance-1',
            workerGroup: 'emails',
            host: 'app-01',
            pid: 123,
            startedAt: new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
            mode: WorkerMode::Auto,
            transport: 'postgres',
            queue: 'default',
            bindingIds: $bindingIds,
            workerId: 'emails-worker',
        );
    }
}
