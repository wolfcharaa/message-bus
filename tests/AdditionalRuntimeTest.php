<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Wolfcharaa\MessageBus\Adapter\InMemoryDeliveryBackend;
use Wolfcharaa\MessageBus\Attribute\Handler;
use Wolfcharaa\MessageBus\Attribute\MessageName;
use Wolfcharaa\MessageBus\Attribute\Payload;
use Wolfcharaa\MessageBus\Codec\EncodedPayload;
use Wolfcharaa\MessageBus\Codec\JsonMessageCodec;
use Wolfcharaa\MessageBus\Codec\MessageCodecs;
use Wolfcharaa\MessageBus\Codec\MessageUpcaster;
use Wolfcharaa\MessageBus\Codec\PhpNativeMessageCodec;
use Wolfcharaa\MessageBus\Codec\PhpNativeTypeGraphCompiler;
use Wolfcharaa\MessageBus\Codec\UpcastingJsonMessageCodec;
use Wolfcharaa\MessageBus\Context\CancellationRequested;
use Wolfcharaa\MessageBus\Context\DefaultMessageContextFactory;
use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Context\MessageContextFactories;
use Wolfcharaa\MessageBus\Context\MessageContextSeed;
use Wolfcharaa\MessageBus\Context\NullCancellationSignal;
use Wolfcharaa\MessageBus\Contract\Command;
use Wolfcharaa\MessageBus\Contract\Message;
use Wolfcharaa\MessageBus\Contract\MessageBus;
use Wolfcharaa\MessageBus\Contract\Query;
use Wolfcharaa\MessageBus\Delivery\CancellationOutcome;
use Wolfcharaa\MessageBus\Delivery\ControlActor;
use Wolfcharaa\MessageBus\Delivery\DeliveryAcceptance;
use Wolfcharaa\MessageBus\Delivery\DeliveryDraft;
use Wolfcharaa\MessageBus\Delivery\DeliveryMaintenance;
use Wolfcharaa\MessageBus\Delivery\DeliveryOperations;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\Delivery\DeliveryState;
use Wolfcharaa\MessageBus\Delivery\FailureCategory;
use Wolfcharaa\MessageBus\Delivery\FailureClassifier;
use Wolfcharaa\MessageBus\Delivery\FailureClassifiers;
use Wolfcharaa\MessageBus\Exception\IncompatibleDeliveryFailure;
use Wolfcharaa\MessageBus\HandlerServices;
use Wolfcharaa\MessageBus\Idempotency\AtomicIdempotencyBoundary;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyAttempt;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentity;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentityResolver;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentityResolvers;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyKey;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyOutcome;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyResult;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyRuntime;
use Wolfcharaa\MessageBus\Idempotency\IntentFingerprint;
use Wolfcharaa\MessageBus\Idempotency\TargetIdempotencyResolver;
use Wolfcharaa\MessageBus\Identity\CorrelationId;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\MessageId;
use Wolfcharaa\MessageBus\Identity\RandomIdentityGenerator;
use Wolfcharaa\MessageBus\Internal\Runtime\BestEffortRuntimeObserver;
use Wolfcharaa\MessageBus\Internal\Runtime\DeliveryCancellationSignal;
use Wolfcharaa\MessageBus\Internal\Runtime\OneShotNextHandler;
use Wolfcharaa\MessageBus\Metadata\MessageMetadata;
use Wolfcharaa\MessageBus\Metadata\MetadataSchema;
use Wolfcharaa\MessageBus\Observability\NullObserverFailureSink;
use Wolfcharaa\MessageBus\Observability\ObserverFailureSink;
use Wolfcharaa\MessageBus\Observability\PsrLogRuntimeObserver;
use Wolfcharaa\MessageBus\Observability\RuntimeEvent;
use Wolfcharaa\MessageBus\Observability\RuntimeEventType;
use Wolfcharaa\MessageBus\Observability\RuntimeObserver;
use Wolfcharaa\MessageBus\Receipt\PublicationEntry;
use Wolfcharaa\MessageBus\Receipt\PublicationOutcome;
use Wolfcharaa\MessageBus\Receipt\PublicationReceipt;
use Wolfcharaa\MessageBus\Registry\CompiledRegistry;
use Wolfcharaa\MessageBus\Registry\ComposerClassMapSource;
use Wolfcharaa\MessageBus\Registry\DiagnosticSeverity;
use Wolfcharaa\MessageBus\Registry\ExplicitClassSource;
use Wolfcharaa\MessageBus\Registry\RegistryArtifact;
use Wolfcharaa\MessageBus\Registry\RegistryArtifactStore;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryValidationRule;
use Wolfcharaa\MessageBus\Routing\DeliveryMode;
use Wolfcharaa\MessageBus\Routing\DeliveryRoute;
use Wolfcharaa\MessageBus\Routing\DeliveryRoutes;
use Wolfcharaa\MessageBus\Routing\RuntimeConfigurationRule;
use Wolfcharaa\MessageBus\Worker\DefaultFailureClassifier;
use Wolfcharaa\MessageBus\Worker\DeliveryWorkerRuntime;
use Wolfcharaa\MessageBus\Worker\WorkerIteration;
use Wolfcharaa\MessageBus\Worker\WorkerProfile;

final class AdditionalRuntimeTest extends TestCase
{
    public function testValueObjectsAndReceiptInvariantsFailClosed(): void
    {
        $failures = [
            static fn(): MessageId => new MessageId('short'),
            static fn(): DeliveryPolicy => new DeliveryPolicy('', 0),
            static fn(): DeliveryPolicy => new DeliveryPolicy(
                'short-retention',
                idempotent: true,
                idempotencyRetentionSeconds: 1,
            ),
            static fn(): DeliveryPolicy => new DeliveryPolicy('unsafe-lost-retry', retryLostAttempts: true),
            static fn(): DeliveryRoute => new DeliveryRoute(DeliveryMode::Inline, ''),
            static fn(): ControlActor => new ControlActor('', ''),
            static fn(): ControlActor => new ControlActor('tester', 'invalid operation', operationId: 'short'),
            static fn(): ControlActor => new ControlActor(
                'tester',
                'oversized metadata',
                metadata: ['blob' => str_repeat('x', 4_097)],
            ),
            static fn(): Handler => new Handler('INVALID'),
            static fn(): MessageName => new MessageName('INVALID'),
            static fn(): Payload => new Payload(0, 'x'),
            static fn(): EncodedPayload => new EncodedPayload('', 0, '', ''),
            static fn(): EncodedPayload => new EncodedPayload('test.message', 1, 'json', '{}', 'invalid'),
            static fn(): DeliveryAcceptance => new DeliveryAcceptance(new DeliveryId('delivery:invalid-acceptance'), DeliveryState::Succeeded),
            static fn(): WorkerProfile => new WorkerProfile('', '', 0, 0, 0, 0),
            static fn(): MessageMetadata => new MessageMetadata(
                new MessageId('message:oversized-metadata'),
                new CorrelationId('correlation:oversized-metadata'),
                null,
                new \DateTimeImmutable('2026-11-22T10:00:00+00:00'),
                ['blob' => str_repeat('x', 16_385)],
            ),
            static fn() => (new MetadataSchema(['tenant']))->validate(['other' => 'x']),
            static fn(): PublicationEntry => new PublicationEntry('', PublicationOutcome::Completed),
            static fn(): PublicationEntry => new PublicationEntry('target', PublicationOutcome::Accepted),
            static fn(): PublicationEntry => new PublicationEntry('target', PublicationOutcome::Completed, failureCategory: 'x'),
        ];
        foreach ($failures as $failure) {
            try {
                $failure();
                self::fail('Invalid value was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame('application/json', (new EncodedPayload('test.message', 1, 'json', '{}'))->contentType());
        self::assertSame(
            'application/vnd.php.serialized',
            (new EncodedPayload('test.message', 1, 'php-native', 'payload'))->contentType(),
        );
        self::assertSame(
            'application/octet-stream',
            (new EncodedPayload('test.message', 1, 'custom', 'payload'))->contentType(),
        );

        $messageId = new MessageId('message:value-contract');
        $entry = new PublicationEntry('target', PublicationOutcome::Completed);
        $receipt = new PublicationReceipt($messageId, [$entry]);
        self::assertTrue($receipt->isComplete());

        try {
            (new HandlerServices([]))->get(ChangedEmailHandler::class);
            self::fail('Unknown handler service was returned.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        $identities = new RandomIdentityGenerator();
        foreach ([
            $identities->messageId(),
            $identities->deliveryId(),
            $identities->attemptId(),
            $identities->correlationId(),
            $identities->leaseToken(),
        ] as $identity) {
            self::assertSame($identity->value, (string) $identity);
        }

        $this->expectException(\InvalidArgumentException::class);
        new PublicationReceipt($messageId, [$entry, $entry]);
    }

    public function testContextFactoryMetadataAndCancellationContracts(): void
    {
        $metadata = new MessageMetadata(
            new MessageId('message:context-contract'),
            new CorrelationId('correlation:context-contract'),
            null,
            new \DateTimeImmutable('2026-11-22T10:00:00+00:00'),
        );
        $dispatcher = self::createStub(MessageBus::class);
        $dispatcher->method('dispatch')->willReturn('handled');
        $context = (new DefaultMessageContextFactory())->create(new MessageContextSeed(
            $dispatcher,
            $metadata,
            new NullCancellationSignal(),
        ));

        self::assertSame($metadata, $context->metadata());
        self::assertFalse($context->cancellation()->isRequested());
        $context->cancellation()->throwIfRequested();
        self::assertSame('handled', $context->dispatch(new ContextMessage()));
        $factories = new MessageContextFactories();
        self::assertTrue($factories->has(MessageContext::class));
        self::assertInstanceOf(MessageContext::class, $factories->factory(MessageContext::class)->create(
            new MessageContextSeed($dispatcher, $metadata, new NullCancellationSignal()),
        ));

        $this->expectException(\LogicException::class);
        $factories->factory(UnknownContext::class);
    }

    public function testInMemoryBackendCoversCancellationRecoveryReplayAndPrune(): void
    {
        $clock = new MutableClock(new \DateTimeImmutable('2026-11-22T10:00:00+00:00'));
        $backend = new InMemoryDeliveryBackend($clock, new SequenceIdentities());
        $actor = new ControlActor('tester', 'coverage');
        $missing = new DeliveryId('delivery:not-found');
        self::assertSame(CancellationOutcome::NotFound, $backend->cancel($missing, $actor)->outcome);

        $draft = $this->draft('delivery:cancel-running', new DeliveryPolicy('retry', maxAttempts: 2));
        $backend->accept($draft);
        self::assertNull($backend->claim('other', 'worker'));
        $claim = $backend->claim('default', 'worker');
        self::assertNotNull($claim);
        self::assertSame(CancellationOutcome::RequestedRunning, $backend->cancel($draft->deliveryId, $actor)->outcome);
        self::assertSame(CancellationOutcome::AlreadyRequested, $backend->cancel($draft->deliveryId, $actor)->outcome);
        self::assertTrue($backend->cancellationRequested($claim));
        self::assertTrue($backend->fail($claim, FailureCategory::Cancelled, 'cancelled'));
        self::assertFalse($backend->succeed($claim));
        self::assertSame(CancellationOutcome::AlreadyTerminal, $backend->cancel($draft->deliveryId, $actor)->outcome);
        $replayed = $backend->replay($draft->deliveryId, new DeliveryPolicy('manual'), $actor);
        $replayedStatus = $backend->status($replayed->newDeliveryId);
        self::assertNotNull($replayedStatus);
        self::assertSame(DeliveryState::Accepted, $replayedStatus->state);
        $replayedClaim = $backend->claim('default', 'worker');
        self::assertNotNull($replayedClaim);
        self::assertTrue($backend->fail($replayedClaim, FailureCategory::Terminal, 'test cleanup'));

        $expiring = $this->draft(
            'delivery:recover-expired',
            new DeliveryPolicy(
                'idempotent',
                maxAttempts: 2,
                leaseSeconds: 1,
                idempotent: true,
                replayWindowSeconds: 3_600,
                retryLostAttempts: true,
            ),
        );
        $backend->accept($expiring);
        self::assertNotNull($backend->claim('default', 'worker'));
        $clock->advance('+2 seconds');
        self::assertSame(1, $backend->recoverExpired(10)->changed);
        self::assertSame(DeliveryState::RetryWait, $backend->status($expiring->deliveryId)?->state);
        self::assertNotNull($backend->claim('default', 'worker'));
        $clock->advance('+2 seconds');
        self::assertSame(1, $backend->recoverExpired(10)->changed);
        self::assertSame(DeliveryState::OutcomeUnknown, $backend->status($expiring->deliveryId)->state);
        $backend->replay($expiring->deliveryId, new DeliveryPolicy('safe', idempotent: true), $actor);

        $clock->advance('+1 day');
        $plan = $backend->prune($clock->now(), 20, false);
        self::assertGreaterThanOrEqual(2, $plan->examined);
        self::assertSame(0, $plan->changed);
        self::assertGreaterThanOrEqual(2, $backend->prune($clock->now(), 20, true)->changed);
        self::assertNull($backend->status($draft->deliveryId));
    }

    public function testCodecsAndNativeGraphRejectUnregisteredOrUnsafeShapes(): void
    {
        $codec = new UpcastingJsonMessageCodec(ChangedEmail::class, [new AddressUpcaster()]);
        $message = $codec->decode('{"address":"ada@example.test"}');
        self::assertInstanceOf(ChangedEmail::class, $message);
        self::assertSame('ada@example.test', $message->email);
        self::assertSame('{"email":"ada@example.test"}', $codec->encode($message));

        $graph = (new PhpNativeTypeGraphCompiler())->compile(NativeExtraCommand::class);
        $native = new PhpNativeMessageCodec(NativeExtraCommand::class, $graph);
        $roundTrip = $native->decode($native->encode(new NativeExtraCommand(new NativeExtraValue('x'))));
        self::assertInstanceOf(NativeExtraCommand::class, $roundTrip);
        self::assertStringContainsString('fingerprint', $graph->toJson());

        $codecs = new MessageCodecs([], []);
        self::assertFalse($codecs->canEncode(ChangedEmail::class));
        self::assertInstanceOf(ContextMessage::class, (new JsonMessageCodec(ContextMessage::class))->decode('{}'));

        $failures = [
            static fn(): Message => $codecs->decode(new EncodedPayload('missing', 1, 'json', '{}')),
            static fn(): EncodedPayload => $codecs->encode(new ContextMessage()),
            static fn(): EncodedPayload => $codecs->encode(new ChangedEmail('missing@example.test')),
            static fn(): string => $codec->encode(new ContextMessage()),
            static fn(): Message => $codec->decode('[]'),
            static fn(): Message => $codec->decode('{"address":7}'),
            static fn(): string => (new JsonMessageCodec(ChangedEmail::class))->encode(new ContextMessage()),
            static fn(): Message => (new JsonMessageCodec(ChangedEmail::class))->decode('[]'),
            static fn(): string => $native->encode(new ContextMessage()),
            static fn(): Message => $native->decode(serialize(new ContextMessage())),
        ];
        foreach ($failures as $failure) {
            try {
                $failure();
                self::fail('Unsafe codec input was accepted.');
            } catch (\LogicException|\UnexpectedValueException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRegistrySourcesRulesAndArtifactStoreAreDeterministic(): void
    {
        $directory = sys_get_temp_dir() . '/message-bus-artifact-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $validMap = $directory . '/valid-classmap.php';
        $invalidMap = $directory . '/invalid-classmap.php';
        file_put_contents($validMap, '<?php return ' . var_export([
            0 => '/ignored.php',
            'Outside\Ignored' => '/ignored.php',
            __NAMESPACE__ . '\MissingClass' => '/missing.php',
            ChangedEmail::class => __FILE__,
        ], true) . ';');
        file_put_contents($invalidMap, '<?php return "invalid";');

        $source = new ComposerClassMapSource([$validMap], [__NAMESPACE__ . '\\']);
        self::assertSame([ChangedEmail::class], iterator_to_array($source->classes(), false));
        foreach ([
            static fn(): ComposerClassMapSource => new ComposerClassMapSource([], [__NAMESPACE__ . '\\']),
            static fn(): array => iterator_to_array(
                (new ComposerClassMapSource([$invalidMap], [__NAMESPACE__ . '\\']))->classes(),
                false,
            ),
        ] as $failure) {
            try {
                $failure();
                self::fail('Invalid Composer classmap source was accepted.');
            } catch (\InvalidArgumentException|\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }

        $compiler = new RegistryCompiler(rules: [new WarningRule()]);
        $result = $compiler->compile(new ExplicitClassSource([ChangedEmail::class, ChangedEmailHandler::class]));
        self::assertTrue($result->isValid());
        self::assertSame(DiagnosticSeverity::Warning, $result->diagnostics[0]->severity);
        $artifact = RegistryArtifact::fromRegistry($result->requireRegistry(), 'build:additional');
        $path = $directory . '/registry.json';

        try {
            $store = new RegistryArtifactStore();
            $store->write($artifact, $path);
            self::assertSame($artifact->contentHash, $store->load($path)->contentHash);
            self::assertSame(
                'build:additional',
                $store->load($path, 'build:additional')->applicationBuildId,
            );

            try {
                $store->write($artifact, $directory . '/missing/registry.json');
                self::fail('Artifact was written into a missing directory.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }

            set_error_handler(static fn(): bool => true);

            try {
                $store->write($artifact, $directory);
                self::fail('Artifact replaced a directory.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            } finally {
                restore_error_handler();
            }

            set_error_handler(static fn(): bool => true);

            try {
                $store->load($directory . '/missing.json');
                self::fail('Missing artifact was loaded.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            } finally {
                restore_error_handler();
            }
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            unlink($validMap);
            unlink($invalidMap);
            rmdir($directory);
        }

        $invalid = (new RegistryCompiler())->compile(new ExplicitClassSource([AdditionalOrphanQuery::class]));
        self::assertFalse($invalid->isValid());

        try {
            $invalid->requireRegistry();
            self::fail('Invalid registry compilation produced a runtime registry.');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }
    }

    public function testRuntimeConfigurationRuleValidatesLostRetryAndTargetResolver(): void
    {
        $bindingId = ChangedEmail::class;
        $routes = new DeliveryRoutes([
            $bindingId => new DeliveryRoute(DeliveryMode::Durable, 'safe'),
        ]);
        $policies = new DeliveryPolicies([
            'safe' => new DeliveryPolicy(
                'safe',
                maxAttempts: 2,
                idempotent: true,
                retryLostAttempts: true,
            ),
        ]);
        $source = new ExplicitClassSource([ChangedEmail::class, ChangedEmailHandler::class]);
        $invalid = (new RegistryCompiler(rules: [
            new RuntimeConfigurationRule($routes, $policies),
        ]))->compile($source);
        self::assertFalse($invalid->isValid());
        self::assertSame(['MBC005'], array_column($invalid->diagnostics, 'code'));

        $resolver = new class implements IdempotencyIdentityResolver {
            public function resolve(Message $message, string $targetId): IdempotencyIdentity
            {
                if (!$message instanceof ChangedEmail) {
                    throw new \LogicException('Unexpected message type.');
                }

                return new IdempotencyIdentity(
                    $targetId,
                    new IdempotencyKey($message->email),
                    new IntentFingerprint('sha256', 1, hash('sha256', $message->email)),
                );
            }
        };
        $boundary = new class implements AtomicIdempotencyBoundary {
            public function execute(IdempotencyAttempt $attempt, callable $effect): IdempotencyResult
            {
                $effect();

                return new IdempotencyResult(IdempotencyOutcome::Executed);
            }
        };
        $runtime = new IdempotencyRuntime(
            new IdempotencyIdentityResolvers([new TargetIdempotencyResolver($bindingId, $resolver)]),
            $boundary,
        );
        $valid = (new RegistryCompiler(rules: [
            new RuntimeConfigurationRule($routes, $policies, $runtime),
        ]))->compile($source);
        self::assertTrue($valid->isValid());
        self::assertSame([$bindingId], $runtime->identities->targetIds());

        $this->expectException(\InvalidArgumentException::class);
        new IdempotencyIdentityResolvers([
            new TargetIdempotencyResolver($bindingId, $resolver),
            new TargetIdempotencyResolver($bindingId, $resolver),
        ]);
    }

    public function testOperationsCancellationSignalAndRoutingDelegateExplicitly(): void
    {
        $clock = new MutableClock();
        $backend = new InMemoryDeliveryBackend($clock, new SequenceIdentities());
        $actor = new ControlActor('tester', 'facade coverage');
        $draft = $this->draft('delivery:operations', new DeliveryPolicy('operations'));
        $backend->accept($draft);
        $operations = new DeliveryOperations($backend);
        self::assertSame(DeliveryState::Accepted, $operations->status($draft->deliveryId)?->state);
        self::assertSame(CancellationOutcome::CancelledBeforeStart, $operations->cancel($draft->deliveryId, $actor)->outcome);
        $replay = $operations->replay(
            $draft->deliveryId,
            new DeliveryPolicy('operations-replay'),
            $actor,
        );
        self::assertSame($draft->deliveryId, $replay->originalDeliveryId);
        $replayClaim = $backend->claim('default', 'worker');
        self::assertNotNull($replayClaim);
        self::assertTrue($backend->fail($replayClaim, FailureCategory::Terminal, 'test cleanup'));

        $maintenance = new DeliveryMaintenance($backend);
        self::assertSame(0, $maintenance->recoverExpired(10)->changed);
        self::assertGreaterThanOrEqual(1, $maintenance->prune($clock->now()->modify('+1 second'), 10, false)->examined);

        $claimedDraft = $this->draft('delivery:cancellation-signal', new DeliveryPolicy('signal'));
        $backend->accept($claimedDraft);
        $claim = $backend->claim('default', 'worker');
        self::assertNotNull($claim);
        $signal = new DeliveryCancellationSignal($backend, $claim);
        self::assertFalse($signal->isRequested());
        $backend->cancel($claim->delivery->deliveryId, $actor);
        self::assertTrue($signal->isRequested());

        try {
            $signal->throwIfRequested();
            self::fail('Cancellation signal did not interrupt execution.');
        } catch (CancellationRequested) {
            self::addToAssertionCount(1);
        }

        $next = new OneShotNextHandler(static fn(): string => 'continued');
        self::assertSame('continued', $next->handle());

        try {
            $next->handle();
            self::fail('One-shot continuation was invoked twice.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }

        $classifiers = new FailureClassifiers(new class implements FailureClassifier {
            public function classify(\Throwable $failure): ?FailureCategory
            {
                return $failure instanceof \RuntimeException ? FailureCategory::Retryable : null;
            }
        });
        self::assertSame(FailureCategory::Retryable, $classifiers->classify(new \RuntimeException()));

        $registry = (new RegistryCompiler())->compile(new ExplicitClassSource([
            ChangedEmail::class,
            ChangedEmailHandler::class,
        ]))->requireRegistry();
        $binding = $registry->handlersFor(new ChangedEmail('route@example.test'))[0];
        $route = new DeliveryRoute(DeliveryMode::Durable, 'operations');
        $routes = new DeliveryRoutes([$binding->id => $route]);
        self::assertTrue($routes->has($binding));
        self::assertSame($route, $routes->route($binding));

        try {
            (new DeliveryRoutes([]))->route($binding);
            self::fail('Missing route was resolved.');
        } catch (\LogicException) {
            self::addToAssertionCount(1);
        }
    }

    public function testObserverAndWorkerFailureExposeTypedOutcomes(): void
    {
        $logger = new RecordingLogger();
        $event = new RuntimeEvent(
            RuntimeEventType::AttemptFailed,
            new \DateTimeImmutable('2026-11-22T10:00:00+00:00'),
            new MessageId('message:observer-event'),
            new DeliveryId('delivery:observer-event'),
            failureCategory: 'terminal',
            failureClass: \RuntimeException::class,
            errorFingerprint: hash('sha256', 'observer-test'),
        );
        (new PsrLogRuntimeObserver($logger))->observe($event);
        self::assertSame('message_bus.attempt_failed', $logger->records[0]['message']);
        self::assertSame('terminal', $logger->records[0]['context']['failure_category']);
        self::assertSame('warning', RuntimeEventType::DeliveryRecovered->severity()->value);
        self::assertSame('debug', RuntimeEventType::DispatchStarted->severity()->value);
        (new NullObserverFailureSink())->report(new \RuntimeException('ignored'), $event);
        self::addToAssertionCount(1);

        foreach ([
            static fn(): RuntimeEvent => new RuntimeEvent(
                RuntimeEventType::DispatchStarted,
                new \DateTimeImmutable('2026-11-22T10:00:00+00:00'),
                messageName: '',
            ),
            static fn(): RuntimeEvent => new RuntimeEvent(
                RuntimeEventType::DispatchStarted,
                new \DateTimeImmutable('2026-11-22T10:00:00+00:00'),
                attemptNumber: 0,
            ),
        ] as $invalidEvent) {
            try {
                $invalidEvent();
                self::fail('Invalid runtime event was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        $failureSink = new RecordingObserverFailureSink();
        (new BestEffortRuntimeObserver(new ThrowingRuntimeObserver(), $failureSink))->observe($event);
        self::assertSame(1, $failureSink->failures);
        (new BestEffortRuntimeObserver(new ThrowingRuntimeObserver(), new ThrowingObserverFailureSink()))
            ->observe($event);
        self::addToAssertionCount(1);

        $handler = new FailingCommandHandler();
        $registry = (new RegistryCompiler())->compile(new ExplicitClassSource([
            FailingCommand::class,
            FailingCommandHandler::class,
        ]))->requireRegistry();
        $backend = new InMemoryDeliveryBackend(new MutableClock(), new SequenceIdentities());
        $codecs = new MessageCodecs(
            [FailingCommand::class => new JsonMessageCodec(FailingCommand::class)],
            ['failing.command' => [1 => new JsonMessageCodec(FailingCommand::class)]],
        );
        $backend->accept($this->draft(
            'delivery:worker-failure',
            new DeliveryPolicy('terminal'),
            $codecs->encode(new FailingCommand('x')),
            FailingCommand::class,
            FailingCommandHandler::class,
        ));
        $runtime = new DeliveryWorkerRuntime(
            $backend,
            $codecs,
            $registry,
            new HandlerServices([FailingCommandHandler::class => $handler]),
            self::createStub(MessageBus::class),
        );
        self::assertSame(WorkerIteration::Failed, $runtime->runOnce(new WorkerProfile('failure', 'default'), 'worker'));
        self::assertSame(DeliveryState::Failed, $backend->status(new DeliveryId('delivery:worker-failure'))?->state);
        self::assertSame(WorkerIteration::Idle, $runtime->runOnce(new WorkerProfile('failure', 'default'), 'worker'));
        self::assertSame(FailureCategory::Retryable, (new FailureClassifiers())->classify(new \RuntimeException()));
        self::assertSame(FailureCategory::ClassifierError, (new FailureClassifiers(
            new class implements FailureClassifier {
                public function classify(\Throwable $failure): ?FailureCategory
                {
                    throw new \RuntimeException('classifier failed');
                }
            },
        ))->classify(new \RuntimeException()));
        self::assertSame(FailureCategory::Cancelled, (new DefaultFailureClassifier())
            ->classify(new CancellationRequested()));
        self::assertSame(FailureCategory::Incompatible, (new DefaultFailureClassifier())
            ->classify(new IncompatibleDeliveryFailure()));
    }

    private function draft(
        string $deliveryId,
        DeliveryPolicy $policy,
        ?EncodedPayload $payload = null,
        string $target = 'target',
        string $handler = 'Handler',
    ): DeliveryDraft {
        return new DeliveryDraft(
            new DeliveryId($deliveryId),
            new MessageMetadata(
                new MessageId('message:' . hash('sha256', $deliveryId)),
                new CorrelationId('correlation:' . hash('sha256', $deliveryId)),
                null,
                new \DateTimeImmutable('2026-11-22T10:00:00+00:00'),
            ),
            $payload ?? new EncodedPayload('test.command', 1, 'json', '{}'),
            $target,
            $handler,
            '__invoke',
            'default',
            $policy,
        );
    }
}

final class MutableClock implements ClockInterface
{
    public function __construct(private \DateTimeImmutable $current = new \DateTimeImmutable('2026-11-22T10:00:00+00:00')) {}

    public function now(): \DateTimeImmutable
    {
        return $this->current;
    }

    public function advance(string $modify): void
    {
        $this->current = $this->current->modify($modify);
    }
}

interface UnknownContext extends MessageContext {}

/** @implements Query<string> */
final readonly class AdditionalOrphanQuery implements Query {}

/** @implements Message<string> */
final readonly class ContextMessage implements Message {}

#[MessageName('changed.email')]
#[Payload(2)]
final readonly class ChangedEmail implements Command
{
    public function __construct(public string $email) {}
}

final readonly class ChangedEmailHandler
{
    #[Handler]
    public function __invoke(ChangedEmail $message): void {}
}

final readonly class AddressUpcaster implements MessageUpcaster
{
    public function upcast(array $payload): array
    {
        $address = $payload['address'] ?? null;
        if (!\is_string($address)) {
            throw new \UnexpectedValueException('Legacy address must be a string.');
        }

        return ['email' => $address];
    }
}

#[MessageName('native.extra')]
#[Payload(1, 'php-native')]
final readonly class NativeExtraCommand implements Command
{
    public function __construct(public NativeExtraValue $value) {}
}

final readonly class NativeExtraValue
{
    public function __construct(public string $value) {}
}

final readonly class WarningRule implements RegistryValidationRule
{
    public function validate(CompiledRegistry $registry): array
    {
        return [new RegistryDiagnostic('EXAMPLE001', DiagnosticSeverity::Warning, 'Example warning.')];
    }
}

final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{message: string, context: array<string, mixed>}> */
    public array $records = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $normalized = [];
        foreach ($context as $key => $value) {
            if (\is_string($key)) {
                $normalized[$key] = $value;
            }
        }
        $this->records[] = ['message' => (string) $message, 'context' => $normalized];
    }
}

final readonly class ThrowingRuntimeObserver implements RuntimeObserver
{
    public function observe(RuntimeEvent $event): void
    {
        throw new \RuntimeException('observer failed');
    }
}

final class RecordingObserverFailureSink implements ObserverFailureSink
{
    public int $failures = 0;

    public function report(\Throwable $failure, RuntimeEvent $event): void
    {
        ++$this->failures;
    }
}

final readonly class ThrowingObserverFailureSink implements ObserverFailureSink
{
    public function report(\Throwable $failure, RuntimeEvent $event): void
    {
        throw new \RuntimeException('emergency sink failed');
    }
}

#[MessageName('failing.command')]
#[Payload]
final readonly class FailingCommand implements Command
{
    public function __construct(public string $value) {}
}

final readonly class FailingCommandHandler
{
    #[Handler]
    public function __invoke(FailingCommand $command): void
    {
        throw new \RuntimeException('expected failure');
    }
}
