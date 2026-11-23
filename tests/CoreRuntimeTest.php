<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Adapter\InMemoryDeliveryBackend;
use Wolfcharaa\MessageBus\Adapter\Postgres\SchemaManifest;
use Wolfcharaa\MessageBus\Adapter\Postgres\SchemaMigration;
use Wolfcharaa\MessageBus\Attribute\Handler;
use Wolfcharaa\MessageBus\Attribute\MessageName;
use Wolfcharaa\MessageBus\Attribute\Payload;
use Wolfcharaa\MessageBus\Codec\JsonMessageCodec;
use Wolfcharaa\MessageBus\Codec\MessageCodecs;
use Wolfcharaa\MessageBus\Codec\PhpNativeTypeGraphCompiler;
use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Contract\Command;
use Wolfcharaa\MessageBus\Contract\Event;
use Wolfcharaa\MessageBus\Contract\Query;
use Wolfcharaa\MessageBus\DefaultMessageBus;
use Wolfcharaa\MessageBus\Delivery\CancellationOutcome;
use Wolfcharaa\MessageBus\Delivery\ControlActor;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\Delivery\DeliveryState;
use Wolfcharaa\MessageBus\DurableRuntime;
use Wolfcharaa\MessageBus\Exception\PublicationFailed;
use Wolfcharaa\MessageBus\HandlerServices;
use Wolfcharaa\MessageBus\Identity\AttemptId;
use Wolfcharaa\MessageBus\Identity\CorrelationId;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\IdentityGenerator;
use Wolfcharaa\MessageBus\Identity\LeaseToken;
use Wolfcharaa\MessageBus\Identity\MessageId;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddleware;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddlewares;
use Wolfcharaa\MessageBus\Middleware\NextHandler;
use Wolfcharaa\MessageBus\Receipt\CommandReceipt;
use Wolfcharaa\MessageBus\Receipt\PublicationOutcome;
use Wolfcharaa\MessageBus\Registry\CompiledRegistry;
use Wolfcharaa\MessageBus\Registry\ExplicitClassSource;
use Wolfcharaa\MessageBus\Registry\RegistryArtifact;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;
use Wolfcharaa\MessageBus\Routing\DeliveryMode;
use Wolfcharaa\MessageBus\Routing\DeliveryRoute;
use Wolfcharaa\MessageBus\Routing\DeliveryRoutes;
use Wolfcharaa\MessageBus\Worker\DeliveryWorkerRuntime;
use Wolfcharaa\MessageBus\Worker\WorkerIteration;
use Wolfcharaa\MessageBus\Worker\WorkerProfile;

final class CoreRuntimeTest extends TestCase
{
    public function testQueryUsesOneHandlerContextAndOneShotMiddleware(): void
    {
        $queryHandler = new FindGreetingHandler();
        $middleware = new CountingMiddleware();
        $runtime = $this->runtime([$queryHandler], [$middleware]);

        $result = $runtime->bus->dispatch(new FindGreeting('Ada'));

        self::assertSame('Hello Ada', $result);
        self::assertSame(1, $queryHandler->calls);
        self::assertSame(1, $middleware->calls);
        self::assertNotNull($queryHandler->messageId);
    }

    public function testCommandIsAcceptedThenExecutedByWorker(): void
    {
        $handler = new RegisterUserHandler();
        $runtime = $this->runtime([$handler]);

        $receipt = $runtime->bus->dispatch(new RegisterUser('ada@example.test'));

        self::assertInstanceOf(CommandReceipt::class, $receipt);
        self::assertSame(PublicationOutcome::Accepted, $receipt->entry->outcome);
        $deliveryId = $receipt->entry->deliveryId;
        self::assertInstanceOf(DeliveryId::class, $deliveryId);
        self::assertSame(DeliveryState::Accepted, $this->statusState($runtime->backend, $deliveryId));
        self::assertSame(0, $handler->calls);

        $worker = new DeliveryWorkerRuntime(
            $runtime->backend,
            $runtime->codecs,
            $runtime->registry,
            $runtime->services,
            $runtime->bus,
        );
        $result = $worker->runOnce(new WorkerProfile('mail', 'default'), 'worker:one');

        self::assertSame(WorkerIteration::Succeeded, $result);
        self::assertSame(1, $handler->calls);
        self::assertSame(DeliveryState::Succeeded, $this->statusState($runtime->backend, $deliveryId));
    }

    public function testEventAttemptsEverySubscriptionAndExposesPartialReceipt(): void
    {
        $successful = new SuccessfulProjection();
        $failing = new FailingProjection();
        $runtime = $this->runtime([$successful, $failing]);

        try {
            $runtime->bus->dispatch(new UserRegistered('user-1'));
            self::fail('PublicationFailed was not thrown.');
        } catch (PublicationFailed $failure) {
            self::assertFalse($failure->receipt->isComplete());
            self::assertCount(2, $failure->receipt->entries);
            self::assertSame(
                [PublicationOutcome::Failed, PublicationOutcome::Completed],
                array_column($failure->receipt->entries, 'outcome'),
            );
        }

        self::assertSame(1, $successful->calls);
        self::assertSame(1, $failing->calls);
    }

    public function testCancellationAndReplayPreserveHistory(): void
    {
        $runtime = $this->runtime([new RegisterUserHandler()]);
        $receipt = $runtime->bus->dispatch(new RegisterUser('cancel@example.test'));
        $deliveryId = $receipt->entry->deliveryId;
        self::assertInstanceOf(DeliveryId::class, $deliveryId);

        $cancel = $runtime->backend->cancel($deliveryId, new ControlActor('operator-1', 'duplicate'));
        self::assertSame(CancellationOutcome::CancelledBeforeStart, $cancel->outcome);
        self::assertSame(DeliveryState::Cancelled, $this->statusState($runtime->backend, $deliveryId));

        $replay = $runtime->backend->replay(
            $deliveryId,
            new DeliveryPolicy('manual', maxAttempts: 1),
            new ControlActor('operator-1', 'approved retry'),
        );
        self::assertNotSame($deliveryId->value, $replay->newDeliveryId->value);
        self::assertSame(DeliveryState::Accepted, $this->statusState($runtime->backend, $replay->newDeliveryId));
        self::assertSame(DeliveryState::Cancelled, $this->statusState($runtime->backend, $deliveryId));
    }

    public function testCompilerReportsMissingQueryHandlerButAllowsEmptyEvent(): void
    {
        $result = (new RegistryCompiler())->compile(new ExplicitClassSource([
            OrphanQuery::class,
            EmptyEvent::class,
        ]));

        self::assertFalse($result->isValid());
        self::assertSame(['MBR014'], array_column($result->diagnostics, 'code'));
    }

    public function testRegistryArtifactIsDeterministicAndRejectsTampering(): void
    {
        $registry = (new RegistryCompiler())->compile(new ExplicitClassSource([
            FindGreeting::class,
            FindGreetingHandler::class,
        ]))->requireRegistry();
        $artifact = RegistryArtifact::fromRegistry($registry, 'build:test-001');

        $restored = RegistryArtifact::fromJson($artifact->toJson());
        self::assertSame($registry->fingerprint, $restored->registry()->fingerprint);
        self::assertSame('build:test-001', $restored->applicationBuildId);
        $restored->assertApplicationBuildId('build:test-001');

        try {
            $restored->assertApplicationBuildId('build:other');
            self::fail('Registry artifact accepted another application build.');
        } catch (\UnexpectedValueException) {
            self::addToAssertionCount(1);
        }

        $data = json_decode($artifact->toJson(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);
        self::assertIsArray($data['bindings'] ?? null);
        self::assertIsArray($data['bindings'][0] ?? null);
        $data['bindings'][0]['handler_method'] = 'tampered';
        $this->expectException(\UnexpectedValueException::class);
        RegistryArtifact::fromJson(json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testPackagedPostgresManifestVerifiesImmutableArtifacts(): void
    {
        $manifest = new SchemaManifest();
        $migrations = $manifest->migrations();

        self::assertSame(
            ['delivery:0001_v7_initial', 'worker-control:0001_v7_initial'],
            array_map(static fn(SchemaMigration $migration): string => $migration->key(), $migrations),
        );
        self::assertStringContainsString('__SCHEMA__.message_bus__deliveries', $migrations[0]->sql);
        $catalog = $manifest->catalog();
        self::assertIsArray($catalog['tables'] ?? null);
        self::assertCount(10, $catalog['tables']);
    }

    public function testPhpNativeGraphProducesMinimalDeterministicAllowlist(): void
    {
        $graph = (new PhpNativeTypeGraphCompiler())->compile(NativeCommand::class);

        self::assertSame([NativeCommand::class, NativeValue::class], $graph->classes);
        self::assertSame([NativeValue::class], $graph->properties[NativeCommand::class]['value']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $graph->fingerprint);
    }

    /**
     * @param list<object> $handlers
     * @param list<HandlerMiddleware<mixed>> $middleware
     */
    private function runtime(array $handlers, array $middleware = []): RuntimeFixture
    {
        $messages = [];
        foreach ($handlers as $handler) {
            foreach ((new \ReflectionClass($handler))->getMethods() as $method) {
                if ($method->getAttributes(Handler::class) !== []) {
                    $type = $method->getParameters()[0]->getType();
                    if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                        throw new \LogicException('Test handler message type must be a named class.');
                    }
                    $messageClass = $type->getName();
                    if (!class_exists($messageClass)) {
                        throw new \LogicException('Test message class does not exist.');
                    }
                    $messages[] = $messageClass;
                }
            }
        }
        $messages = array_values(array_unique($messages));
        $handlerClasses = array_map(static fn(object $handler): string => $handler::class, $handlers);
        $registry = (new RegistryCompiler())->compile(
            new ExplicitClassSource([...$messages, ...$handlerClasses]),
        )->requireRegistry();
        $services = new HandlerServices(array_combine($handlerClasses, $handlers));
        $routes = new DeliveryRoutes([
            RegisterUser::class => new DeliveryRoute(DeliveryMode::Durable),
            'audit.user-registered' => new DeliveryRoute(DeliveryMode::Inline),
            'projection.user-registered' => new DeliveryRoute(DeliveryMode::Inline),
        ]);
        $codecs = new MessageCodecs(
            [RegisterUser::class => new JsonMessageCodec(RegisterUser::class)],
            ['user.register' => [1 => new JsonMessageCodec(RegisterUser::class)]],
        );
        $clock = new FixedClock();
        $identities = new SequenceIdentities();
        $backend = new InMemoryDeliveryBackend($clock, $identities);
        $servicesByClass = array_combine($handlerClasses, $handlers);
        $bus = new DefaultMessageBus(
            $registry,
            new HandlerServices($servicesByClass),
            $routes,
            middleware: new HandlerMiddlewares($middleware),
            durable: new DurableRuntime(
                $backend,
                $codecs,
                new DeliveryPolicies(['default' => new DeliveryPolicy('default')]),
            ),
            clock: $clock,
            identities: $identities,
        );

        return new RuntimeFixture($bus, $backend, $codecs, $registry, $services);
    }

    private function statusState(
        InMemoryDeliveryBackend $backend,
        DeliveryId $deliveryId,
    ): DeliveryState {
        $status = $backend->status($deliveryId);
        self::assertNotNull($status);

        return $status->state;
    }
}

/** @implements Query<string> */
final readonly class FindGreeting implements Query
{
    public function __construct(public string $name) {}
}

/** @implements Query<string> */
final readonly class OrphanQuery implements Query {}

#[MessageName('user.register')]
#[Payload(1)]
final readonly class RegisterUser implements Command
{
    public function __construct(public string $email) {}
}

#[MessageName('user.registered')]
final readonly class UserRegistered implements Event
{
    public function __construct(public string $id) {}
}

#[MessageName('event.empty')]
final readonly class EmptyEvent implements Event {}

#[MessageName('native.command')]
#[Payload(1, 'php-native')]
final readonly class NativeCommand implements Command
{
    public function __construct(public NativeValue $value) {}
}

final readonly class NativeValue
{
    public function __construct(public string $value) {}
}

final class FindGreetingHandler
{
    public int $calls = 0;

    public ?string $messageId = null;

    #[Handler]
    public function __invoke(FindGreeting $query, MessageContext $context): string
    {
        ++$this->calls;
        $this->messageId = $context->metadata()->messageId->value;

        return 'Hello ' . $query->name;
    }
}

final class RegisterUserHandler
{
    public int $calls = 0;

    #[Handler]
    public function __invoke(RegisterUser $command): void
    {
        ++$this->calls;
    }
}

final class SuccessfulProjection
{
    public int $calls = 0;

    #[Handler('projection.user-registered')]
    public function __invoke(UserRegistered $event): void
    {
        ++$this->calls;
    }
}

final class FailingProjection
{
    public int $calls = 0;

    #[Handler('audit.user-registered')]
    public function __invoke(UserRegistered $event): void
    {
        ++$this->calls;

        throw new \RuntimeException('audit unavailable');
    }
}

/** @implements HandlerMiddleware<mixed> */
final class CountingMiddleware implements HandlerMiddleware
{
    public int $calls = 0;

    public function process(object $message, MessageContext $context, NextHandler $next): mixed
    {
        ++$this->calls;

        return $next->handle();
    }
}

final readonly class FixedClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-22T12:00:00+00:00');
    }
}

final class SequenceIdentities implements IdentityGenerator
{
    private int $sequence = 0;

    public function messageId(): MessageId
    {
        return new MessageId($this->next('msg'));
    }

    public function deliveryId(): DeliveryId
    {
        return new DeliveryId($this->next('delivery'));
    }

    public function attemptId(): AttemptId
    {
        return new AttemptId($this->next('attempt'));
    }

    public function correlationId(): CorrelationId
    {
        return new CorrelationId($this->next('correlation'));
    }

    public function leaseToken(): LeaseToken
    {
        return new LeaseToken($this->next('lease'));
    }

    private function next(string $prefix): string
    {
        return \sprintf('%s:%08d', $prefix, ++$this->sequence);
    }
}

final readonly class RuntimeFixture
{
    public function __construct(
        public DefaultMessageBus $bus,
        public InMemoryDeliveryBackend $backend,
        public MessageCodecs $codecs,
        public CompiledRegistry $registry,
        public HandlerServices $services,
    ) {}
}
