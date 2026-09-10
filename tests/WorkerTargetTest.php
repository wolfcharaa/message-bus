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

    public function testAllTargetMatchesAnyIdentityAndHasZeroSpecificity(): void
    {
        $target = WorkerTarget::all();

        self::assertTrue($target->matches($this->identity(workerGroup: 'reports')));
        self::assertSame(0, $target->specificityScore());
        self::assertTrue($target->toArray()['all']);
        self::assertTrue(WorkerTarget::fromArray($target->toArray())->matches($this->identity()));
    }

    public function testTargetRoundTripPreservesAllFilters(): void
    {
        $target = new WorkerTarget(
            workerId: 'emails-worker',
            workerName: 'emails-worker',
            workerInstanceId: 'instance-1',
            workerGroup: 'emails',
            transport: 'postgres',
            queue: 'default',
            flows: ['async'],
            bindingIds: ['user.created.send_welcome_email'],
            bindingPatterns: ['user.*'],
            mode: WorkerMode::Auto,
            host: 'app-01',
        );

        self::assertEquals($target, WorkerTarget::fromArray($target->toArray()));
        self::assertTrue($target->matches($this->identity(
            bindingIds: ['user.created.send_welcome_email'],
            flows: ['async'],
        )));
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

    public function testBindingIdTargetMatchesWorkerAcceptingPattern(): void
    {
        $identity = $this->identity(bindingPatterns: ['user.created.*']);

        self::assertTrue((new WorkerTarget(bindingIds: ['user.created.send_welcome_email']))->matches($identity));
        self::assertFalse((new WorkerTarget(bindingIds: ['order.created.audit']))->matches($identity));
    }

    public function testTargetRejectsMismatchedScalarFilters(): void
    {
        $identity = $this->identity();

        self::assertFalse((new WorkerTarget(workerId: 'other-worker'))->matches($identity));
        self::assertFalse((new WorkerTarget(workerName: 'reports-worker'))->matches($identity));
        self::assertFalse((new WorkerTarget(workerGroup: 'reports'))->matches($identity));
        self::assertFalse((new WorkerTarget(transport: 'redis'))->matches($identity));
        self::assertFalse((new WorkerTarget(queue: 'slow'))->matches($identity));
        self::assertFalse((new WorkerTarget(mode: WorkerMode::Single))->matches($identity));
        self::assertFalse((new WorkerTarget(host: 'app-02'))->matches($identity));
    }

    public function testFlowFilterMatchesEmptyWorkerFlowAsWildcard(): void
    {
        self::assertTrue((new WorkerTarget(flows: ['async']))->matches($this->identity(flows: [])));
        self::assertFalse((new WorkerTarget(flows: ['reports']))->matches($this->identity(flows: ['async'])));
    }

    public function testMoreSpecificTargetHasHigherScore(): void
    {
        $group = new WorkerTarget(workerGroup: 'emails');
        $instance = new WorkerTarget(workerInstanceId: 'instance-1');

        self::assertGreaterThan($group->specificityScore(), $instance->specificityScore());
    }

    /**
     * @param list<string> $bindingIds
     * @param list<string> $bindingPatterns
     * @param list<string> $flows
     */
    private function identity(
        array $bindingIds = [],
        array $bindingPatterns = [],
        array $flows = [],
        string $workerGroup = 'emails',
    ): WorkerIdentity {
        return new WorkerIdentity(
            workerName: 'emails-worker',
            workerInstanceId: 'instance-1',
            workerGroup: $workerGroup,
            host: 'app-01',
            pid: 123,
            startedAt: new DateTimeImmutable('2026-08-20T10:00:00+00:00'),
            mode: WorkerMode::Auto,
            transport: 'postgres',
            queue: 'default',
            flows: $flows,
            bindingIds: $bindingIds,
            bindingPatterns: $bindingPatterns,
            workerId: 'emails-worker',
        );
    }
}
