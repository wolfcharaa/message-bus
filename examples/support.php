<?php

declare(strict_types=1);

namespace MessageBusExamples;

use Wolfcharaa\MessageBus\Attribute\Handler;
use Wolfcharaa\MessageBus\Codec\MessageUpcaster;
use Wolfcharaa\MessageBus\Codec\UpcastingJsonMessageCodec;
use Wolfcharaa\MessageBus\Context\CancellationSignal;
use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Context\MessageContextFactories;
use Wolfcharaa\MessageBus\Context\MessageContextFactory;
use Wolfcharaa\MessageBus\Context\MessageContextSeed;
use Wolfcharaa\MessageBus\Contract\Command;
use Wolfcharaa\MessageBus\Contract\Message;
use Wolfcharaa\MessageBus\Contract\MessageBus;
use Wolfcharaa\MessageBus\Contract\Query;
use Wolfcharaa\MessageBus\DefaultMessageBus;
use Wolfcharaa\MessageBus\HandlerServices;
use Wolfcharaa\MessageBus\Idempotency\AtomicIdempotencyBoundary;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyAttempt;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentity;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyKey;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyOutcome;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyResult;
use Wolfcharaa\MessageBus\Idempotency\IntentFingerprint;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Metadata\MessageMetadata;
use Wolfcharaa\MessageBus\Metadata\MetadataEnricher;
use Wolfcharaa\MessageBus\Metadata\MetadataSchema;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddleware;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddlewares;
use Wolfcharaa\MessageBus\Middleware\NextHandler;
use Wolfcharaa\MessageBus\Observability\RuntimeEvent;
use Wolfcharaa\MessageBus\Observability\RuntimeObserver;
use Wolfcharaa\MessageBus\Registry\ExplicitClassSource;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;
use Wolfcharaa\MessageBus\Routing\DeliveryRoutes;

/** @implements Query<string> */
final readonly class Greeting implements Query
{
    public function __construct(public string $name) {}
}

final readonly class GreetingHandler
{
    #[Handler]
    public function __invoke(Greeting $query): string
    {
        return 'Hello ' . $query->name;
    }
}

/** @implements HandlerMiddleware<mixed> */
final readonly class UppercaseMiddleware implements HandlerMiddleware
{
    public function process(object $message, MessageContext $context, NextHandler $next): mixed
    {
        $result = $next->handle();

        return is_string($result) ? strtoupper($result) : $result;
    }
}

final class RecordingObserver implements RuntimeObserver
{
    /** @var list<RuntimeEvent> */
    public array $events = [];

    public function observe(RuntimeEvent $event): void
    {
        $this->events[] = $event;
    }
}

interface TenantContext extends MessageContext
{
    public function tenant(): string;
}

final readonly class TenantContextValue implements TenantContext
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

    public function tenant(): string
    {
        return (string) $this->seed->metadata->application['tenant'];
    }
}

/** @implements MessageContextFactory<TenantContext> */
final readonly class TenantContextFactory implements MessageContextFactory
{
    public function create(MessageContextSeed $seed): MessageContext
    {
        return new TenantContextValue($seed);
    }
}

/** @implements Query<string> */
final readonly class CurrentTenant implements Query {}

final readonly class CurrentTenantHandler
{
    #[Handler]
    public function __invoke(CurrentTenant $query, TenantContext $context): string
    {
        return $context->tenant();
    }
}

final readonly class TenantEnricher implements MetadataEnricher
{
    public function enrich(object $message): array
    {
        return ['tenant' => 'example'];
    }
}

final readonly class EmailChanged implements Command
{
    public function __construct(public string $email) {}
}

final readonly class RenameEmail implements MessageUpcaster
{
    public function upcast(array $payload): array
    {
        return ['email' => (string) $payload['address']];
    }
}

final class MemoryIdempotencyBoundary implements AtomicIdempotencyBoundary
{
    /** @var array<string, true> */
    private array $completed = [];

    public function execute(
        IdempotencyAttempt $attempt,
        callable $effect,
    ): IdempotencyResult {
        $key = $attempt->identity->targetId . ':' . $attempt->identity->key->digest();
        if (isset($this->completed[$key])) {
            return new IdempotencyResult(IdempotencyOutcome::Replayed);
        }
        $effect();
        $this->completed[$key] = true;

        return new IdempotencyResult(IdempotencyOutcome::Executed);
    }
}

/** @param list<HandlerMiddleware<mixed>> $middleware */
function greetingBus(array $middleware = [], ?RuntimeObserver $observer = null): DefaultMessageBus
{
    $handler = new GreetingHandler();
    $registry = (new RegistryCompiler())->compile(new ExplicitClassSource([
        Greeting::class,
        GreetingHandler::class,
    ]))->requireRegistry();

    return new DefaultMessageBus(
        $registry,
        new HandlerServices([GreetingHandler::class => $handler]),
        new DeliveryRoutes([]),
        middleware: new HandlerMiddlewares($middleware),
        observer: $observer ?? new \Wolfcharaa\MessageBus\Observability\NullRuntimeObserver(),
    );
}

function run(string $scenario): string
{
    return match ($scenario) {
        'orchestration' => (string) greetingBus()->dispatch(new Greeting('Ada')),
        'middleware' => (string) greetingBus([new UppercaseMiddleware()])->dispatch(new Greeting('Ada')),
        'observer' => observerScenario(),
        'custom-context' => contextScenario(),
        'codec-upcast' => codecScenario(),
        'idempotency' => idempotencyScenario(),
        default => throw new \InvalidArgumentException('Unknown example scenario: ' . $scenario),
    };
}

function observerScenario(): string
{
    $observer = new RecordingObserver();
    greetingBus(observer: $observer)->dispatch(new Greeting('Ada'));

    return implode(',', array_map(static fn(RuntimeEvent $event): string => $event->type->value, $observer->events));
}

function contextScenario(): string
{
    $handler = new CurrentTenantHandler();
    $contexts = new MessageContextFactories([TenantContext::class => new TenantContextFactory()]);
    $registry = (new RegistryCompiler($contexts))->compile(new ExplicitClassSource([
        CurrentTenant::class,
        CurrentTenantHandler::class,
    ]))->requireRegistry();
    $bus = new DefaultMessageBus(
        $registry,
        new HandlerServices([CurrentTenantHandler::class => $handler]),
        new DeliveryRoutes([]),
        $contexts,
        metadataSchema: new MetadataSchema(['tenant']),
        metadataEnricher: new TenantEnricher(),
    );

    return (string) $bus->dispatch(new CurrentTenant());
}

function codecScenario(): string
{
    $message = (new UpcastingJsonMessageCodec(EmailChanged::class, [new RenameEmail()]))
        ->decode('{"address":"ada@example.test"}');

    return $message instanceof EmailChanged ? $message->email : 'unexpected';
}

function idempotencyScenario(): string
{
    $boundary = new MemoryIdempotencyBoundary();
    $identity = new IdempotencyIdentity(
        'billing',
        new IdempotencyKey('invoice:42'),
        new IntentFingerprint('sha256', 1, hash('sha256', 'invoice:42')),
    );
    $delivery = new DeliveryId('delivery:example-idempotency');
    $attempt = new IdempotencyAttempt($identity, $delivery, new \DateTimeImmutable('+1 day'));
    $effects = 0;
    $first = $boundary->execute($attempt, static function () use (&$effects): void {
        ++$effects;
    });
    $second = $boundary->execute($attempt, static function () use (&$effects): void {
        ++$effects;
    });

    return $first->outcome->value . ',' . $second->outcome->value . ',' . $effects;
}
