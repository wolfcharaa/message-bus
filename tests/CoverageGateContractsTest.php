<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PDOException;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ContainerServiceReferenceResolver;
use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ResolvedServiceReference;
use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ServiceReference;
use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ServiceReferenceResolutionFailed;
use Wolfcharaa\MessageBus\Cli\BootstrapResolver;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\AttributeDiscoveryResult;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Discovery\RegexFilterClassProvider;
use Wolfcharaa\MessageBus\Dumper\CompiledRegistryDumperInterface;
use Wolfcharaa\MessageBus\Dumper\CompiledRegistryFileWriter;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Envelope\EnvelopeSerializerInterface;
use Wolfcharaa\MessageBus\Envelope\Headers;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelopeNormalizer;
use Wolfcharaa\MessageBus\Execution\ExecutionEnvironment;
use Wolfcharaa\MessageBus\Execution\ExecutionRequest;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\Execution\SequentialExecutionStrategy;
use Wolfcharaa\MessageBus\FanOutResult;
use Wolfcharaa\MessageBus\Flow\Contract\FlowContract;
use Wolfcharaa\MessageBus\Flow\Contract\FlowContractRegistry;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Idempotency\Decision\IdempotencyDecisionInterface;
use Wolfcharaa\MessageBus\Idempotency\Exception\IdempotencyPolicyMissing;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyClaim;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyCompletion;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyEffectReference;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyEffectReferenceFactoryInterface;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyExecution;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyFlowContract;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyKey;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyKeyExtractorInterface;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyMiddlewareRole;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyPolicyDescriptor;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyStoreInterface;
use Wolfcharaa\MessageBus\Idempotency\IntentFingerprint;
use Wolfcharaa\MessageBus\Idempotency\IntentFingerprintFactoryInterface;
use Wolfcharaa\MessageBus\Idempotency\ResolvedIdempotencyPolicy;
use Wolfcharaa\MessageBus\Idempotency\ResolvedIdempotencyPolicyRegistry;
use Wolfcharaa\MessageBus\Invoker\CallableInvokerInterface;
use Wolfcharaa\MessageBus\Message\Command;
use Wolfcharaa\MessageBus\Postgres\DefaultPostgresTransientFailureDetector;
use Wolfcharaa\MessageBus\Postgres\PostgresRetryConfig;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;
use Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\HandlerInvocationMode;
use Wolfcharaa\MessageBus\Registry\HandlerKind;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\MessageRegistryDefinition;
use Wolfcharaa\MessageBus\Registry\Metadata\BindingRegistrationContext;
use Wolfcharaa\MessageBus\Registry\Metadata\RegistryOwner;
use Wolfcharaa\MessageBus\Registry\Metadata\RegistrySource;
use Wolfcharaa\MessageBus\Registry\RegistryCompilationException;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticOrigin;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticOriginKind;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticSeverity;
use Wolfcharaa\MessageBus\Registry\RegistryRuntimeLoader;
use Wolfcharaa\MessageBus\Serialization\ContentTypeAwareMessageSerializerInterface;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;
use Wolfcharaa\MessageBus\Worker\RandomWorkerControlIdGenerator;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommand;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;
use Wolfcharaa\MessageBus\Worker\WorkerIdentity;
use Wolfcharaa\MessageBus\Worker\WorkerMode;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

final class CoverageGateContractsTest extends TestCase
{
    public function testCommandAttributePublishOptionsAndFanOutResultExposeStableContracts(): void
    {
        $binding = (new CommandHandler(
            CoverageCommand::class,
            flow: 'async',
            method: 'handle',
            priority: 5,
            bindingId: 'coverage.command',
            delaySeconds: 10,
            retryPolicy: 'slow',
            contextAware: false,
        ))->toBinding(CoverageHandler::class);

        $merged = (new PublishOptions(
            'message-1',
            Headers::empty()->with('source', 'base'),
            new QueueDeliveryOptions(priority: 1, delaySeconds: 2),
        ))->merge(new PublishOptions(
            headers: Headers::empty()->with('trace', 'override'),
            delivery: new QueueDeliveryOptions(delaySeconds: 7, retryPolicy: 'slow'),
        ));
        $fanOut = new FanOutResult('sync-result', PublishResult::empty());

        self::assertSame('coverage.command', $binding->bindingId);
        self::assertSame(HandlerInvocationMode::Contextless, $binding->invocationMode);
        self::assertSame(['priority' => 5, 'delaySeconds' => 10, 'retryPolicy' => 'slow'], $binding->delivery?->toArray());
        self::assertSame('message-1', $merged->messageId);
        self::assertSame(['source' => 'base', 'trace' => 'override'], $merged->headers->all());
        self::assertSame(['priority' => 1, 'delaySeconds' => 7, 'retryPolicy' => 'slow'], $merged->delivery?->toArray());
        self::assertSame('sync-result', $fanOut->dispatchResult);
        self::assertTrue($fanOut->fanOutResult->isEmpty());
    }

    public function testServiceReferenceDiscoveryAndFlowRegistriesCoverFailureBranches(): void
    {
        $resolver = new ContainerServiceReferenceResolver(new CoverageNotFoundContainer());

        try {
            $resolver->resolve(ServiceReference::service('missing', IdempotencyKeyExtractorInterface::class));
            self::fail('Expected container not found exception to be mapped.');
        } catch (ServiceReferenceResolutionFailed $e) {
            self::assertStringContainsString('was not found', $e->getMessage());
        }

        $classes = \iterator_to_array((new RegexFilterClassProvider(
            new ClassListProvider([CoverageCommand::class, CoverageHandler::class]),
            '/Command$/',
        ))->classes());
        $binding = $this->binding();
        $discovery = new AttributeDiscoveryResult(
            bindings: [$binding],
            aliases: ['coverage.command' => CoverageCommand::class],
            messageNames: [CoverageCommand::class => 'coverage.command'],
            diagnostics: [RegistryDiagnostic::error('coverage.error', 'Broken')],
        );
        $contract = FlowContract::forFlow('idempotent');
        $registry = new FlowContractRegistry($contract);

        self::assertSame([CoverageCommand::class], $classes);
        self::assertTrue($discovery->hasErrors());
        self::assertSame([
            'bindings' => [$binding],
            'aliases' => ['coverage.command' => CoverageCommand::class],
            'messageNames' => [CoverageCommand::class => 'coverage.command'],
        ], $discovery->toArray());
        self::assertSame(['idempotent' => $contract], $registry->all());

        try {
            new FlowContractRegistry($contract, $contract);
            self::fail('Expected duplicate flow contract to fail.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('already registered', $e->getMessage());
        }
    }

    public function testIdempotencyContractsCoverValidationAndArrayShapes(): void
    {
        foreach ([
            static fn (): object => new IdempotencyKey(' '),
            static fn (): object => new IdempotencyEffectReference('', 'job-1'),
            static fn (): object => new IntentFingerprint('', '1', 'digest'),
        ] as $factory) {
            try {
                $factory();
                self::fail('Expected invalid idempotency value object to fail.');
            } catch (InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }

        $contract = IdempotencyFlowContract::requiresIdempotencyKey('idempotent');
        $descriptor = IdempotencyPolicyDescriptor::forBinding(
            'coverage.command',
            ServiceReference::service('key', IdempotencyKeyExtractorInterface::class),
            ServiceReference::service('fingerprint', IntentFingerprintFactoryInterface::class),
            ServiceReference::service('store', IdempotencyStoreInterface::class),
            ServiceReference::service('effect', IdempotencyEffectReferenceFactoryInterface::class),
            retentionSeconds: 3600,
        );
        $policy = $this->resolvedPolicy('coverage.command');
        $registry = new ResolvedIdempotencyPolicyRegistry($policy);

        self::assertSame('Idempotency policy is required for binding `coverage.command`.', IdempotencyPolicyMissing::forBinding('coverage.command')->getMessage());
        self::assertSame('idempotent', $contract->flow);
        self::assertTrue($contract->requiresStableBindingId);
        self::assertTrue($contract->requiresBindingOwner);
        self::assertSame([IdempotencyMiddlewareRole::Guard->value], $contract->requiredRoles);
        self::assertSame([HandlerKind::Command, HandlerKind::Event], $contract->allowedHandlerKinds);
        self::assertSame('effect', $descriptor->toArray()['effectReferenceFactory']['id']);
        self::assertSame(3600, $descriptor->toArray()['retentionSeconds']);
        self::assertSame(['coverage.command'], \array_keys(\iterator_to_array($registry->all())));

        try {
            new ResolvedIdempotencyPolicyRegistry($policy, $policy);
            self::fail('Expected duplicate resolved policy to fail.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('already registered', $e->getMessage());
        }
    }

    public function testRegistryMetadataDiagnosticsAndDefinitionsCoverRoundTripBranches(): void
    {
        $binding = $this->binding();
        $context = new BindingRegistrationContext(
            RegistryOwner::app('gateway'),
            RegistrySource::handler(CoverageHandler::class),
        );
        $stamped = $context->stamp($binding->withInvocationMode(HandlerInvocationMode::Contextless));

        self::assertSame(['kind' => 'app', 'id' => 'gateway'], $stamped->owner?->toArray());
        self::assertSame('handler', $stamped->source?->type);
        self::assertSame(HandlerInvocationMode::Contextless, $stamped->invocationMode);
        self::assertSame(RegistryDiagnosticSeverity::Info, RegistryDiagnostic::info('coverage.info', 'Info')->severity);

        try {
            new RegistryOwner('', 'orders');
            self::fail('Expected invalid owner kind to fail.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('lowercase ASCII slug', $e->getMessage());
        }

        try {
            new RegistrySource('', 'provider');
            self::fail('Expected invalid source type to fail.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('source type must be non-empty', $e->getMessage());
        }

        self::assertSame([
            'type' => 'provider',
            'name' => 'CoverageProvider',
            'providerClass' => CoverageHandler::class,
        ], RegistrySource::fromArray([
            'type' => 'provider',
            'name' => 'CoverageProvider',
            'providerClass' => CoverageHandler::class,
            'package' => null,
            'location' => '',
        ])->toArray());

        foreach ([
            static fn (): HandlerBindingDefinition => HandlerBindingDefinition::fromArray($binding->toArray() + ['owner' => 'invalid']),
            static fn (): HandlerBindingDefinition => HandlerBindingDefinition::fromArray($binding->toArray() + [
                'owner' => ['kind' => 'app', 'id' => 'gateway'],
                'source' => 'invalid',
            ]),
            static fn (): RegistrySource => RegistrySource::fromArray(['type' => 'provider', 'name' => 'CoverageProvider', 'package' => 42]),
            static fn (): MessageRegistryDefinition => MessageRegistryDefinition::fromArray(['schemaVersion' => -1]),
        ] as $factory) {
            try {
                $factory();
                self::fail('Expected malformed registry payload to fail.');
            } catch (\Throwable $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }

        $warning = RegistryDiagnostic::warning('coverage.warning', 'Warning');
        $error = RegistryDiagnostic::error(
            'coverage.error',
            'Error',
            new RegistryDiagnosticOrigin(
                RegistryDiagnosticOriginKind::Compiler,
                name: 'coverage',
                file: '/tmp/coverage.php',
                line: 12,
            ),
        );
        $warningOnly = RegistryCompilationException::fromDiagnostics([$warning], 'Coverage failure');
        $withError = RegistryCompilationException::fromDiagnostics([$warning, $error], 'Coverage failure');

        self::assertFalse($warningOnly->hasErrors());
        self::assertTrue($warningOnly->hasWarnings());
        self::assertTrue($withError->hasErrors());
        self::assertStringContainsString('/tmp/coverage.php:12', $withError->getMessage());
    }

    public function testCompiledRegistryWriterRegistryLoaderAndCompiledRegistryFileBranches(): void
    {
        $definition = $this->definition();
        $target = \sys_get_temp_dir() . '/messagebus-coverage-registry-' . \bin2hex(\random_bytes(4)) . '.php';
        $invalid = \sys_get_temp_dir() . '/messagebus-coverage-invalid-' . \bin2hex(\random_bytes(4)) . '.php';

        try {
            (new CompiledRegistryFileWriter(new CoverageRegistryDumper()))->write($definition, $target);
            \file_put_contents($invalid, '<?php return new stdClass();');

            $compiled = CompiledMessageRegistry::fromFile($target);
            $loaded = (new RegistryRuntimeLoader())->load(new ClassListProvider([]), $target);
            $fresh = (new RegistryRuntimeLoader())->load(new ClassListProvider([]));

            self::assertSame('coverage.command', $compiled->binding('coverage.command')->bindingId);
            self::assertSame('coverage.command', $loaded->binding('coverage.command')->bindingId);
            self::assertSame([], $fresh->definition()->bindings);

            try {
                CompiledMessageRegistry::fromFile($invalid);
                self::fail('Expected invalid compiled registry file to fail.');
            } catch (RegistryCompilationException $e) {
                self::assertStringContainsString('must return array', $e->getMessage());
            }

            try {
                (new RegistryRuntimeLoader())->load(new ClassListProvider([]), $target . '.missing', requireCompiled: true);
                self::fail('Expected missing required compiled file to fail.');
            } catch (RuntimeException $e) {
                self::assertStringContainsString('is required but was not found', $e->getMessage());
            }
        } finally {
            @\unlink($target);
            @\unlink($invalid);
        }
    }

    public function testPostgresSerializerAndWorkerHelpersCoverAlternateBranches(): void
    {
        $pdo = new PDOException('connection failed');
        $pdo->errorInfo = ['08006'];
        $detector = new DefaultPostgresTransientFailureDetector();
        $retry = PostgresRetryConfig::fromArray([
            'profile' => 'fast',
            'initial_delay_ms' => 0,
            'jitter' => false,
        ]);
        $ids = new RandomWorkerControlIdGenerator();
        $command = new WorkerControlCommand(
            'command-1',
            WorkerControlCommandType::Pause,
            WorkerTarget::all(),
            new DateTimeImmutable('2026-09-22T12:00:00+03:00'),
        );
        $identityWithoutBindings = $this->identity(bindingIds: [], bindingPatterns: []);
        $identityWithPattern = $this->identity(bindingIds: [], bindingPatterns: ['coverage.*']);
        $identityWithBinding = $this->identity(bindingIds: ['coverage.command'], bindingPatterns: []);

        self::assertSame('sqlstate.08006', $detector->reason($pdo));
        self::assertSame(0, $retry->delayMillisecondsForRetry(1));
        self::assertSame(15, (new PostgresRetryConfig(initialDelayMilliseconds: 10, multiplier: 2.0, maxDelayMilliseconds: 15, jitter: false))->delayMillisecondsForRetry(2));
        self::assertStringStartsWith('worker_command.pause.', $ids->nextCommandId(WorkerControlCommandType::Pause));
        self::assertSame('worker_desired_state.command-1', $ids->nextDesiredStateId($command));
        self::assertTrue((new WorkerTarget(bindingIds: ['coverage.command']))->matches($identityWithoutBindings));
        self::assertTrue((new WorkerTarget(bindingIds: ['coverage.command']))->matches($identityWithPattern));
        self::assertTrue((new WorkerTarget(bindingPatterns: ['coverage.*']))->matches($identityWithBinding));

        try {
            new \Wolfcharaa\MessageBus\Serialization\CompositeMessageSerializer([new CoverageEmptyContentTypeSerializer()]);
            self::fail('Expected empty content type serializer to fail.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('content type must not be empty', $e->getMessage());
        }
    }

    public function testBootstrapResolverFindsConventionPathBeforeRejectingInvalidRuntime(): void
    {
        $previousDirectory = \getcwd();
        $directory = \sys_get_temp_dir() . '/messagebus-bootstrap-' . \bin2hex(\random_bytes(4));
        \mkdir($directory);
        \file_put_contents($directory . '/message-bus.php', '<?php return new stdClass();');

        try {
            \chdir($directory);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('MessageBus bootstrap must return MessageBusRuntime');

            (new BootstrapResolver(['message-bus.php']))->resolve();
        } finally {
            \chdir($previousDirectory);
            @\unlink($directory . '/message-bus.php');
            @\rmdir($directory);
        }
    }

    public function testSerializedEnvelopeNormalizerAcceptsSnakeCaseAndRejectsMalformedMessage(): void
    {
        $normalizer = new SerializedEnvelopeNormalizer();

        $envelope = $normalizer->fromArray([
            'message' => [
                'name' => 'coverage.command',
                'content_type' => 'application/json',
                'payload' => '{}',
                'headers' => 'not-array',
            ],
            'headers' => 'not-array',
            'message_id' => 'message-1',
            'causation_id' => null,
            'correlation_id' => 'correlation-1',
            'flow' => 'default',
            'binding_id' => 123,
            'created_at' => '2026-09-22T12:00:00+03:00',
            'schema_version' => 1,
        ]);

        self::assertSame([], $envelope->message->headers);
        self::assertSame([], $envelope->headers);
        self::assertSame('123', $envelope->bindingId);
        self::assertSame('message-1', $normalizer->toArray($envelope)['messageId']);

        try {
            $normalizer->fromArray(['message' => 'invalid']);
            self::fail('Expected malformed envelope message to fail.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('message field must be an array', $e->getMessage());
        }
    }

    public function testSequentialStrategyCanCollectHandlerFailuresWithoutFailFast(): void
    {
        $binding = HandlerBindingDefinition::command(
            CoverageCommand::class,
            CoverageHandler::class,
            'handle',
            'default',
            true,
            0,
            'coverage.command',
        );
        $context = new CoverageContext(new Envelope(
            new CoverageCommand(),
            'message-1',
            'message-1',
            null,
            'default',
            'coverage.command',
            new DateTimeImmutable('2026-09-22T12:00:00+03:00'),
        ));
        $request = new ExecutionRequest(
            [$binding],
            $context,
            FlowDefinition::sync('default'),
            new PublishOptions(),
            new ExecutionEnvironment(
                new CoverageFailingInvoker(),
                new CoverageEnvelopeSerializer(),
                new CoverageClock(),
            ),
        );

        $result = (new SequentialExecutionStrategy(failFast: false))->execute($request);
        $handlerResult = $result->failed()[0] ?? null;

        self::assertNotNull($handlerResult);
        self::assertFalse($handlerResult->isSuccessful());
        self::assertInstanceOf(RuntimeException::class, $handlerResult->error());
    }

    private function binding(): HandlerBindingDefinition
    {
        return HandlerBindingDefinition::command(
            CoverageCommand::class,
            CoverageHandler::class,
            'handle',
            'default',
            true,
            0,
            'coverage.command',
        );
    }

    private function definition(): MessageRegistryDefinition
    {
        $binding = $this->binding();

        return new MessageRegistryDefinition(
            MessageRegistryCompiler::SCHEMA_VERSION,
            MessageRegistryCompiler::LIBRARY_VERSION,
            '2026-09-22T12:00:00+03:00',
            'coverage-hash',
            new FlowRegistry(FlowDefinition::sync('default')),
            [CoverageCommand::class => ['coverage.command']],
            ['coverage.command' => $binding],
            ['coverage.command' => CoverageCommand::class],
            [CoverageCommand::class => 'coverage.command'],
        );
    }

    private function resolvedPolicy(string $bindingId): ResolvedIdempotencyPolicy
    {
        return new ResolvedIdempotencyPolicy(
            $bindingId,
            RegistryOwner::app('gateway'),
            RegistrySource::handler(CoverageHandler::class),
            new ResolvedServiceReference(ServiceReference::service('key', IdempotencyKeyExtractorInterface::class), new CoverageKeyExtractor()),
            new ResolvedServiceReference(ServiceReference::service('fingerprint', IntentFingerprintFactoryInterface::class), new CoverageFingerprintFactory()),
            new ResolvedServiceReference(ServiceReference::service('store', IdempotencyStoreInterface::class), new CoverageStore()),
            new ResolvedServiceReference(ServiceReference::service('effect', IdempotencyEffectReferenceFactoryInterface::class), new CoverageEffectFactory()),
            3600,
        );
    }

    /**
     * @param list<string> $bindingIds
     * @param list<string> $bindingPatterns
     */
    private function identity(array $bindingIds, array $bindingPatterns): WorkerIdentity
    {
        return new WorkerIdentity(
            'worker',
            'worker-1',
            'default',
            'localhost',
            123,
            new DateTimeImmutable('2026-09-22T12:00:00+03:00'),
            WorkerMode::Single,
            'memory',
            'default',
            ['default'],
            $bindingIds,
            $bindingPatterns,
        );
    }
}

final readonly class CoverageCommand implements Command
{
    public function __construct(public string $id = '42')
    {
    }
}

final class CoverageHandler
{
    public function handle(CoverageCommand $command): void
    {
    }
}

final class CoverageNotFoundContainer implements ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new CoverageContainerNotFound('missing');
    }

    public function has(string $id): bool
    {
        return true;
    }
}

final class CoverageContainerNotFound extends RuntimeException implements NotFoundExceptionInterface
{
}

final class CoverageRegistryDumper implements CompiledRegistryDumperInterface
{
    public function dump(MessageRegistryDefinition $definition): string
    {
        return '<?php return ' . \var_export($definition->toArray(), true) . ';';
    }
}

final class CoverageKeyExtractor implements IdempotencyKeyExtractorInterface
{
    public function extract(object $message): IdempotencyKey
    {
        return new IdempotencyKey('coverage-key');
    }
}

final class CoverageFingerprintFactory implements IntentFingerprintFactoryInterface
{
    public function fingerprint(object $message): IntentFingerprint
    {
        return IntentFingerprint::sha256('coverage');
    }
}

final class CoverageStore implements IdempotencyStoreInterface
{
    public function claim(
        IdempotencyExecution $execution,
        IdempotencyKey $key,
        IntentFingerprint $fingerprint,
    ): IdempotencyDecisionInterface {
        throw new RuntimeException('Unused in coverage contract test.');
    }

    public function complete(IdempotencyClaim $claim, IdempotencyCompletion $completion): void
    {
        throw new RuntimeException('Unused in coverage contract test.');
    }
}

final class CoverageEffectFactory implements IdempotencyEffectReferenceFactoryInterface
{
    public function effectReference(object $message, IdempotencyExecution $execution): ?IdempotencyEffectReference
    {
        return new IdempotencyEffectReference('queue-message', $execution->messageId);
    }
}

final class CoverageEmptyContentTypeSerializer implements ContentTypeAwareMessageSerializerInterface
{
    public function serialize(object $message): SerializedMessage
    {
        return new SerializedMessage('coverage.command', '', '{}');
    }

    public function deserialize(SerializedMessage $message): object
    {
        return new CoverageCommand();
    }

    public function supportedContentTypes(): array
    {
        return [''];
    }

    public function supportsContentType(string $contentType): bool
    {
        return $contentType === '';
    }
}

final class CoverageContext implements MessageContextInterface
{
    public function __construct(private readonly Envelope $envelope)
    {
    }

    public function envelope(): Envelope
    {
        return $this->envelope;
    }

    public function dispatch(object $message, PublishOptions $options = new PublishOptions()): mixed
    {
        throw new RuntimeException('Unused in coverage contract test.');
    }

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions()): HandlerExecutionResultInterface
    {
        throw new RuntimeException('Unused in coverage contract test.');
    }

    public function publish(object $message, PublishOptions $options = new PublishOptions()): PublishResult
    {
        throw new RuntimeException('Unused in coverage contract test.');
    }
}

final class CoverageFailingInvoker implements CallableInvokerInterface
{
    public function invoke(string|object $service, string $method, array $arguments): mixed
    {
        throw new RuntimeException('handler failed');
    }
}

final class CoverageEnvelopeSerializer implements EnvelopeSerializerInterface
{
    public function serialize(Envelope $envelope): SerializedEnvelope
    {
        throw new RuntimeException('Unused in coverage contract test.');
    }

    public function deserialize(SerializedEnvelope $envelope): Envelope
    {
        throw new RuntimeException('Unused in coverage contract test.');
    }
}

final class CoverageClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-22T12:00:00+03:00');
    }
}
