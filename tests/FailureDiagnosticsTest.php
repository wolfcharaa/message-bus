<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Adapter\InMemoryDeliveryBackend;
use Wolfcharaa\MessageBus\Attribute\Handler;
use Wolfcharaa\MessageBus\Attribute\MessageName;
use Wolfcharaa\MessageBus\Codec\EncodedPayload;
use Wolfcharaa\MessageBus\Codec\JsonMessageCodec;
use Wolfcharaa\MessageBus\Codec\MessageCodecs;
use Wolfcharaa\MessageBus\Context\CancellationSignal;
use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Context\MessageContextFactories;
use Wolfcharaa\MessageBus\Context\MessageContextFactory;
use Wolfcharaa\MessageBus\Context\MessageContextFactoryResolver;
use Wolfcharaa\MessageBus\Context\MessageContextSeed;
use Wolfcharaa\MessageBus\Contract\Command;
use Wolfcharaa\MessageBus\Contract\Message;
use Wolfcharaa\MessageBus\Contract\MessageBus;
use Wolfcharaa\MessageBus\Contract\Query;
use Wolfcharaa\MessageBus\DefaultMessageBus;
use Wolfcharaa\MessageBus\Delivery\CancellationReceipt;
use Wolfcharaa\MessageBus\Delivery\ClaimedDelivery;
use Wolfcharaa\MessageBus\Delivery\ControlActor;
use Wolfcharaa\MessageBus\Delivery\DeliveryAcceptance;
use Wolfcharaa\MessageBus\Delivery\DeliveryAttempt;
use Wolfcharaa\MessageBus\Delivery\DeliveryDraft;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\Delivery\DeliveryState;
use Wolfcharaa\MessageBus\Delivery\DeliveryStatus;
use Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend;
use Wolfcharaa\MessageBus\Delivery\FailureCategory;
use Wolfcharaa\MessageBus\Delivery\FailureClassifier;
use Wolfcharaa\MessageBus\Delivery\FailureClassifiers;
use Wolfcharaa\MessageBus\Delivery\MaintenanceReport;
use Wolfcharaa\MessageBus\Delivery\ReplayReceipt;
use Wolfcharaa\MessageBus\DurableRuntime;
use Wolfcharaa\MessageBus\Exception\CommandNotAccepted;
use Wolfcharaa\MessageBus\Exception\DeliverySettlementFailed;
use Wolfcharaa\MessageBus\Exception\IncompatibleDeliveryFailure;
use Wolfcharaa\MessageBus\Exception\TerminalDeliveryFailure;
use Wolfcharaa\MessageBus\HandlerServiceResolver;
use Wolfcharaa\MessageBus\HandlerServices;
use Wolfcharaa\MessageBus\Idempotency\AtomicIdempotencyBoundary;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyAttempt;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentity;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentityResolver;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentityResolvers;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyKey;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyResult;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyRuntime;
use Wolfcharaa\MessageBus\Idempotency\IntentFingerprint;
use Wolfcharaa\MessageBus\Idempotency\TargetIdempotencyResolver;
use Wolfcharaa\MessageBus\Identity\AttemptId;
use Wolfcharaa\MessageBus\Identity\CorrelationId;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\LeaseToken;
use Wolfcharaa\MessageBus\Identity\MessageId;
use Wolfcharaa\MessageBus\Metadata\MessageMetadata;
use Wolfcharaa\MessageBus\Observability\FailureDiagnostic;
use Wolfcharaa\MessageBus\Observability\FailureStage;
use Wolfcharaa\MessageBus\Observability\RuntimeEvent;
use Wolfcharaa\MessageBus\Observability\RuntimeEventType;
use Wolfcharaa\MessageBus\Observability\RuntimeFailureSink;
use Wolfcharaa\MessageBus\Observability\RuntimeObserver;
use Wolfcharaa\MessageBus\Registry\ExplicitClassSource;
use Wolfcharaa\MessageBus\Registry\MessageRole;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;
use Wolfcharaa\MessageBus\Routing\DeliveryMode;
use Wolfcharaa\MessageBus\Routing\DeliveryRoute;
use Wolfcharaa\MessageBus\Routing\DeliveryRoutes;
use Wolfcharaa\MessageBus\Worker\DeliveryWorkerRuntime;
use Wolfcharaa\MessageBus\Worker\WorkerIteration;

final class FailureDiagnosticsTest extends TestCase
{
    public function testMbd001DispatchFailureIdentifiesMessageAndPreservesContainerException(): void
    {
        $containerFailure = new DiagnosticContainerFailure('sensitive container detail');
        $observer = new DiagnosticRuntimeObserver();
        $registry = (new RegistryCompiler())->compile(new ExplicitClassSource([
            DiagnosticQuery::class,
            DiagnosticQueryHandler::class,
        ]))->requireRegistry();
        $bus = new DefaultMessageBus(
            $registry,
            new DiagnosticFailingResolver($containerFailure),
            new DeliveryRoutes([]),
            observer: $observer,
        );

        try {
            $bus->dispatch(new DiagnosticQuery());
            self::fail('Dispatch container failure was not rethrown.');
        } catch (DiagnosticContainerFailure $failure) {
            self::assertSame($containerFailure, $failure);
        }

        $event = $observer->last();
        $this->assertFailureEvent(
            $event,
            FailureStage::Dispatch,
            DiagnosticContainerFailure::class,
        );
        self::assertSame(RuntimeEventType::DispatchFailed, $event->type);
        self::assertSame(DiagnosticQuery::class, $event->messageName);
    }

    public function testMbd101PayloadDecodeFailureIdentifiesCodecBoundaryAndUnderlyingCause(): void
    {
        $fixture = $this->workerFixture(codecs: new MessageCodecs([], []));

        self::assertSame(WorkerIteration::Failed, $fixture->worker->execute($fixture->claim));

        $this->assertSettledFailure(
            $fixture,
            FailureStage::PayloadDecode,
            IncompatibleDeliveryFailure::class,
            \LogicException::class,
        );
        self::assertInstanceOf(\LogicException::class, $fixture->failures->records[0]['failure']->getPrevious());
    }

    public function testMbd102TargetResolutionFailureNamesPersistedTargetMismatch(): void
    {
        $fixture = $this->workerFixture(targetId: 'target:missing-from-registry');

        self::assertSame(WorkerIteration::Failed, $fixture->worker->execute($fixture->claim));

        $this->assertSettledFailure(
            $fixture,
            FailureStage::TargetResolution,
            IncompatibleDeliveryFailure::class,
            TerminalDeliveryFailure::class,
        );
        self::assertSame('target:missing-from-registry', $fixture->failures->lastEvent()->targetId);
    }

    public function testMbd103ContextCreationFailureNamesFactoryBoundaryAndException(): void
    {
        $contextFailure = new DiagnosticContextCreationFailure('sensitive context detail');
        $fixture = $this->workerFixture(
            contexts: $this->contexts(new DiagnosticContextFactory($contextFailure)),
        );

        self::assertSame(WorkerIteration::Failed, $fixture->worker->execute($fixture->claim));

        $this->assertSettledFailure(
            $fixture,
            FailureStage::ContextCreation,
            DiagnosticContextCreationFailure::class,
        );
        self::assertSame($contextFailure, $fixture->failures->records[0]['failure']);
    }

    public function testMbd104HandlerResolutionFailurePreservesTheExactDiException(): void
    {
        $containerFailure = new DiagnosticContainerFailure('sensitive DI detail');
        $fixture = $this->workerFixture(services: new DiagnosticFailingResolver($containerFailure));

        try {
            $fixture->worker->execute($fixture->claim);
            self::fail('Worker handler resolution failure was not rethrown.');
        } catch (DiagnosticContainerFailure $failure) {
            self::assertSame($containerFailure, $failure);
        }

        $this->assertSettledFailure(
            $fixture,
            FailureStage::HandlerResolution,
            DiagnosticContainerFailure::class,
        );
        self::assertSame($containerFailure, $fixture->failures->records[0]['failure']);
    }

    public function testMbd105HandlerExecutionFailureIdentifiesHandlerBoundaryAndClass(): void
    {
        $handlerFailure = new DiagnosticHandlerFailure('sensitive handler detail');
        $fixture = $this->workerFixture(handler: new DiagnosticCommandHandler($handlerFailure));

        self::assertSame(WorkerIteration::Failed, $fixture->worker->execute($fixture->claim));

        $this->assertSettledFailure(
            $fixture,
            FailureStage::HandlerExecution,
            DiagnosticHandlerFailure::class,
        );
        self::assertSame($handlerFailure, $fixture->failures->records[0]['failure']);
    }

    public function testMbd106IdempotencyFailureIdentifiesAtomicBoundaryAndClass(): void
    {
        $idempotencyFailure = new DiagnosticIdempotencyFailure('sensitive idempotency detail');
        $fixture = $this->workerFixture(
            policy: new DeliveryPolicy('idempotent', idempotent: true),
            idempotency: new IdempotencyRuntime(
                new IdempotencyIdentityResolvers([
                    new TargetIdempotencyResolver(DiagnosticCommand::class, new DiagnosticIdentityResolver()),
                ]),
                new DiagnosticFailingIdempotencyBoundary($idempotencyFailure),
            ),
        );

        self::assertSame(WorkerIteration::Failed, $fixture->worker->execute($fixture->claim));

        $this->assertSettledFailure(
            $fixture,
            FailureStage::Idempotency,
            DiagnosticIdempotencyFailure::class,
        );
        self::assertSame($idempotencyFailure, $fixture->failures->records[0]['failure']);
    }

    public function testMbd107ClassifierFailureRetainsClassifierAndPrimaryHandlerClasses(): void
    {
        $handlerFailure = new DiagnosticHandlerFailure('sensitive primary detail');
        $classifierFailure = new DiagnosticClassifierFailure('sensitive classifier detail');
        $fixture = $this->workerFixture(
            handler: new DiagnosticCommandHandler($handlerFailure),
            classifiers: new FailureClassifiers(new DiagnosticThrowingClassifier($classifierFailure)),
        );

        self::assertSame(WorkerIteration::Failed, $fixture->worker->execute($fixture->claim));

        $this->assertSettledFailure(
            $fixture,
            FailureStage::FailureClassification,
            DiagnosticClassifierFailure::class,
            DiagnosticHandlerFailure::class,
        );
        self::assertSame([$handlerFailure, $classifierFailure], array_column($fixture->failures->records, 'failure'));
        self::assertSame(FailureCategory::ClassifierError, $fixture->backend->settledCategory);
    }

    public function testMbd108SettlementFailureRetainsBackendAndPrimaryHandlerFailures(): void
    {
        $handlerFailure = new DiagnosticHandlerFailure('sensitive primary detail');
        $settlementFailure = new DiagnosticSettlementFailure('sensitive settlement detail');
        $fixture = $this->workerFixture(
            handler: new DiagnosticCommandHandler($handlerFailure),
            settlementFailure: $settlementFailure,
        );

        try {
            $fixture->worker->execute($fixture->claim);
            self::fail('Settlement failure did not escape as DeliverySettlementFailed.');
        } catch (DeliverySettlementFailed $failure) {
            self::assertSame($handlerFailure, $failure->primaryFailure);
            self::assertSame($settlementFailure, $failure->settlementFailure);
            self::assertSame($handlerFailure, $failure->getPrevious());
            self::assertSame($fixture->claim->delivery->deliveryId, $failure->deliveryId);
            self::assertSame($fixture->claim->attempt->attemptId, $failure->attemptId);
        }

        self::assertCount(2, $fixture->failures->records);
        self::assertSame([$handlerFailure, $settlementFailure], array_column($fixture->failures->records, 'failure'));
        $this->assertFailureEvent(
            $fixture->failures->lastEvent(),
            FailureStage::DeliverySettlement,
            DiagnosticSettlementFailure::class,
            DiagnosticHandlerFailure::class,
        );
    }

    public function testMbd109ForkFailurePersistsExactWorkerStage(): void
    {
        $fixture = $this->workerFixture();

        self::assertSame(WorkerIteration::Failed, $fixture->worker->forkFailed($fixture->claim));

        self::assertNotNull($fixture->backend->settledFailure);
        $this->assertFailureDiagnostic(
            $fixture->backend->settledFailure,
            FailureStage::WorkerFork,
            \RuntimeException::class,
        );
    }

    public function testMbd110LeaseRecoveryPersistsLostAttemptAndAttemptIdentity(): void
    {
        $clock = new DiagnosticClock(new \DateTimeImmutable('2026-11-27T10:00:00+00:00'));
        $backend = new InMemoryDeliveryBackend($clock);
        $policy = new DeliveryPolicy(
            'lease-recovery',
            maxAttempts: 2,
            leaseSeconds: 1,
            idempotent: true,
            replayWindowSeconds: 3_600,
            retryLostAttempts: true,
        );
        $draft = $this->draft(policy: $policy);
        $backend->accept($draft);
        $claim = $backend->claim('default', 'worker:lease-recovery');
        self::assertNotNull($claim);
        $clock->advance('+2 seconds');

        self::assertSame(1, $backend->recoverExpired(1)->changed);

        $status = $backend->status($draft->deliveryId);
        self::assertNotNull($status);
        self::assertSame(DeliveryState::RetryWait, $status->state);
        self::assertEquals($claim->attempt->attemptId, $status->lastFailureAttemptId);
        self::assertNotNull($status->lastFailure);
        $this->assertFailureDiagnostic(
            $status->lastFailure,
            FailureStage::LeaseRecovery,
            \RuntimeException::class,
        );
    }

    public function testMbd111DeliveryAcceptanceFailureNamesStageAndPreservesBackendCause(): void
    {
        $acceptanceFailure = new DiagnosticAcceptanceFailure('sensitive backend detail');
        $backend = new DiagnosticBackend(acceptanceFailure: $acceptanceFailure);
        $observer = new DiagnosticRuntimeObserver();
        $contexts = $this->contexts();
        $registry = (new RegistryCompiler($contexts))->compile(new ExplicitClassSource([
            DiagnosticCommand::class,
            DiagnosticCommandHandler::class,
        ]))->requireRegistry();
        $handler = new DiagnosticCommandHandler();
        $codecs = $this->codecs();
        $bus = new DefaultMessageBus(
            $registry,
            new HandlerServices([DiagnosticCommandHandler::class => $handler]),
            new DeliveryRoutes([
                DiagnosticCommand::class => new DeliveryRoute(DeliveryMode::Durable),
            ]),
            $contexts,
            durable: new DurableRuntime(
                $backend,
                $codecs,
                new DeliveryPolicies(['default' => new DeliveryPolicy('default')]),
            ),
            observer: $observer,
        );

        try {
            $bus->dispatch(new DiagnosticCommand('acceptance'));
            self::fail('Durable acceptance failure was not exposed.');
        } catch (CommandNotAccepted $failure) {
            self::assertSame(FailureStage::DeliveryAcceptance, $failure->stage);
            self::assertSame($acceptanceFailure, $failure->getPrevious());
            self::assertSame(DiagnosticCommand::class, $failure->targetId);
            self::assertNotNull($failure->messageId);
        }

        $event = $observer->last();
        $this->assertFailureEvent(
            $event,
            FailureStage::DeliveryAcceptance,
            CommandNotAccepted::class,
            DiagnosticAcceptanceFailure::class,
        );
        self::assertSame(DiagnosticCommand::class, $event->messageName);
    }

    private function workerFixture(
        ?MessageCodecs $codecs = null,
        string $targetId = DiagnosticCommand::class,
        ?MessageContextFactoryResolver $contexts = null,
        ?HandlerServiceResolver $services = null,
        ?DiagnosticCommandHandler $handler = null,
        ?DeliveryPolicy $policy = null,
        ?IdempotencyRuntime $idempotency = null,
        ?FailureClassifiers $classifiers = null,
        ?\Throwable $settlementFailure = null,
    ): DiagnosticWorkerFixture {
        $contexts ??= $this->contexts();
        $handler ??= new DiagnosticCommandHandler();
        $services ??= new HandlerServices([DiagnosticCommandHandler::class => $handler]);
        $policy ??= new DeliveryPolicy('default');
        $backend = new DiagnosticBackend(settlementFailure: $settlementFailure);
        $failures = new DiagnosticFailureSink();
        $registry = (new RegistryCompiler($contexts))->compile(new ExplicitClassSource([
            DiagnosticCommand::class,
            DiagnosticCommandHandler::class,
        ]))->requireRegistry();
        $draft = $this->draft(
            targetId: $targetId,
            policy: $policy,
        );
        $claim = new ClaimedDelivery(
            $draft,
            new DeliveryAttempt(
                new AttemptId('attempt:diagnostic-stage'),
                $draft->deliveryId,
                1,
                new \DateTimeImmutable('2026-11-27T10:00:00+00:00'),
            ),
            new LeaseToken('lease:diagnostic-stage'),
            new \DateTimeImmutable('2026-11-27T10:01:00+00:00'),
        );
        $worker = new DeliveryWorkerRuntime(
            $backend,
            $codecs ?? $this->codecs(),
            $registry,
            $services,
            self::createStub(MessageBus::class),
            $contexts,
            classifiers: $classifiers ?? new FailureClassifiers(),
            idempotency: $idempotency,
            runtimeFailureSink: $failures,
        );

        return new DiagnosticWorkerFixture($worker, $backend, $claim, $failures);
    }

    /** @param MessageContextFactory<MessageContext>|null $factory */
    private function contexts(?MessageContextFactory $factory = null): MessageContextFactories
    {
        return new MessageContextFactories([
            DiagnosticContext::class => $factory ?? new DiagnosticContextFactory(),
        ]);
    }

    private function codecs(): MessageCodecs
    {
        $codec = new JsonMessageCodec(DiagnosticCommand::class);

        return new MessageCodecs(
            [DiagnosticCommand::class => $codec],
            ['diagnostic.command' => [1 => $codec]],
        );
    }

    private function draft(
        string $targetId = DiagnosticCommand::class,
        ?DeliveryPolicy $policy = null,
    ): DeliveryDraft {
        return new DeliveryDraft(
            new DeliveryId('delivery:diagnostic-stage'),
            new MessageMetadata(
                new MessageId('message:diagnostic-stage'),
                new CorrelationId('correlation:diagnostic-stage'),
                null,
                new \DateTimeImmutable('2026-11-27T10:00:00+00:00'),
            ),
            new EncodedPayload('diagnostic.command', 1, 'json', '{"value":"stage"}'),
            $targetId,
            DiagnosticCommandHandler::class,
            '__invoke',
            'default',
            $policy ?? new DeliveryPolicy('default'),
            role: MessageRole::Command,
        );
    }

    private function assertSettledFailure(
        DiagnosticWorkerFixture $fixture,
        FailureStage $stage,
        string $failureClass,
        ?string $relatedFailureClass = null,
    ): void {
        self::assertNotNull($fixture->backend->settledFailure);
        $this->assertFailureDiagnostic(
            $fixture->backend->settledFailure,
            $stage,
            $failureClass,
            $relatedFailureClass,
        );
        $this->assertFailureEvent(
            $fixture->failures->lastEvent(),
            $stage,
            $failureClass,
            $relatedFailureClass,
        );
    }

    private function assertFailureDiagnostic(
        FailureDiagnostic $diagnostic,
        FailureStage $stage,
        string $failureClass,
        ?string $relatedFailureClass = null,
    ): void {
        self::assertSame($stage, $diagnostic->stage);
        self::assertSame($stage->code(), $diagnostic->code);
        self::assertSame($stage->summary(), $diagnostic->summary);
        self::assertSame($failureClass, $diagnostic->failureClass);
        self::assertSame($relatedFailureClass, $diagnostic->relatedFailureClass);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $diagnostic->fingerprint);
        self::assertStringNotContainsString('sensitive', $diagnostic->encode());
    }

    private function assertFailureEvent(
        RuntimeEvent $event,
        FailureStage $stage,
        string $failureClass,
        ?string $relatedFailureClass = null,
    ): void {
        self::assertSame($stage->code(), $event->failureCode);
        self::assertSame($stage->value, $event->failureStage);
        self::assertSame($stage->summary(), $event->failureSummary);
        self::assertSame($failureClass, $event->failureClass);
        self::assertSame($relatedFailureClass, $event->relatedFailureClass);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $event->errorFingerprint);
        self::assertStringNotContainsString('sensitive', json_encode($event->normalized(), JSON_THROW_ON_ERROR));
    }
}

final readonly class DiagnosticWorkerFixture
{
    public function __construct(
        public DeliveryWorkerRuntime $worker,
        public DiagnosticBackend $backend,
        public ClaimedDelivery $claim,
        public DiagnosticFailureSink $failures,
    ) {}
}

final class DiagnosticBackend implements DurableDeliveryBackend
{
    public ?FailureDiagnostic $settledFailure = null;

    public ?FailureCategory $settledCategory = null;

    public function __construct(
        private readonly ?\Throwable $settlementFailure = null,
        private readonly ?\Throwable $acceptanceFailure = null,
    ) {}

    public function accept(DeliveryDraft $delivery): DeliveryAcceptance
    {
        if ($this->acceptanceFailure instanceof \Throwable) {
            throw $this->acceptanceFailure;
        }

        return new DeliveryAcceptance($delivery->deliveryId, DeliveryState::Accepted);
    }

    public function claim(string $lane, string $workerId): ?ClaimedDelivery
    {
        return null;
    }

    public function heartbeat(ClaimedDelivery $claim): bool
    {
        return true;
    }

    public function cancellationRequested(ClaimedDelivery $claim): bool
    {
        return false;
    }

    public function markHandlerStarted(ClaimedDelivery $claim, ?int $childPid = null): bool
    {
        return true;
    }

    public function succeed(ClaimedDelivery $claim): bool
    {
        return true;
    }

    public function fail(ClaimedDelivery $claim, FailureCategory $category, string $summary): bool
    {
        if ($this->settlementFailure instanceof \Throwable) {
            throw $this->settlementFailure;
        }
        $diagnostic = FailureDiagnostic::tryFromEncoded($summary);
        if (!$diagnostic instanceof FailureDiagnostic) {
            throw new \LogicException('Worker did not provide a canonical FailureDiagnostic.');
        }
        $this->settledCategory = $category;
        $this->settledFailure = $diagnostic;

        return true;
    }

    public function status(DeliveryId $deliveryId): ?DeliveryStatus
    {
        return null;
    }

    public function cancel(DeliveryId $deliveryId, ControlActor $actor): CancellationReceipt
    {
        throw new \LogicException('Not used by diagnostic tests.');
    }

    public function recoverExpired(int $limit): MaintenanceReport
    {
        throw new \LogicException('Not used by diagnostic tests.');
    }

    public function replay(DeliveryId $deliveryId, DeliveryPolicy $policy, ControlActor $actor): ReplayReceipt
    {
        throw new \LogicException('Not used by diagnostic tests.');
    }

    public function prune(\DateTimeImmutable $terminalBefore, int $limit, bool $execute): MaintenanceReport
    {
        throw new \LogicException('Not used by diagnostic tests.');
    }
}

final class DiagnosticFailureSink implements RuntimeFailureSink
{
    /** @var list<array{failure: \Throwable, event: RuntimeEvent}> */
    public array $records = [];

    public function report(\Throwable $failure, RuntimeEvent $event): void
    {
        $this->records[] = ['failure' => $failure, 'event' => $event];
    }

    public function lastEvent(): RuntimeEvent
    {
        if ($this->records === []) {
            throw new \LogicException('No runtime failure has been reported.');
        }

        return $this->records[\count($this->records) - 1]['event'];
    }
}

final class DiagnosticRuntimeObserver implements RuntimeObserver
{
    /** @var list<RuntimeEvent> */
    public array $events = [];

    public function observe(RuntimeEvent $event): void
    {
        $this->events[] = $event;
    }

    public function last(): RuntimeEvent
    {
        if ($this->events === []) {
            throw new \LogicException('No runtime event has been observed.');
        }

        return $this->events[\count($this->events) - 1];
    }
}

/** @implements Query<string> */
final readonly class DiagnosticQuery implements Query {}

final readonly class DiagnosticQueryHandler
{
    #[Handler]
    public function __invoke(DiagnosticQuery $query): string
    {
        return $query::class;
    }
}

#[MessageName('diagnostic.command')]
final readonly class DiagnosticCommand implements Command
{
    public function __construct(public string $value) {}
}

final readonly class DiagnosticCommandHandler
{
    public function __construct(private ?\Throwable $failure = null) {}

    #[Handler]
    public function __invoke(DiagnosticCommand $command, DiagnosticContext $context): void
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }
    }
}

final readonly class DiagnosticContext implements MessageContext
{
    public function __construct(private MessageContextSeed $seed) {}

    public function dispatch(Message $message): mixed
    {
        return $this->seed->dispatcher->dispatch($message);
    }

    public function metadata(): MessageMetadata
    {
        return $this->seed->metadata;
    }

    public function cancellation(): CancellationSignal
    {
        return $this->seed->cancellation;
    }
}

/** @implements MessageContextFactory<MessageContext> */
final readonly class DiagnosticContextFactory implements MessageContextFactory
{
    public function __construct(private ?\Throwable $failure = null) {}

    public function create(MessageContextSeed $seed): MessageContext
    {
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return new DiagnosticContext($seed);
    }
}

final readonly class DiagnosticFailingResolver implements HandlerServiceResolver
{
    public function __construct(private \Throwable $failure) {}

    public function get(string $class): object
    {
        throw $this->failure;
    }

    public function has(string $class): bool
    {
        return true;
    }
}

final readonly class DiagnosticIdentityResolver implements IdempotencyIdentityResolver
{
    public function resolve(Message $message, string $targetId): IdempotencyIdentity
    {
        return new IdempotencyIdentity(
            $targetId,
            new IdempotencyKey('diagnostic-idempotency-key'),
            new IntentFingerprint('sha256', 1, hash('sha256', $message::class)),
        );
    }
}

final readonly class DiagnosticFailingIdempotencyBoundary implements AtomicIdempotencyBoundary
{
    public function __construct(private \Throwable $failure) {}

    public function execute(IdempotencyAttempt $attempt, callable $effect): IdempotencyResult
    {
        throw $this->failure;
    }
}

final readonly class DiagnosticThrowingClassifier implements FailureClassifier
{
    public function __construct(private \Throwable $failure) {}

    public function classify(\Throwable $failure): ?FailureCategory
    {
        throw $this->failure;
    }
}

final class DiagnosticClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $current) {}

    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(string $modifier): void
    {
        $this->current = $this->current->modify($modifier);
    }
}

final class DiagnosticContainerFailure extends \RuntimeException {}

final class DiagnosticContextCreationFailure extends \RuntimeException {}

final class DiagnosticHandlerFailure extends \RuntimeException {}

final class DiagnosticIdempotencyFailure extends \RuntimeException {}

final class DiagnosticClassifierFailure extends \RuntimeException {}

final class DiagnosticSettlementFailure extends \RuntimeException {}

final class DiagnosticAcceptanceFailure extends \RuntimeException {}
