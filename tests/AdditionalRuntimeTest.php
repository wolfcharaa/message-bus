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
use Wolfcharaa\MessageBus\Delivery\DeliveryStatus;
use Wolfcharaa\MessageBus\Delivery\FailureCategory;
use Wolfcharaa\MessageBus\Delivery\FailureClassification;
use Wolfcharaa\MessageBus\Delivery\FailureClassifier;
use Wolfcharaa\MessageBus\Delivery\FailureClassifiers;
use Wolfcharaa\MessageBus\Exception\CleanupFailed;
use Wolfcharaa\MessageBus\Exception\CommandNotAccepted;
use Wolfcharaa\MessageBus\Exception\IncompatibleDeliveryFailure;
use Wolfcharaa\MessageBus\Exception\InvalidMessageBusConfiguration;
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
use Wolfcharaa\MessageBus\Identity\AttemptId;
use Wolfcharaa\MessageBus\Identity\CorrelationId;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\MessageId;
use Wolfcharaa\MessageBus\Identity\RandomIdentityGenerator;
use Wolfcharaa\MessageBus\Internal\Runtime\BestEffortRuntimeFailureSink;
use Wolfcharaa\MessageBus\Internal\Runtime\BestEffortRuntimeObserver;
use Wolfcharaa\MessageBus\Internal\Runtime\DeliveryCancellationSignal;
use Wolfcharaa\MessageBus\Internal\Runtime\FailurePreservingCleanup;
use Wolfcharaa\MessageBus\Internal\Runtime\OneShotNextHandler;
use Wolfcharaa\MessageBus\Metadata\MessageMetadata;
use Wolfcharaa\MessageBus\Metadata\MetadataSchema;
use Wolfcharaa\MessageBus\Observability\FailureDiagnostic;
use Wolfcharaa\MessageBus\Observability\FailureStage;
use Wolfcharaa\MessageBus\Observability\NullObserverFailureSink;
use Wolfcharaa\MessageBus\Observability\ObserverFailureSink;
use Wolfcharaa\MessageBus\Observability\PsrLogRuntimeFailureSink;
use Wolfcharaa\MessageBus\Observability\PsrLogRuntimeObserver;
use Wolfcharaa\MessageBus\Observability\RuntimeEvent;
use Wolfcharaa\MessageBus\Observability\RuntimeEventType;
use Wolfcharaa\MessageBus\Observability\RuntimeFailureSink;
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
use Wolfcharaa\MessageBus\Worker\WorkerStatusFilter;

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

    public function testValidationFailuresNameTheExactFieldAndRule(): void
    {
        $cases = [
            [
                static fn(): DeliveryPolicy => new DeliveryPolicy('invalid', maxAttempts: 0),
                'DeliveryPolicy maxAttempts must be at least 1.',
            ],
            [
                static fn(): WorkerProfile => new WorkerProfile('worker', 'default', parallelism: 0),
                'WorkerProfile parallelism must be at least 1.',
            ],
            [
                static fn(): EncodedPayload => new EncodedPayload('message.name', 0, 'json', '{}'),
                'EncodedPayload version must be at least 1.',
            ],
            [
                static fn(): Payload => new Payload(0, 'json'),
                'Payload version must be at least 1.',
            ],
            [
                static fn(): WorkerStatusFilter => new WorkerStatusFilter('profile', 'instance'),
                'WorkerStatusFilter accepts either profile or instance, not both.',
            ],
            [
                static fn(): RuntimeEvent => new RuntimeEvent(
                    RuntimeEventType::DispatchStarted,
                    new \DateTimeImmutable('2026-11-22T10:00:00+00:00'),
                    correlationId: new CorrelationId('correlation:missing-message'),
                    messageName: 'message.name',
                ),
                'Runtime event dispatch_started requires field message_id.',
            ],
        ];

        foreach ($cases as [$operation, $expected]) {
            try {
                $operation();
                self::fail('Invalid value was accepted: ' . $expected);
            } catch (\InvalidArgumentException $failure) {
                self::assertSame($expected, $failure->getMessage());
            }
        }
    }

    public function testFailureContractsCoverEveryValidationBoundary(): void
    {
        $invalid = [
            [
                static fn(): Payload => new Payload(1, 'INVALID'),
                'Payload codec must match [a-z][a-z0-9._-]{1,31}.',
            ],
            [
                static fn(): EncodedPayload => new EncodedPayload('message.name', 1, '', '{}'),
                'EncodedPayload codec must not be empty.',
            ],
            [
                static fn(): DeliveryStatus => new DeliveryStatus(
                    new DeliveryId('delivery:invalid-status'),
                    new MessageId('message:invalid-status'),
                    'target',
                    DeliveryState::Failed,
                    1,
                    new \DateTimeImmutable('2026-11-22T10:00:00+00:00'),
                    lastFailure: new FailureDiagnostic(FailureStage::HandlerExecution, \RuntimeException::class),
                ),
                'DeliveryStatus lastFailure requires lastFailureAttemptId.',
            ],
            [
                static fn(): FailureClassification => new FailureClassification(
                    FailureCategory::Retryable,
                    new \RuntimeException(),
                ),
                'FailureClassification classifierFailure requires category classifier_error.',
            ],
            [
                static fn(): ControlActor => new ControlActor('tester', ''),
                'ControlActor reason must not be empty.',
            ],
            [
                static fn(): ControlActor => new ControlActor(
                    'tester',
                    'invalid identity',
                    idempotencyKey: 'short',
                ),
                'ControlActor idempotencyKey must match [a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}.',
            ],
            [
                static fn(): PublicationEntry => new PublicationEntry('target', PublicationOutcome::Failed),
                'Failed publication outcomes require failureCategory, failureSummary and failureClass.',
            ],
            [
                static fn(): DeliveryRoute => new DeliveryRoute(DeliveryMode::Inline, 'default', ''),
                'DeliveryRoute lane must not be empty.',
            ],
            [
                static fn(): RegistryDiagnostic => new RegistryDiagnostic(
                    'x',
                    DiagnosticSeverity::Error,
                    'invalid code',
                ),
                'Registry diagnostic code must match [A-Z][A-Z0-9._-]{2,31}.',
            ],
            [
                static fn(): RegistryDiagnostic => new RegistryDiagnostic(
                    'TEST001',
                    DiagnosticSeverity::Error,
                    '',
                ),
                'Registry diagnostic message must not be empty.',
            ],
            [
                static fn(): FailureDiagnostic => new FailureDiagnostic(FailureStage::Dispatch, ''),
                'Failure diagnostic field failureClass must contain 1..255 bytes.',
            ],
            [
                static fn(): FailureDiagnostic => new FailureDiagnostic(
                    FailureStage::Dispatch,
                    \RuntimeException::class,
                    '',
                ),
                'Failure diagnostic field relatedFailureClass must contain 1..255 bytes.',
            ],
            [
                static fn(): object => (new \ReflectionClass(CleanupFailed::class))->newInstanceArgs([
                    'test cleanup',
                    new \RuntimeException(),
                    [],
                ]),
                'CleanupFailed requires an operation and at least one cleanup failure.',
            ],
        ];

        foreach ($invalid as [$operation, $message]) {
            try {
                $operation();
                self::fail('Invalid value was accepted: ' . $message);
            } catch (\InvalidArgumentException $failure) {
                self::assertSame($message, $failure->getMessage());
            }
        }

        $diagnostic = FailureDiagnostic::fromThrowable(
            FailureStage::HandlerResolution,
            $primary = new \RuntimeException('primary'),
            $related = new \LogicException('related'),
        );
        self::assertSame($primary::class, $diagnostic->failureClass);
        self::assertSame($related::class, $diagnostic->relatedFailureClass);
        self::assertEquals($diagnostic, FailureDiagnostic::tryFromEncoded($diagnostic->encode()));
        self::assertNull(FailureDiagnostic::tryFromEncoded('not-json'));
        self::assertNull(FailureDiagnostic::tryFromEncoded('[]'));
        self::assertNull(FailureDiagnostic::tryFromEncoded('{"schema":2}'));
        self::assertNull(FailureDiagnostic::tryFromEncoded(str_replace(
            'MBD104',
            'MBD999',
            $diagnostic->encode(),
        )));

        $configuration = new CommandNotAccepted(
            'acceptance failed',
            previous: $primary,
            messageId: new MessageId('message:command-not-accepted'),
            messageName: 'message.name',
            targetId: 'target',
            stage: FailureStage::DeliveryAcceptance,
        );
        self::assertSame($primary, $configuration->getPrevious());
        self::assertSame(FailureStage::DeliveryAcceptance, $configuration->stage);

        try {
            FailurePreservingCleanup::rethrowAfter($primary, 'clean cleanup', static function (): void {});
        } catch (\RuntimeException $failure) {
            self::assertSame($primary, $failure);
        }
    }

    public function testPolicyAndWorkerValidationIdentifyEveryInvalidField(): void
    {
        $invalid = [
            [static fn(): DeliveryPolicy => new DeliveryPolicy('policy', retryDelaySeconds: -1), 'DeliveryPolicy retryDelaySeconds must not be negative.'],
            [static fn(): DeliveryPolicy => new DeliveryPolicy('policy', leaseSeconds: 0), 'DeliveryPolicy leaseSeconds must be at least 1.'],
            [static fn(): DeliveryPolicy => new DeliveryPolicy('policy', maxElapsedSeconds: 0), 'DeliveryPolicy maxElapsedSeconds must be at least 1.'],
            [static fn(): DeliveryPolicy => new DeliveryPolicy('policy', idempotencyRetentionSeconds: -1), 'DeliveryPolicy idempotencyRetentionSeconds must not be negative.'],
            [static fn(): DeliveryPolicy => new DeliveryPolicy('policy', replayWindowSeconds: -1), 'DeliveryPolicy replayWindowSeconds must not be negative.'],
            [static fn(): DeliveryPolicy => new DeliveryPolicy('policy', idempotencySafetyMarginSeconds: -1), 'DeliveryPolicy idempotencySafetyMarginSeconds must not be negative.'],
            [static fn(): WorkerProfile => new WorkerProfile('worker', ''), 'WorkerProfile lane must not be empty.'],
            [static fn(): WorkerProfile => new WorkerProfile('worker', 'default', pollMicroseconds: 999), 'WorkerProfile pollMicroseconds must be at least 1000.'],
            [static fn(): WorkerProfile => new WorkerProfile('worker', 'default', heartbeatMicroseconds: 999), 'WorkerProfile heartbeatMicroseconds must be at least 1000.'],
            [static fn(): WorkerProfile => new WorkerProfile('worker', 'default', shutdownGraceSeconds: 0), 'WorkerProfile shutdownGraceSeconds must be at least 1.'],
            [static fn(): WorkerProfile => new WorkerProfile('worker', 'default', forceKillGraceSeconds: 0), 'WorkerProfile forceKillGraceSeconds must be at least 1.'],
            [static fn(): WorkerProfile => new WorkerProfile('worker', 'default', maxHandledAttempts: -1), 'WorkerProfile maxHandledAttempts must not be negative.'],
            [static fn(): WorkerProfile => new WorkerProfile('worker', 'default', maxRuntimeSeconds: -1), 'WorkerProfile maxRuntimeSeconds must not be negative.'],
            [static fn(): WorkerProfile => new WorkerProfile('worker', 'default', maxMemoryBytes: -1), 'WorkerProfile maxMemoryBytes must not be negative.'],
        ];

        foreach ($invalid as [$operation, $message]) {
            try {
                $operation();
                self::fail('Invalid value was accepted: ' . $message);
            } catch (\InvalidArgumentException $failure) {
                self::assertSame($message, $failure->getMessage());
            }
        }
    }

    public function testRuntimeEventValidationIdentifiesEveryInvalidField(): void
    {
        $invalid = [
            [
                static fn(): RuntimeEvent => new RuntimeEvent(
                    RuntimeEventType::DispatchStarted,
                    new \DateTimeImmutable(),
                    schemaVersion: 2,
                ),
                'Runtime event schemaVersion must be 1.',
            ],
            [
                static fn(): RuntimeEvent => new RuntimeEvent(
                    RuntimeEventType::DispatchStarted,
                    new \DateTimeImmutable(),
                    durationMicroseconds: -1,
                ),
                'Runtime event durationMicroseconds must not be negative.',
            ],
            [
                static fn(): RuntimeEvent => new RuntimeEvent(
                    RuntimeEventType::DispatchStarted,
                    new \DateTimeImmutable(),
                    errorFingerprint: 'invalid',
                ),
                'Runtime event errorFingerprint must be a lowercase SHA-256 digest.',
            ],
            [
                static fn(): RuntimeEvent => new RuntimeEvent(
                    RuntimeEventType::DispatchStarted,
                    new \DateTimeImmutable(),
                    failureCode: 'MBD001',
                ),
                'Runtime event failureCode, failureStage and failureSummary must be provided together.',
            ],
        ];

        foreach ($invalid as [$operation, $message]) {
            try {
                $operation();
                self::fail('Invalid runtime event was accepted: ' . $message);
            } catch (\InvalidArgumentException $failure) {
                self::assertSame($message, $failure->getMessage());
            }
        }
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

        $retrying = $this->draft('delivery:retained-failure', new DeliveryPolicy('retrying', maxAttempts: 2));
        $backend->accept($retrying);
        $firstRetry = $backend->claim('default', 'worker');
        self::assertNotNull($firstRetry);
        $diagnostic = new FailureDiagnostic(FailureStage::HandlerExecution, \RuntimeException::class);
        self::assertTrue($backend->fail($firstRetry, FailureCategory::Retryable, $diagnostic->encode()));
        $secondRetry = $backend->claim('default', 'worker');
        self::assertNotNull($secondRetry);
        self::assertEquals($diagnostic, $backend->status($retrying->deliveryId)?->lastFailure);
        self::assertTrue($backend->succeed($secondRetry));
        $succeeded = $backend->status($retrying->deliveryId);
        self::assertNotNull($succeeded);
        self::assertSame(FailureCategory::Retryable->value, $succeeded->lastFailureCategory);
        self::assertEquals($firstRetry->attempt->attemptId, $succeeded->lastFailureAttemptId);
        self::assertEquals($diagnostic, $succeeded->lastFailure);

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
        } catch (InvalidMessageBusConfiguration $failure) {
            self::assertSame($invalid->diagnostics, $failure->diagnostics);
            self::assertStringContainsString('MBR014@' . AdditionalOrphanQuery::class, $failure->getMessage());
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
        $missingResolver = (new RegistryCompiler(rules: [new RuntimeConfigurationRule(
            $routes,
            $policies,
            new IdempotencyRuntime(new IdempotencyIdentityResolvers([]), $boundary),
        )]))->compile($source);
        self::assertFalse($missingResolver->isValid());
        self::assertSame(['MBC008'], array_column($missingResolver->diagnostics, 'code'));
        self::assertSame('resolver for target ' . $bindingId, $missingResolver->diagnostics[0]->expected);
        self::assertSame('not configured', $missingResolver->diagnostics[0]->actual);

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
        $diagnostic = new FailureDiagnostic(FailureStage::HandlerExecution, \RuntimeException::class);
        $event = new RuntimeEvent(
            RuntimeEventType::AttemptFailed,
            new \DateTimeImmutable('2026-11-22T10:00:00+00:00'),
            new MessageId('message:observer-event'),
            new DeliveryId('delivery:observer-event'),
            new CorrelationId('correlation:observer-event'),
            attemptId: new AttemptId('attempt:observer-event'),
            messageName: 'observer.event',
            targetId: 'observer.target',
            workerId: 'worker:observer-event',
            route: 'default',
            profile: 'observer',
            attemptNumber: 1,
            durationMicroseconds: 10,
            outcome: 'failed',
            failureCategory: 'terminal',
            failureClass: $diagnostic->failureClass,
            errorFingerprint: $diagnostic->fingerprint,
            failureCode: $diagnostic->code,
            failureStage: $diagnostic->stage->value,
            failureSummary: $diagnostic->summary,
        );
        (new PsrLogRuntimeObserver($logger))->observe($event);
        self::assertSame('message_bus.attempt_failed', $logger->records[0]['message']);
        self::assertSame('terminal', $logger->records[0]['context']['failure_category']);
        $loggedFailure = new \RuntimeException('process-local detail');
        (new PsrLogRuntimeFailureSink($logger))->report($loggedFailure, $event);
        self::assertSame($loggedFailure, $logger->records[1]['context']['exception']);
        self::assertArrayNotHasKey('exception', $logger->records[0]['context']);
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
        (new BestEffortRuntimeFailureSink(
            new ThrowingRuntimeFailureSink(),
            new ThrowingObserverFailureSink(),
        ))->report(new \RuntimeException('original runtime failure'), $event);
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
            observer: $events = new RecordingRuntimeObserver(),
            runtimeFailureSink: $runtimeFailures = new RecordingRuntimeFailureSink(),
        );
        self::assertSame(WorkerIteration::Failed, $runtime->runOnce(new WorkerProfile('failure', 'default'), 'worker'));
        $status = $backend->status(new DeliveryId('delivery:worker-failure'));
        self::assertNotNull($status);
        self::assertSame(DeliveryState::Failed, $status->state);
        self::assertNotNull($status->lastFailure);
        self::assertSame(FailureStage::HandlerExecution, $status->lastFailure->stage);
        self::assertSame('MBD105', $status->lastFailure->code);
        self::assertSame(\RuntimeException::class, $status->lastFailure->failureClass);
        self::assertSame('Handler execution failed.', $status->lastFailure->summary);
        self::assertNotNull($status->lastFailureAttemptId);
        self::assertSame($handler->failure, $runtimeFailures->records[0]['failure']);
        self::assertSame('worker', $runtimeFailures->records[0]['event']->workerId);
        self::assertSame('failure', $runtimeFailures->records[0]['event']->profile);
        $failedEvent = $events->last();
        self::assertSame(RuntimeEventType::AttemptFailed, $failedEvent->type);
        self::assertSame('MBD105', $failedEvent->failureCode);
        self::assertSame('default', $failedEvent->route);
        self::assertStringNotContainsString('expected failure', $status->lastFailure->encode());
        self::assertSame(WorkerIteration::Idle, $runtime->runOnce(new WorkerProfile('failure', 'default'), 'worker'));
        self::assertSame(FailureCategory::Retryable, (new FailureClassifiers())->classify(new \RuntimeException()));
        $classifierFailure = new \RuntimeException('classifier failed');
        $classification = (new FailureClassifiers(
            new readonly class ($classifierFailure) implements FailureClassifier {
                public function __construct(private \Throwable $failure) {}

                public function classify(\Throwable $failure): ?FailureCategory
                {
                    throw $this->failure;
                }
            },
        ))->classifyDetailed(new \RuntimeException());
        self::assertSame(FailureCategory::ClassifierError, $classification->category);
        self::assertSame($classifierFailure, $classification->classifierFailure);
        self::assertSame(FailureCategory::Cancelled, (new DefaultFailureClassifier())
            ->classify(new CancellationRequested()));
        self::assertSame(FailureCategory::Incompatible, (new DefaultFailureClassifier())
            ->classify(new IncompatibleDeliveryFailure()));
    }

    public function testClassifierFailureRetainsBothThrowablesAndPersistsExactStage(): void
    {
        $handlerFailure = new HandlerExecutionFailure('sensitive handler detail');
        $classifierFailure = new ClassifierExecutionFailure('sensitive classifier detail');
        $handler = new FailingCommandHandler($handlerFailure);
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
            'delivery:classifier-failure',
            new DeliveryPolicy('terminal'),
            $codecs->encode(new FailingCommand('x')),
            FailingCommand::class,
            FailingCommandHandler::class,
        ));
        $classifiers = new FailureClassifiers(new readonly class ($classifierFailure) implements FailureClassifier {
            public function __construct(private \Throwable $failure) {}

            public function classify(\Throwable $failure): ?FailureCategory
            {
                throw $this->failure;
            }
        });
        $runtime = new DeliveryWorkerRuntime(
            $backend,
            $codecs,
            $registry,
            new HandlerServices([FailingCommandHandler::class => $handler]),
            self::createStub(MessageBus::class),
            classifiers: $classifiers,
            observer: $events = new RecordingRuntimeObserver(),
            runtimeFailureSink: $failures = new RecordingRuntimeFailureSink(),
        );

        self::assertSame(
            WorkerIteration::Failed,
            $runtime->runOnce(new WorkerProfile('classifier', 'default'), 'worker:classifier'),
        );
        $status = $backend->status(new DeliveryId('delivery:classifier-failure'));
        self::assertNotNull($status);
        self::assertSame(FailureCategory::ClassifierError->value, $status->lastFailureCategory);
        self::assertNotNull($status->lastFailure);
        self::assertSame(FailureStage::FailureClassification, $status->lastFailure->stage);
        self::assertSame(ClassifierExecutionFailure::class, $status->lastFailure->failureClass);
        self::assertSame(HandlerExecutionFailure::class, $status->lastFailure->relatedFailureClass);
        self::assertSame([$handlerFailure, $classifierFailure], array_column($failures->records, 'failure'));
        $event = $events->last();
        self::assertSame('MBD107', $event->failureCode);
        self::assertSame(HandlerExecutionFailure::class, $event->relatedFailureClass);
        self::assertStringNotContainsString('sensitive', (string) json_encode($event->normalized()));
    }

    public function testCleanupFailurePreservesPrimaryAndAttemptsEveryCleanup(): void
    {
        $primary = new \RuntimeException('primary');
        $first = new \RuntimeException('first cleanup');
        $second = new \RuntimeException('second cleanup');
        $attempted = [];

        try {
            FailurePreservingCleanup::rethrowAfter(
                $primary,
                'test cleanup',
                static function () use (&$attempted, $first): never {
                    $attempted[] = 'first';

                    throw $first;
                },
                static function () use (&$attempted, $second): never {
                    $attempted[] = 'second';

                    throw $second;
                },
            );
        } catch (CleanupFailed $failure) {
            self::assertSame($primary, $failure->primaryFailure);
            self::assertSame($primary, $failure->getPrevious());
            self::assertSame([$first, $second], $failure->cleanupFailures);
            self::assertSame(['first', 'second'], $attempted);
            self::assertStringContainsString('test cleanup', $failure->getMessage());
        }
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

final readonly class ThrowingRuntimeFailureSink implements RuntimeFailureSink
{
    public function report(\Throwable $failure, RuntimeEvent $event): void
    {
        throw new \RuntimeException('runtime failure sink failed');
    }
}

final class RecordingRuntimeObserver implements RuntimeObserver
{
    /** @var list<RuntimeEvent> */
    public array $events = [];

    public function observe(RuntimeEvent $event): void
    {
        $this->events[] = $event;
    }

    public function last(): RuntimeEvent
    {
        return $this->events[array_key_last($this->events)]
            ?? throw new \LogicException('No runtime event was recorded.');
    }
}

final class RecordingRuntimeFailureSink implements RuntimeFailureSink
{
    /** @var list<array{failure: \Throwable, event: RuntimeEvent}> */
    public array $records = [];

    public function report(\Throwable $failure, RuntimeEvent $event): void
    {
        $this->records[] = ['failure' => $failure, 'event' => $event];
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
    public function __construct(public \Throwable $failure = new \RuntimeException('expected failure')) {}

    #[Handler]
    public function __invoke(FailingCommand $command): void
    {
        throw $this->failure;
    }
}

final class HandlerExecutionFailure extends \RuntimeException {}

final class ClassifierExecutionFailure extends \RuntimeException {}
