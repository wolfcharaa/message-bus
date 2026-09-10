<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use BackedEnum;
use DateTimeImmutable;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wolfcharaa\MessageBus\Context\DefaultMessageContext;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Exception\MessageCancellationRequested;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResult;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\Execution\HandlerResult;
use Wolfcharaa\MessageBus\MessageBatchItem;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;
use Wolfcharaa\MessageBus\Queue\QueueJobControlInterface;
use Wolfcharaa\MessageBus\Queue\ExponentialRetryDelayStrategy;
use Wolfcharaa\MessageBus\Queue\FixedRetryDelayStrategy;
use Wolfcharaa\MessageBus\Worker\DefaultWorkerStatusService;
use Wolfcharaa\MessageBus\Worker\QueueJobWorkerRuntimeControl;
use Wolfcharaa\MessageBus\Worker\WorkerActivityState;
use Wolfcharaa\MessageBus\Worker\WorkerChildInstance;
use Wolfcharaa\MessageBus\Worker\WorkerChildState;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgement;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgementState;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredState;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredStateType;
use Wolfcharaa\MessageBus\Worker\WorkerIdentity;
use Wolfcharaa\MessageBus\Worker\WorkerInstance;
use Wolfcharaa\MessageBus\Worker\WorkerLifecycleState;
use Wolfcharaa\MessageBus\Worker\WorkerMode;
use Wolfcharaa\MessageBus\Worker\WorkerRegistryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerRuntimeControlInterface;
use Wolfcharaa\MessageBus\Worker\WorkerRuntimeControlScope;
use Wolfcharaa\MessageBus\Worker\WorkerStatusRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

final class CoreContractsTest extends TestCase
{
    public function testDefaultMessageContextDelegatesThroughCausationEnvelope(): void
    {
        $bus = new CoreContextRecordingBus();
        $envelope = $this->envelope(new CoreContextMessage('root'));
        $control = new CoreContextWorkerControl(cancellationRequested: false);
        $context = new DefaultMessageContext($bus, $envelope, $control);

        $dispatchResult = $context->dispatch(new CoreContextMessage('dispatch'));
        $dispatchAllResult = $context->dispatchAll(new CoreContextMessage('all'));
        $publishResult = $context->publish(new CoreContextMessage('publish'));
        $context->heartbeat();

        self::assertSame('dispatched', $dispatchResult);
        self::assertSame($bus->dispatchAllResult, $dispatchAllResult);
        self::assertSame($bus->publishResult, $publishResult);
        self::assertSame($envelope, $context->envelope());
        self::assertSame([
            ['dispatch', 'dispatch', $envelope],
            ['dispatchAll', 'all', $envelope],
            ['publish', 'publish', $envelope],
        ], $bus->calls);
        self::assertSame(1, $control->heartbeats);
        self::assertFalse($context->isCancellationRequested());
        $context->throwIfCancellationRequested();
    }

    public function testDefaultMessageContextThrowsWhenCancellationIsRequested(): void
    {
        $context = new DefaultMessageContext(
            new CoreContextRecordingBus(),
            $this->envelope(new CoreContextMessage('root')),
            new CoreContextWorkerControl(cancellationRequested: true),
        );

        self::assertTrue($context->isCancellationRequested());

        $this->expectException(MessageCancellationRequested::class);
        $this->expectExceptionMessage('Message cancellation was requested.');

        $context->throwIfCancellationRequested();
    }

    public function testHandlerExecutionResultIndexesSuccessesFailuresAndMissingResults(): void
    {
        $failure = new RuntimeException('failed');
        $result = new HandlerExecutionResult(
            HandlerResult::success(CoreBinding::Primary->value, CoreContextMessage::class, 'ok'),
            HandlerResult::failure('failed.binding', CoreContextRecordingBus::class, $failure),
        );

        self::assertSame('ok', $result->get(CoreBinding::Primary));
        self::assertSame('ok', $result->getByAction(CoreContextMessage::class));
        self::assertCount(1, $result->successful());
        self::assertCount(1, $result->failed());
        self::assertTrue($result->hasFailures());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed');

        $result->get('failed.binding');
    }

    public function testHandlerExecutionResultMergesAndReportsUnknownBinding(): void
    {
        $first = new HandlerExecutionResult(
            HandlerResult::success('first', CoreContextMessage::class, 'first-result'),
        );
        $second = new HandlerExecutionResult(
            HandlerResult::success('second', CoreContextRecordingBus::class, 'second-result'),
        );

        $merged = $first->merge($second);

        self::assertSame('first-result', $merged->get('first'));
        self::assertSame('second-result', $merged->get('second'));
        self::assertFalse($merged->hasFailures());

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Handler result for binding `missing` was not found.');

        $merged->get('missing');
    }

    public function testHandlerExecutionResultReportsUnknownAction(): void
    {
        $result = new HandlerExecutionResult(
            HandlerResult::success('binding', CoreContextMessage::class, 'ok'),
        );

        $this->expectException(OutOfBoundsException::class);
        $this->expectExceptionMessage('Handler result for action `missing-action` was not found.');

        $result->getByAction('missing-action');
    }

    public function testRetryDelayStrategiesExposeConfiguredValues(): void
    {
        $exponential = new ExponentialRetryDelayStrategy(3, 2.5, 20);
        $fixed = new FixedRetryDelayStrategy(7);

        self::assertSame(3, $exponential->initialDelaySeconds());
        self::assertSame(2.5, $exponential->multiplier());
        self::assertSame(20, $exponential->maxDelaySeconds());
        self::assertSame(3, $exponential->delaySeconds(0));
        self::assertSame(8, $exponential->delaySeconds(2));
        self::assertSame(19, $exponential->delaySeconds(3));
        self::assertSame(20, $exponential->delaySeconds(4));
        self::assertSame(7, $fixed->delaySeconds(100));
        self::assertSame(7, $fixed->delaySecondsValue());
    }

    public function testWorkerDesiredStateMatchesOnlyWhenActiveAndRestoresRuntimeScope(): void
    {
        $identity = new WorkerIdentity(
            workerName: 'emails-worker',
            workerInstanceId: 'instance-1',
            workerGroup: 'emails',
            host: 'app-01',
            pid: 123,
            startedAt: new DateTimeImmutable('2026-09-10T10:00:00+00:00'),
            mode: WorkerMode::Auto,
            transport: 'postgres',
            queue: 'default',
        );
        $control = new CoreContextWorkerControl(cancellationRequested: false);
        $desiredState = new WorkerDesiredState(
            'state-1',
            WorkerDesiredStateType::Paused,
            new WorkerTarget(workerName: 'emails-worker'),
            new DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        );
        $inactiveState = new WorkerDesiredState(
            'state-2',
            WorkerDesiredStateType::Resumed,
            new WorkerTarget(workerName: 'emails-worker'),
            new DateTimeImmutable('2026-09-10T10:00:00+00:00'),
            active: false,
        );

        self::assertTrue($desiredState->matches($identity));
        self::assertFalse($inactiveState->matches($identity));
        self::assertGreaterThan(0, $desiredState->specificityScore());

        try {
            WorkerRuntimeControlScope::run($control, static function (): void {
                self::assertInstanceOf(CoreContextWorkerControl::class, WorkerRuntimeControlScope::current());

                throw new RuntimeException('scope failed');
            });
        } catch (RuntimeException $e) {
            self::assertSame('scope failed', $e->getMessage());
        }

        self::assertNull(WorkerRuntimeControlScope::current());
    }

    public function testQueueJobRuntimeControlDelegatesQueueAndChildHeartbeat(): void
    {
        $queueControl = new CoreQueueJobControl(cancellationRequested: true);
        $registry = new CoreWorkerRegistry();
        $control = new QueueJobWorkerRuntimeControl(
            'queue-1',
            $queueControl,
            $registry,
            'child-1',
        );

        $control->heartbeat();

        self::assertSame(['queue-1'], $queueControl->heartbeats);
        self::assertSame('child-1', $registry->childHeartbeats[0][0]);
        self::assertSame(WorkerChildState::Running, $registry->childHeartbeats[0][1]);
        self::assertTrue($control->isCancellationRequested());
    }

    public function testDefaultWorkerStatusServiceDelegatesRepositoryQueries(): void
    {
        $repository = new CoreWorkerStatusRepository();
        $service = new DefaultWorkerStatusService($repository);
        $target = new WorkerTarget(workerGroup: 'emails');

        self::assertSame($repository->worker, $service->getWorker('instance-1'));
        self::assertSame([$repository->worker], $service->listWorkers($target));
        self::assertSame([$repository->child], $service->listChildren('instance-1'));
        self::assertSame([$repository->acknowledgement], $service->acknowledgementsForCommand('command-1'));
        self::assertSame([
            ['getWorker', 'instance-1'],
            ['listWorkers', $target],
            ['listChildren', 'instance-1'],
            ['acknowledgementsForCommand', 'command-1'],
        ], $repository->calls);
    }

    private function envelope(object $message): Envelope
    {
        return new Envelope(
            $message,
            'message-1',
            'correlation-1',
            null,
            'default',
            null,
            new DateTimeImmutable('2026-09-10T10:00:00+00:00'),
        );
    }
}

enum CoreBinding: string
{
    case Primary = 'primary.binding';
}

final class CoreContextMessage
{
    public function __construct(public readonly string $name)
    {
    }
}

final class CoreContextRecordingBus implements MessageBusInterface
{
    /** @var list<array{0: string, 1: string, 2: Envelope|null}> */
    public array $calls = [];

    public HandlerExecutionResult $dispatchAllResult;
    public PublishResult $publishResult;

    public function __construct()
    {
        $this->dispatchAllResult = new HandlerExecutionResult(
            HandlerResult::success('binding', self::class, 'all-result'),
        );
        $this->publishResult = PublishResult::empty();
    }

    public function dispatch(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        if (!$message instanceof CoreContextMessage) {
            throw new RuntimeException('Expected core context message.');
        }

        $this->calls[] = ['dispatch', $message->name, $causation];

        return 'dispatched';
    }

    public function dispatchAll(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        if (!$message instanceof CoreContextMessage) {
            throw new RuntimeException('Expected core context message.');
        }

        $this->calls[] = ['dispatchAll', $message->name, $causation];

        return $this->dispatchAllResult;
    }

    public function publish(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        if (!$message instanceof CoreContextMessage) {
            throw new RuntimeException('Expected core context message.');
        }

        $this->calls[] = ['publish', $message->name, $causation];

        return $this->publishResult;
    }

    /**
     * @param iterable<object|MessageBatchItem> $messages
     */
    public function publishMany(
        iterable $messages,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        throw new RuntimeException('Not used by core contract tests.');
    }

    public function dispatchPublishedSync(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        throw new RuntimeException('Not used by core contract tests.');
    }

    public function dispatchBindingSync(
        object $message,
        string|BackedEnum $bindingId,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        throw new RuntimeException('Not used by core contract tests.');
    }

    public function dispatchEnvelopeToBinding(Envelope $envelope): mixed
    {
        throw new RuntimeException('Not used by core contract tests.');
    }
}

final class CoreContextWorkerControl implements WorkerRuntimeControlInterface
{
    public int $heartbeats = 0;

    public function __construct(private readonly bool $cancellationRequested)
    {
    }

    public function heartbeat(): void
    {
        $this->heartbeats++;
    }

    public function isCancellationRequested(): bool
    {
        return $this->cancellationRequested;
    }
}

final class CoreQueueJobControl implements QueueJobControlInterface
{
    /** @var list<string> */
    public array $heartbeats = [];

    public function __construct(private readonly bool $cancellationRequested)
    {
    }

    public function cancel(string $queueMessageId): void
    {
    }

    public function requestCancellation(string $queueMessageId): void
    {
    }

    public function heartbeat(string $queueMessageId): void
    {
        $this->heartbeats[] = $queueMessageId;
    }

    public function isCancellationRequested(string $queueMessageId): bool
    {
        return $this->cancellationRequested;
    }
}

final class CoreWorkerRegistry implements WorkerRegistryInterface
{
    /** @var list<array{0: string, 1: WorkerChildState, 2: DateTimeImmutable}> */
    public array $childHeartbeats = [];

    public function registerWorker(WorkerInstance $worker): void
    {
    }

    public function heartbeatWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        WorkerActivityState $activity,
        DateTimeImmutable $heartbeatAt,
        int $childrenCount = 0,
        ?string $lastCommandId = null,
    ): void {
    }

    public function stopWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        DateTimeImmutable $stoppedAt,
        ?string $failureMessage = null,
    ): void {
    }

    public function registerChild(WorkerChildInstance $child): void
    {
    }

    public function heartbeatChild(string $childInstanceId, WorkerChildState $state, DateTimeImmutable $heartbeatAt): void
    {
        $this->childHeartbeats[] = [$childInstanceId, $state, $heartbeatAt];
    }

    public function finishChild(
        string $childInstanceId,
        WorkerChildState $state,
        DateTimeImmutable $finishedAt,
        ?string $failureMessage = null,
    ): void {
    }
}

final class CoreWorkerStatusRepository implements WorkerStatusRepositoryInterface
{
    public WorkerInstance $worker;
    public WorkerChildInstance $child;
    public WorkerControlAcknowledgement $acknowledgement;

    /** @var list<array{0: string, 1: mixed}> */
    public array $calls = [];

    public function __construct()
    {
        $now = new DateTimeImmutable('2026-09-10T10:00:00+00:00');
        $identity = new WorkerIdentity(
            workerName: 'emails-worker',
            workerInstanceId: 'instance-1',
            workerGroup: 'emails',
            host: 'app-01',
            pid: 123,
            startedAt: $now,
            mode: WorkerMode::Auto,
            transport: 'postgres',
            queue: 'default',
        );

        $this->worker = new WorkerInstance($identity, WorkerLifecycleState::Running, WorkerActivityState::Idle, $now);
        $this->child = new WorkerChildInstance('child-1', 'instance-1', 456, WorkerChildState::Running, $now, $now);
        $this->acknowledgement = new WorkerControlAcknowledgement(
            'command-1',
            'instance-1',
            WorkerControlAcknowledgementState::Applied,
            $now,
        );
    }

    public function getWorker(string $workerInstanceId): ?WorkerInstance
    {
        $this->calls[] = ['getWorker', $workerInstanceId];

        return $this->worker;
    }

    public function listWorkers(WorkerTarget $target): array
    {
        $this->calls[] = ['listWorkers', $target];

        return [$this->worker];
    }

    public function listChildren(string $workerInstanceId): array
    {
        $this->calls[] = ['listChildren', $workerInstanceId];

        return [$this->child];
    }

    public function acknowledgementsForCommand(string $commandId): array
    {
        $this->calls[] = ['acknowledgementsForCommand', $commandId];

        return [$this->acknowledgement];
    }
}
