<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Adapter\InMemoryDeliveryBackend;
use Wolfcharaa\MessageBus\Attribute\Handler;
use Wolfcharaa\MessageBus\Attribute\MessageName;
use Wolfcharaa\MessageBus\Attribute\Payload;
use Wolfcharaa\MessageBus\Codec\JsonMessageCodec;
use Wolfcharaa\MessageBus\Codec\MessageCodecs;
use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Contract\Command;
use Wolfcharaa\MessageBus\Contract\Event;
use Wolfcharaa\MessageBus\Contract\Query;
use Wolfcharaa\MessageBus\DefaultMessageBus;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\DurableRuntime;
use Wolfcharaa\MessageBus\HandlerServiceResolver;
use Wolfcharaa\MessageBus\HandlerServices;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddleware;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddlewares;
use Wolfcharaa\MessageBus\Middleware\NextHandler;
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

final class HandlerMiddlewareTest extends TestCase
{
    public function testInlinePipelineWrapsHandlerWithGlobalThenLocalMiddleware(): void
    {
        $trace = new MiddlewareTrace();
        $handler = new MiddlewareQueryHandler($trace);
        $services = new HandlerServices([
            MiddlewareQueryHandler::class => $handler,
            LocalMiddleware::class => new LocalMiddleware($trace),
        ]);
        $registry = $this->registry([
            MiddlewareQuery::class,
            MiddlewareQueryHandler::class,
        ]);
        $bus = new DefaultMessageBus(
            $registry,
            $services,
            new DeliveryRoutes([]),
            middleware: new HandlerMiddlewares([new GlobalMiddleware($trace)]),
        );

        self::assertSame('handled:value', $bus->dispatch(new MiddlewareQuery('value')));
        self::assertSame([
            'global.before',
            'local.before',
            'handler.query',
            'local.after',
            'global.after',
        ], $trace->entries);
    }

    public function testDurablePipelineWrapsHandlerWithGlobalPolicyThenLocalMiddleware(): void
    {
        $trace = new MiddlewareTrace();
        $handler = new MiddlewareCommandHandler($trace);
        $services = new HandlerServices([
            MiddlewareCommandHandler::class => $handler,
            PolicyMiddleware::class => new PolicyMiddleware($trace),
            LocalMiddleware::class => new LocalMiddleware($trace),
        ]);
        $registry = $this->registry([
            MiddlewareCommand::class,
            MiddlewareCommandHandler::class,
        ]);
        $policy = new DeliveryPolicy('middleware', middleware: [PolicyMiddleware::class]);
        $clock = new FixedClock();
        $identities = new SequenceIdentities();
        $backend = new InMemoryDeliveryBackend($clock, $identities);
        $codecs = new MessageCodecs(
            [MiddlewareCommand::class => new JsonMessageCodec(MiddlewareCommand::class)],
            ['middleware.command' => [1 => new JsonMessageCodec(MiddlewareCommand::class)]],
        );
        $middlewares = new HandlerMiddlewares([new GlobalMiddleware($trace)]);
        $bus = new DefaultMessageBus(
            $registry,
            $services,
            new DeliveryRoutes([
                'command.middleware' => new DeliveryRoute(DeliveryMode::Durable, 'middleware'),
            ]),
            middleware: $middlewares,
            durable: new DurableRuntime(
                $backend,
                $codecs,
                new DeliveryPolicies(['middleware' => $policy]),
            ),
            clock: $clock,
            identities: $identities,
        );
        $bus->dispatch(new MiddlewareCommand('value'));

        $worker = new DeliveryWorkerRuntime(
            $backend,
            $codecs,
            $registry,
            $services,
            $bus,
            middleware: $middlewares,
            clock: $clock,
        );

        self::assertSame(
            WorkerIteration::Succeeded,
            $worker->runOnce(new WorkerProfile('middleware', 'default'), 'worker:middleware'),
        );
        self::assertSame([
            'global.before',
            'policy.before',
            'local.before',
            'handler.command',
            'local.after',
            'policy.after',
            'global.after',
        ], $trace->entries);
    }

    public function testEventSubscriptionsUseOnlyTheirOwnLocalMiddleware(): void
    {
        $trace = new MiddlewareTrace();
        $first = new FirstMiddlewareEventHandler($trace);
        $second = new SecondMiddlewareEventHandler($trace);
        $registry = $this->registry([
            MiddlewareEvent::class,
            FirstMiddlewareEventHandler::class,
            SecondMiddlewareEventHandler::class,
        ]);
        $services = new HandlerServices([
            FirstMiddlewareEventHandler::class => $first,
            SecondMiddlewareEventHandler::class => $second,
            FirstLocalMiddleware::class => new FirstLocalMiddleware($trace),
            SecondLocalMiddleware::class => new SecondLocalMiddleware($trace),
        ]);
        $bus = new DefaultMessageBus(
            $registry,
            $services,
            new DeliveryRoutes([
                'event.first' => new DeliveryRoute(DeliveryMode::Inline),
                'event.second' => new DeliveryRoute(DeliveryMode::Inline),
            ]),
        );

        $bus->dispatch(new MiddlewareEvent());

        self::assertSame([
            'first.before',
            'handler.first',
            'first.after',
            'second.before',
            'handler.second',
            'second.after',
        ], $trace->entries);
    }

    public function testMiddlewareContainerFailureEscapesInlineDispatchUnchanged(): void
    {
        $trace = new MiddlewareTrace();
        $failure = new MiddlewareContainerFailure('Cannot autowire local middleware dependency.');
        $services = new SelectiveFailingMiddlewareResolver(
            new HandlerServices([
                MiddlewareQueryHandler::class => new MiddlewareQueryHandler($trace),
            ]),
            LocalMiddleware::class,
            $failure,
        );
        $bus = new DefaultMessageBus(
            $this->registry([MiddlewareQuery::class, MiddlewareQueryHandler::class]),
            $services,
            new DeliveryRoutes([]),
        );

        try {
            $bus->dispatch(new MiddlewareQuery('value'));
            self::fail('Middleware DI failure was not exposed.');
        } catch (MiddlewareContainerFailure $caught) {
            self::assertSame($failure, $caught);
        }
    }

    public function testMiddlewareContainerFailureIsSettledAtHandlerResolutionAndRethrownByWorker(): void
    {
        $trace = new MiddlewareTrace();
        $handler = new MiddlewareCommandHandler($trace);
        $available = new HandlerServices([
            MiddlewareCommandHandler::class => $handler,
            LocalMiddleware::class => new LocalMiddleware($trace),
        ]);
        $failure = new MiddlewareContainerFailure('Cannot autowire durable middleware dependency.');
        $failing = new SelectiveFailingMiddlewareResolver($available, LocalMiddleware::class, $failure);
        $registry = $this->registry([MiddlewareCommand::class, MiddlewareCommandHandler::class]);
        $clock = new FixedClock();
        $identities = new SequenceIdentities();
        $backend = new InMemoryDeliveryBackend($clock, $identities);
        $codecs = new MessageCodecs(
            [MiddlewareCommand::class => new JsonMessageCodec(MiddlewareCommand::class)],
            ['middleware.command' => [1 => new JsonMessageCodec(MiddlewareCommand::class)]],
        );
        $bus = new DefaultMessageBus(
            $registry,
            $available,
            new DeliveryRoutes([
                'command.middleware' => new DeliveryRoute(DeliveryMode::Durable),
            ]),
            durable: new DurableRuntime(
                $backend,
                $codecs,
                new DeliveryPolicies(['default' => new DeliveryPolicy('default')]),
            ),
            clock: $clock,
            identities: $identities,
        );
        $receipt = $bus->dispatch(new MiddlewareCommand('value'));
        $deliveryId = $receipt->entry->deliveryId;
        self::assertNotNull($deliveryId);
        $worker = new DeliveryWorkerRuntime(
            $backend,
            $codecs,
            $registry,
            $failing,
            $bus,
            clock: $clock,
        );

        try {
            $worker->runOnce(new WorkerProfile('middleware-failure', 'default'), 'worker:middleware-failure');
            self::fail('Durable middleware DI failure was not exposed.');
        } catch (MiddlewareContainerFailure $caught) {
            self::assertSame($failure, $caught);
        }

        $status = $backend->status($deliveryId);
        self::assertNotNull($status);
        self::assertNotNull($status->lastFailure);
        self::assertSame('handler_resolution', $status->lastFailure->stage->value);
        self::assertSame(MiddlewareContainerFailure::class, $status->lastFailure->failureClass);
    }

    public function testRegistryArtifactRetainsHandlerLocalMiddleware(): void
    {
        $registry = $this->registry([MiddlewareQuery::class, MiddlewareQueryHandler::class]);
        $restored = RegistryArtifact::fromJson(RegistryArtifact::fromRegistry($registry)->toJson())->registry();

        self::assertSame(
            [LocalMiddleware::class],
            $restored->handlersFor(new MiddlewareQuery('value'))[0]->middleware,
        );
        self::assertSame($registry->fingerprint, $restored->fingerprint);
    }

    /** @param list<class-string> $classes */
    private function registry(array $classes): CompiledRegistry
    {
        return (new RegistryCompiler())->compile(new ExplicitClassSource($classes))->requireRegistry();
    }
}

final class MiddlewareTrace
{
    /** @var list<string> */
    public array $entries = [];
}

/** @implements Query<string> */
final readonly class MiddlewareQuery implements Query
{
    public function __construct(public string $value) {}
}

final readonly class MiddlewareQueryHandler
{
    public function __construct(private MiddlewareTrace $trace) {}

    #[Handler(middleware: [LocalMiddleware::class])]
    public function __invoke(MiddlewareQuery $query): string
    {
        $this->trace->entries[] = 'handler.query';

        return 'handled:' . $query->value;
    }
}

#[MessageName('middleware.command')]
#[Payload]
final readonly class MiddlewareCommand implements Command
{
    public function __construct(public string $value) {}
}

final readonly class MiddlewareCommandHandler
{
    public function __construct(private MiddlewareTrace $trace) {}

    #[Handler(bindingId: 'command.middleware', middleware: [LocalMiddleware::class])]
    public function __invoke(MiddlewareCommand $command): void
    {
        $this->trace->entries[] = 'handler.command';
    }
}

#[MessageName('middleware.event')]
final readonly class MiddlewareEvent implements Event {}

final readonly class FirstMiddlewareEventHandler
{
    public function __construct(private MiddlewareTrace $trace) {}

    #[Handler(subscriptionId: 'event.first', middleware: [FirstLocalMiddleware::class])]
    public function __invoke(MiddlewareEvent $event): void
    {
        $this->trace->entries[] = 'handler.first';
    }
}

final readonly class SecondMiddlewareEventHandler
{
    public function __construct(private MiddlewareTrace $trace) {}

    #[Handler(subscriptionId: 'event.second', middleware: [SecondLocalMiddleware::class])]
    public function __invoke(MiddlewareEvent $event): void
    {
        $this->trace->entries[] = 'handler.second';
    }
}

/** @implements HandlerMiddleware<mixed> */
final readonly class GlobalMiddleware implements HandlerMiddleware
{
    public function __construct(private MiddlewareTrace $trace) {}

    public function process(object $message, MessageContext $context, NextHandler $next): mixed
    {
        $this->trace->entries[] = 'global.before';
        $result = $next->handle();
        $this->trace->entries[] = 'global.after';

        return $result;
    }
}

/** @implements HandlerMiddleware<mixed> */
final readonly class PolicyMiddleware implements HandlerMiddleware
{
    public function __construct(private MiddlewareTrace $trace) {}

    public function process(object $message, MessageContext $context, NextHandler $next): mixed
    {
        $this->trace->entries[] = 'policy.before';
        $result = $next->handle();
        $this->trace->entries[] = 'policy.after';

        return $result;
    }
}

/** @implements HandlerMiddleware<mixed> */
final readonly class LocalMiddleware implements HandlerMiddleware
{
    public function __construct(private MiddlewareTrace $trace) {}

    public function process(object $message, MessageContext $context, NextHandler $next): mixed
    {
        $this->trace->entries[] = 'local.before';
        $result = $next->handle();
        $this->trace->entries[] = 'local.after';

        return $result;
    }
}

/** @implements HandlerMiddleware<mixed> */
final readonly class FirstLocalMiddleware implements HandlerMiddleware
{
    public function __construct(private MiddlewareTrace $trace) {}

    public function process(object $message, MessageContext $context, NextHandler $next): mixed
    {
        $this->trace->entries[] = 'first.before';
        $result = $next->handle();
        $this->trace->entries[] = 'first.after';

        return $result;
    }
}

/** @implements HandlerMiddleware<mixed> */
final readonly class SecondLocalMiddleware implements HandlerMiddleware
{
    public function __construct(private MiddlewareTrace $trace) {}

    public function process(object $message, MessageContext $context, NextHandler $next): mixed
    {
        $this->trace->entries[] = 'second.before';
        $result = $next->handle();
        $this->trace->entries[] = 'second.after';

        return $result;
    }
}

final class MiddlewareContainerFailure extends \RuntimeException {}

final readonly class SelectiveFailingMiddlewareResolver implements HandlerServiceResolver
{
    /** @param class-string $failingClass */
    public function __construct(
        private HandlerServiceResolver $services,
        private string $failingClass,
        private \Throwable $failure,
    ) {}

    public function get(string $class): object
    {
        if ($class === $this->failingClass) {
            throw $this->failure;
        }

        return $this->services->get($class);
    }

    public function has(string $class): bool
    {
        return $class === $this->failingClass || $this->services->has($class);
    }
}
