<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerExceptionInterface;
use Wolfcharaa\MessageBus\Attribute\CommandHandler;
use Wolfcharaa\MessageBus\Attribute\QueryHandler;
use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ContainerServiceReferenceResolver;
use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ResolvedServiceReference;
use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ServiceReference;
use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ServiceReferenceResolutionFailed;
use Wolfcharaa\MessageBus\Bootstrap\ServiceReference\ServiceReferenceResolverInterface;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Exception\NonRetryableMessageExceptionInterface;
use Wolfcharaa\MessageBus\Exception\RetryableMessageExceptionInterface;
use Wolfcharaa\MessageBus\Flow\Contract\FlowContractRegistry;
use Wolfcharaa\MessageBus\Flow\Contract\FlowContractValidationRule;
use Wolfcharaa\MessageBus\Flow\Contract\MiddlewareRoleRegistry;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Idempotency\Decision\IdempotencyClaimed;
use Wolfcharaa\MessageBus\Idempotency\Decision\IdempotencyCompletedSame;
use Wolfcharaa\MessageBus\Idempotency\Decision\IdempotencyConflict;
use Wolfcharaa\MessageBus\Idempotency\Decision\IdempotencyDecisionInterface;
use Wolfcharaa\MessageBus\Idempotency\Decision\IdempotencyInProgress;
use Wolfcharaa\MessageBus\Idempotency\Exception\IdempotencyConflictDetected;
use Wolfcharaa\MessageBus\Idempotency\Exception\IdempotencyInProgressException;
use Wolfcharaa\MessageBus\Idempotency\Exception\IdempotencyUnsupportedHandlerKind;
use Wolfcharaa\MessageBus\Idempotency\Exception\MissingIdempotencyKey;
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
use Wolfcharaa\MessageBus\Idempotency\IdempotencyPolicyProviderInterface;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyPolicyResolver;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyStoreInterface;
use Wolfcharaa\MessageBus\Idempotency\IntentFingerprint;
use Wolfcharaa\MessageBus\Idempotency\IntentFingerprintFactoryInterface;
use Wolfcharaa\MessageBus\Idempotency\RequiresIdempotencyKey;
use Wolfcharaa\MessageBus\Idempotency\ResolvedIdempotencyPolicy;
use Wolfcharaa\MessageBus\Idempotency\ResolvedIdempotencyPolicyRegistry;
use Wolfcharaa\MessageBus\Interceptor\PipelineInterface;
use Wolfcharaa\MessageBus\MessageBus;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\Metadata\BindingRegistrationContext;
use Wolfcharaa\MessageBus\Registry\Metadata\RegistryOwner;
use Wolfcharaa\MessageBus\Registry\Metadata\RegistrySource;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnosticCodes;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;

final class FlowContractAndIdempotencyTest extends TestCase
{
    public function testRegistryOwnerSourceAndBindingMetadataRoundTrip(): void
    {
        $owner = new RegistryOwner('Core', ' Orders ');
        $source = new RegistrySource('provider', 'OrderProvider', package: 'orders', location: 'config/message_bus.php');
        $binding = HandlerBindingDefinition::command(
            IdempotencyCommand::class,
            IdempotencyCommandHandler::class,
            '__invoke',
            'idempotent',
            true,
            0,
            'orders.create',
        )->withRegistrationMetadata($owner, $source);

        $loaded = HandlerBindingDefinition::fromArray($binding->toArray());

        self::assertSame(['kind' => 'core', 'id' => 'orders'], $owner->toArray());
        self::assertTrue($owner->equals(RegistryOwner::core('orders')));
        self::assertTrue($loaded->owner?->equals($owner));
        self::assertSame($source->toArray(), $loaded->source?->toArray());
        self::assertNull(HandlerBindingDefinition::fromArray(\array_diff_key($binding->toArray(), ['owner' => true, 'source' => true]))->owner);
    }

    public function testRegistryMetadataRejectsMalformedArrayPayloads(): void
    {
        try {
            RegistryOwner::fromArray(['kind' => 'core']);
            self::fail('Expected malformed owner to fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Registry owner field `id` must be a string.', $e->getMessage());
        }

        try {
            RegistrySource::fromArray(['type' => 'provider', 'name' => 42]);
            self::fail('Expected malformed source to fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Registry source field `name` must be a string.', $e->getMessage());
        }
    }

    public function testBindingRegistrationContextRejectsConflictingOwner(): void
    {
        $binding = HandlerBindingDefinition::command(
            IdempotencyCommand::class,
            IdempotencyCommandHandler::class,
            '__invoke',
            'idempotent',
            true,
            0,
            'orders.create',
            owner: RegistryOwner::core('orders'),
        );

        $this->expectException(\LogicException::class);

        (new BindingRegistrationContext(
            RegistryOwner::feature('analytics'),
            RegistrySource::provider('AnalyticsProvider'),
        ))->stamp($binding);
    }

    public function testServiceReferencesResolveThroughContainerAndValidateTypes(): void
    {
        $keyExtractor = new IdempotencyKeyExtractor();
        $resolver = new ContainerServiceReferenceResolver(new TestContainer([
            IdempotencyKeyExtractor::class => $keyExtractor,
            'bad' => new \stdClass(),
        ], autowireClasses: false));
        $reference = ServiceReference::class(IdempotencyKeyExtractor::class, 'idempotency.key_extractor');

        $resolved = $resolver->resolve($reference);

        self::assertSame($keyExtractor, $resolved->service());
        self::assertSame([
            'id' => IdempotencyKeyExtractor::class,
            'expectedType' => IdempotencyKeyExtractor::class,
            'role' => 'idempotency.key_extractor',
            'resolvedClass' => IdempotencyKeyExtractor::class,
        ], $resolved->toArray());

        try {
            $resolver->resolve(ServiceReference::service('missing', IdempotencyKeyExtractorInterface::class));
            self::fail('Expected missing service reference to fail.');
        } catch (ServiceReferenceResolutionFailed $e) {
            self::assertStringContainsString('was not found', $e->getMessage());
        }

        try {
            $resolver->resolve(ServiceReference::service('bad', IdempotencyKeyExtractorInterface::class));
            self::fail('Expected invalid service type to fail.');
        } catch (ServiceReferenceResolutionFailed $e) {
            self::assertStringContainsString('must resolve to', $e->getMessage());
        }
    }

    public function testServiceReferenceResolverMapsContainerFailure(): void
    {
        $resolver = new ContainerServiceReferenceResolver(new IdempotencyFailingContainer());

        $this->expectException(ServiceReferenceResolutionFailed::class);
        $this->expectExceptionMessage('failed during container resolution');

        $resolver->resolve(ServiceReference::service('failing', IdempotencyKeyExtractorInterface::class));
    }

    public function testServiceReferenceAndResolvedReferenceRejectInvalidContracts(): void
    {
        try {
            ServiceReference::service('', IdempotencyKeyExtractorInterface::class);
            self::fail('Expected empty service id to fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('id must be non-empty', $e->getMessage());
        }

        try {
            ServiceReference::service('idempotency.key', 'Missing\\Type');
            self::fail('Expected missing expected type to fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('expected type `Missing\\Type` does not exist', $e->getMessage());
        }

        try {
            new ResolvedServiceReference(
                ServiceReference::service('idempotency.key', IdempotencyKeyExtractorInterface::class),
                new \stdClass(),
            );
            self::fail('Expected wrong resolved service to fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('must implement', $e->getMessage());
        }
    }

    public function testIdempotencyValueObjectsExposeStableDigestEqualityAndEffectShape(): void
    {
        $key = new IdempotencyKey(' request-123 ');
        $same = IntentFingerprint::sha256('{"id":1}');
        $alsoSame = IntentFingerprint::sha256('{"id":1}');
        $otherVersion = IntentFingerprint::sha256('{"id":1}', version: '2');
        $effect = new IdempotencyEffectReference('queue-message', 'job-1', 'v1');

        self::assertSame('request-123', $key->value);
        self::assertSame(\hash('sha256', 'request-123'), $key->digest);
        self::assertTrue($same->equals($alsoSame));
        self::assertFalse($same->equals($otherVersion));
        self::assertSame([
            'type' => 'queue-message',
            'id' => 'job-1',
            'version' => 'v1',
        ], $effect->toArray());
        self::assertSame(['type' => 'queue-message', 'id' => 'job-1'], (new IdempotencyEffectReference('queue-message', 'job-1'))->toArray());
    }

    public function testIdempotencyPolicyDescriptorResolverAndRegistryExposeBootstrapShape(): void
    {
        $provider = new IdempotencyPolicyProvider();
        $registry = (new IdempotencyPolicyResolver(new IdempotencyStaticServiceResolver()))->resolve([$provider]);

        self::assertTrue($registry->has('orders.create'));
        self::assertTrue($registry->hasForBinding('orders.create'));
        self::assertTrue(RegistryOwner::core('orders')->equals($registry->ownerForBinding('orders.create')));
        self::assertSame($registry->require('orders.create'), $registry->get('orders.create'));
        self::assertSame(['orders.create'], \array_keys(\iterator_to_array($registry->all())));
        self::assertArrayHasKey('orders.create', $registry->toArray());

        try {
            (new IdempotencyPolicyDescriptor('', $provider->keyExtractor, $provider->fingerprintFactory, $provider->store));
            self::fail('Expected empty bindingId to fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('bindingId must be non-empty', $e->getMessage());
        }

        try {
            IdempotencyPolicyDescriptor::forBinding('orders.create', $provider->keyExtractor, $provider->fingerprintFactory, $provider->store, retentionSeconds: 0);
            self::fail('Expected invalid retention to fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('retentionSeconds must be positive', $e->getMessage());
        }

        try {
            (new IdempotencyPolicyResolver(new IdempotencyStaticServiceResolver()))->resolve([$provider, $provider]);
            self::fail('Expected duplicate binding policy to fail.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Duplicate idempotency policy', $e->getMessage());
        }
    }

    public function testIdempotencyPolicyProviderMustReturnDescriptors(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must return only');

        (new IdempotencyPolicyResolver(new IdempotencyStaticServiceResolver()))->resolve([
            new IdempotencyMalformedPolicyProvider(),
        ]);
    }

    public function testIdempotencyPolicyDescriptorRejectsWrongReferenceRole(): void
    {
        $provider = new IdempotencyPolicyProvider();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('keyExtractor reference must expect');

        IdempotencyPolicyDescriptor::forBinding(
            'orders.create',
            ServiceReference::service(IdempotencyMemoryStore::class, IdempotencyStoreInterface::class),
            $provider->fingerprintFactory,
            $provider->store,
        );
    }

    public function testFlowContractAcceptsConfiguredIdempotentFlow(): void
    {
        $policyRegistry = $this->policyRegistry(RegistryOwner::core('orders'));
        $result = (new MessageRegistryCompiler(validationRules: [$this->idempotencyRule($policyRegistry)]))
            ->compileWithDiagnostics(
                new ClassListProvider([
                    IdempotencyCommand::class,
                    IdempotencyCommandHandler::class,
                    IdempotencyTransactionMiddleware::class,
                    RequiresIdempotencyKey::class,
                ]),
                $this->idempotentFlows(IdempotencyTransactionMiddleware::class, RequiresIdempotencyKey::class),
            );

        self::assertTrue($result->hasDefinition(), self::diagnosticsAsString($result->diagnostics));
    }

    public function testFlowContractRejectsMissingRoleWrongOwnerQueryAndAutoBindingId(): void
    {
        $policyRegistry = $this->policyRegistry(RegistryOwner::feature('other'));
        $compiler = new MessageRegistryCompiler(validationRules: [$this->idempotencyRule($policyRegistry)]);

        $missingRole = $compiler->compileWithDiagnostics(
            new ClassListProvider([
                IdempotencyCommand::class,
                IdempotencyCommandHandler::class,
                IdempotencyTransactionMiddleware::class,
            ]),
            $this->idempotentFlows(IdempotencyTransactionMiddleware::class),
        );
        self::assertFalse($missingRole->hasDefinition());
        self::assertDiagnosticCode($missingRole->diagnostics, RegistryDiagnosticCodes::FLOW_CONTRACT_VIOLATION);

        $wrongOwner = $compiler->compileWithDiagnostics(
            new ClassListProvider([
                IdempotencyCommand::class,
                IdempotencyCommandHandler::class,
                IdempotencyTransactionMiddleware::class,
                RequiresIdempotencyKey::class,
            ]),
            $this->idempotentFlows(IdempotencyTransactionMiddleware::class, RequiresIdempotencyKey::class),
        );
        self::assertFalse($wrongOwner->hasDefinition());
        self::assertStringContainsString('does not match policy owner', self::diagnosticsAsString($wrongOwner->diagnostics));

        $query = (new MessageRegistryCompiler(validationRules: [$this->idempotencyRule($this->policyRegistry(RegistryOwner::core('orders'), 'orders.lookup'))]))
            ->compileWithDiagnostics(
                new ClassListProvider([
                    IdempotencyQuery::class,
                    IdempotencyQueryHandler::class,
                    IdempotencyTransactionMiddleware::class,
                    RequiresIdempotencyKey::class,
                ]),
                $this->idempotentFlows(IdempotencyTransactionMiddleware::class, RequiresIdempotencyKey::class),
            );
        self::assertFalse($query->hasDefinition());
        self::assertStringContainsString('does not allow `query`', self::diagnosticsAsString($query->diagnostics));

        $autoBinding = (new MessageRegistryCompiler(validationRules: [$this->idempotencyRule($this->policyRegistry(RegistryOwner::core('orders'), 'auto.placeholder'))]))
            ->compileWithDiagnostics(
                new ClassListProvider([
                    IdempotencyAutoBindingCommand::class,
                    IdempotencyAutoBindingHandler::class,
                    IdempotencyTransactionMiddleware::class,
                    RequiresIdempotencyKey::class,
                ]),
                $this->idempotentFlows(IdempotencyTransactionMiddleware::class, RequiresIdempotencyKey::class),
            );
        self::assertFalse($autoBinding->hasDefinition());
        self::assertStringContainsString('requires stable bindingId', self::diagnosticsAsString($autoBinding->diagnostics));
    }

    public function testIdempotencyMiddlewareClaimsCompletesAndReturnsVoid(): void
    {
        IdempotencyRuntimeLog::$events = [];
        $store = new IdempotencyMemoryStore();
        $bus = $this->idempotentBus($store);

        self::assertNull($bus->dispatch(new IdempotencyCommand('key-1', 'payload')));

        self::assertSame(['handled:payload'], IdempotencyRuntimeLog::$events);
        self::assertCount(1, $store->claims);
        self::assertCount(1, $store->completions);
        self::assertSame('effect', $store->completions[0]->effectReference?->type);
    }

    public function testIdempotencyMiddlewareReplaysCompletedSameAsNoOp(): void
    {
        IdempotencyRuntimeLog::$events = [];
        $store = new IdempotencyMemoryStore([
            new IdempotencyCompletedSame(new DateTimeImmutable('2026-09-14T00:00:00+00:00')),
        ]);
        $bus = $this->idempotentBus($store);

        self::assertNull($bus->dispatch(new IdempotencyCommand('key-1', 'payload')));

        self::assertSame([], IdempotencyRuntimeLog::$events);
        self::assertSame([], $store->completions);
    }

    public function testIdempotencyMiddlewareMapsConflictInProgressAndMissingKey(): void
    {
        $conflictStore = new IdempotencyMemoryStore([new IdempotencyConflict()]);
        try {
            $this->idempotentBus($conflictStore)->dispatch(new IdempotencyCommand('key-1', 'changed'));
            self::fail('Expected conflict.');
        } catch (IdempotencyConflictDetected $e) {
            self::assertInstanceOf(NonRetryableMessageExceptionInterface::class, $e);
        }

        $inProgressStore = new IdempotencyMemoryStore([new IdempotencyInProgress()]);
        try {
            $this->idempotentBus($inProgressStore)->dispatch(new IdempotencyCommand('key-1', 'payload'));
            self::fail('Expected in-progress.');
        } catch (IdempotencyInProgressException $e) {
            self::assertInstanceOf(RetryableMessageExceptionInterface::class, $e);
        }

        $this->expectException(MissingIdempotencyKey::class);
        $this->idempotentBus(new IdempotencyMemoryStore())->dispatch(new IdempotencyCommand('', 'payload'));
    }

    public function testIdempotencyMiddlewareRejectsQueryAtRuntime(): void
    {
        $registry = $this->compiledRegistry(
            new ClassListProvider([
                IdempotencyQuery::class,
                IdempotencyQueryHandler::class,
                RequiresIdempotencyKey::class,
            ]),
            new FlowRegistry(FlowDefinition::sync('idempotent')->middleware(RequiresIdempotencyKey::class)),
        );
        $container = new TestContainer([], autowireClasses: true);
        $policyRegistry = $this->policyRegistry(RegistryOwner::core('orders'), 'orders.lookup');
        $container->set(RequiresIdempotencyKey::class, new RequiresIdempotencyKey(
            $policyRegistry,
            $registry,
            $registry,
            new IdempotencyFixedClock(),
        ));
        $bus = new MessageBus($registry, $registry->definition()->flows, $container);

        $this->expectException(IdempotencyUnsupportedHandlerKind::class);

        $bus->dispatch(new IdempotencyQuery('key-1'));
    }

    private function idempotentBus(IdempotencyMemoryStore $store): MessageBus
    {
        $registry = $this->compiledRegistry(
            new ClassListProvider([
                IdempotencyCommand::class,
                IdempotencyCommandHandler::class,
                RequiresIdempotencyKey::class,
            ]),
            new FlowRegistry(FlowDefinition::sync('idempotent')->middleware(RequiresIdempotencyKey::class)),
        );
        $container = new TestContainer([], autowireClasses: true);
        $policyRegistry = $this->policyRegistry(RegistryOwner::core('orders'), store: $store);
        $container->set(RequiresIdempotencyKey::class, new RequiresIdempotencyKey(
            $policyRegistry,
            $registry,
            $registry,
            new IdempotencyFixedClock(),
        ));

        return new MessageBus($registry, $registry->definition()->flows, $container);
    }

    private function compiledRegistry(ClassListProvider $provider, FlowRegistry $flows): CompiledMessageRegistry
    {
        return new CompiledMessageRegistry((new MessageRegistryCompiler())->compile($provider, $flows));
    }

    private function idempotentFlows(string ...$middleware): FlowRegistry
    {
        return new FlowRegistry(FlowDefinition::sync('idempotent')->middleware(...$middleware));
    }

    private function idempotencyRule(ResolvedIdempotencyPolicyRegistry $policyRegistry): FlowContractValidationRule
    {
        $roles = (new MiddlewareRoleRegistry())
            ->register(IdempotencyTransactionMiddleware::class, 'transaction_boundary')
            ->register(RequiresIdempotencyKey::class, IdempotencyMiddlewareRole::Guard);

        $contracts = new FlowContractRegistry(
            IdempotencyFlowContract::requiresIdempotencyKey('idempotent')
                ->requiresOrder('transaction_boundary', IdempotencyMiddlewareRole::Guard),
        );

        return new FlowContractValidationRule($contracts, $roles, [$policyRegistry]);
    }

    private function policyRegistry(
        RegistryOwner $owner,
        string $bindingId = 'orders.create',
        ?IdempotencyMemoryStore $store = null,
    ): ResolvedIdempotencyPolicyRegistry {
        $store ??= new IdempotencyMemoryStore();

        return new ResolvedIdempotencyPolicyRegistry(new ResolvedIdempotencyPolicy(
            $bindingId,
            $owner,
            RegistrySource::provider('IdempotencyPolicyProvider'),
            new ResolvedServiceReference(
                ServiceReference::service(IdempotencyKeyExtractor::class, IdempotencyKeyExtractorInterface::class),
                new IdempotencyKeyExtractor(),
            ),
            new ResolvedServiceReference(
                ServiceReference::service(IdempotencyFingerprintFactory::class, IntentFingerprintFactoryInterface::class),
                new IdempotencyFingerprintFactory(),
            ),
            new ResolvedServiceReference(
                ServiceReference::service(IdempotencyMemoryStore::class, IdempotencyStoreInterface::class),
                $store,
            ),
            new ResolvedServiceReference(
                ServiceReference::service(IdempotencyEffectFactory::class, IdempotencyEffectReferenceFactoryInterface::class),
                new IdempotencyEffectFactory(),
            ),
            3600,
        ));
    }

    /** @param list<\Wolfcharaa\MessageBus\Registry\RegistryDiagnostic> $diagnostics */
    private static function assertDiagnosticCode(array $diagnostics, string $code): void
    {
        foreach ($diagnostics as $diagnostic) {
            if ($diagnostic->code === $code) {
                return;
            }
        }

        self::fail('Diagnostic code `' . $code . '` was not found in: ' . self::diagnosticsAsString($diagnostics));
    }

    /** @param list<\Wolfcharaa\MessageBus\Registry\RegistryDiagnostic> $diagnostics */
    private static function diagnosticsAsString(array $diagnostics): string
    {
        return \implode("\n", \array_map(
            static fn ($diagnostic): string => $diagnostic->code . ': ' . $diagnostic->message,
            $diagnostics,
        ));
    }
}

final readonly class IdempotencyCommand
{
    public function __construct(
        public string $key,
        public string $payload,
    ) {
    }
}

final readonly class IdempotencyAutoBindingCommand
{
    public function __construct(public string $key)
    {
    }
}

final readonly class IdempotencyQuery
{
    public function __construct(public string $key)
    {
    }
}

final class IdempotencyRuntimeLog
{
    /** @var list<string> */
    public static array $events = [];
}

#[CommandHandler(
    message: IdempotencyCommand::class,
    flow: 'idempotent',
    bindingId: 'orders.create',
    contextAware: false,
    ownerKind: 'core',
    ownerId: 'orders',
)]
final class IdempotencyCommandHandler
{
    public function __invoke(IdempotencyCommand $message): void
    {
        IdempotencyRuntimeLog::$events[] = 'handled:' . $message->payload;
    }
}

#[CommandHandler(
    message: IdempotencyAutoBindingCommand::class,
    flow: 'idempotent',
    contextAware: false,
    ownerKind: 'core',
    ownerId: 'orders',
)]
final class IdempotencyAutoBindingHandler
{
    public function __invoke(IdempotencyAutoBindingCommand $message): void
    {
    }
}

#[QueryHandler(
    message: IdempotencyQuery::class,
    flow: 'idempotent',
    bindingId: 'orders.lookup',
    contextAware: false,
    ownerKind: 'core',
    ownerId: 'orders',
)]
final class IdempotencyQueryHandler
{
    public function __invoke(IdempotencyQuery $message): string
    {
        return 'query';
    }
}

final class IdempotencyTransactionMiddleware
{
    public function __invoke(MessageContextInterface $context, PipelineInterface $pipeline): mixed
    {
        return $pipeline->continue();
    }
}

final class IdempotencyKeyExtractor implements IdempotencyKeyExtractorInterface
{
    public function extract(object $message): IdempotencyKey
    {
        \assert($message instanceof IdempotencyCommand || $message instanceof IdempotencyQuery);
        if ($message->key === '') {
            throw MissingIdempotencyKey::forMessage($message);
        }

        return new IdempotencyKey($message->key);
    }
}

final class IdempotencyFingerprintFactory implements IntentFingerprintFactoryInterface
{
    public function fingerprint(object $message): IntentFingerprint
    {
        return IntentFingerprint::sha256(\serialize($message));
    }
}

final class IdempotencyEffectFactory implements IdempotencyEffectReferenceFactoryInterface
{
    public function effectReference(object $message, IdempotencyExecution $execution): ?IdempotencyEffectReference
    {
        return new IdempotencyEffectReference('effect', $execution->bindingId);
    }
}

final class IdempotencyMemoryStore implements IdempotencyStoreInterface
{
    /** @var list<IdempotencyDecisionInterface> */
    private array $decisions;

    /** @var list<array{IdempotencyExecution, IdempotencyKey, IntentFingerprint}> */
    public array $claims = [];

    /** @var list<IdempotencyCompletion> */
    public array $completions = [];

    /** @param list<IdempotencyDecisionInterface> $decisions */
    public function __construct(array $decisions = [])
    {
        $this->decisions = $decisions;
    }

    public function claim(
        IdempotencyExecution $execution,
        IdempotencyKey $key,
        IntentFingerprint $fingerprint,
    ): IdempotencyDecisionInterface {
        $this->claims[] = [$execution, $key, $fingerprint];

        return \array_shift($this->decisions)
            ?? new IdempotencyClaimed(new IdempotencyClaim($execution->bindingId, $key, $fingerprint, 'claim-1'));
    }

    public function complete(IdempotencyClaim $claim, IdempotencyCompletion $completion): void
    {
        $this->completions[] = $completion;
    }
}

final class IdempotencyFixedClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-09-14T10:00:00+00:00');
    }
}

final class IdempotencyFailingContainer implements \Psr\Container\ContainerInterface
{
    public function get(string $id): mixed
    {
        throw new IdempotencyContainerFailure('container failed');
    }

    public function has(string $id): bool
    {
        return true;
    }
}

final class IdempotencyContainerFailure extends \RuntimeException implements ContainerExceptionInterface
{
}

final class IdempotencyStaticServiceResolver implements ServiceReferenceResolverInterface
{
    public function resolve(ServiceReference $reference): ResolvedServiceReference
    {
        return new ResolvedServiceReference($reference, match ($reference->id) {
            IdempotencyKeyExtractor::class => new IdempotencyKeyExtractor(),
            IdempotencyFingerprintFactory::class => new IdempotencyFingerprintFactory(),
            IdempotencyMemoryStore::class => new IdempotencyMemoryStore(),
            IdempotencyEffectFactory::class => new IdempotencyEffectFactory(),
            default => throw new \RuntimeException('Unexpected service reference ' . $reference->id),
        });
    }
}

final readonly class IdempotencyPolicyProvider implements IdempotencyPolicyProviderInterface
{
    public ServiceReference $keyExtractor;

    public ServiceReference $fingerprintFactory;

    public ServiceReference $store;

    public ServiceReference $effectReferenceFactory;

    public function __construct()
    {
        $this->keyExtractor = ServiceReference::service(IdempotencyKeyExtractor::class, IdempotencyKeyExtractorInterface::class);
        $this->fingerprintFactory = ServiceReference::service(IdempotencyFingerprintFactory::class, IntentFingerprintFactoryInterface::class);
        $this->store = ServiceReference::service(IdempotencyMemoryStore::class, IdempotencyStoreInterface::class);
        $this->effectReferenceFactory = ServiceReference::service(IdempotencyEffectFactory::class, IdempotencyEffectReferenceFactoryInterface::class);
    }

    public function owner(): RegistryOwner
    {
        return RegistryOwner::core('orders');
    }

    public function source(): RegistrySource
    {
        return RegistrySource::provider(self::class, 'orders', 'config/idempotency.php');
    }

    public function policies(): iterable
    {
        yield IdempotencyPolicyDescriptor::forBinding(
            'orders.create',
            $this->keyExtractor,
            $this->fingerprintFactory,
            $this->store,
            $this->effectReferenceFactory,
            600,
        );
    }
}

final class IdempotencyMalformedPolicyProvider implements IdempotencyPolicyProviderInterface
{
    public function owner(): RegistryOwner
    {
        return RegistryOwner::core('orders');
    }

    public function source(): RegistrySource
    {
        return RegistrySource::provider(self::class);
    }

    public function policies(): iterable
    {
        yield new \stdClass();
    }
}
