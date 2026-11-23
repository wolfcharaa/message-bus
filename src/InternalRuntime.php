<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Internal\Runtime;

use Wolfcharaa\MessageBus\Context\CancellationRequested;
use Wolfcharaa\MessageBus\Context\CancellationSignal;
use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Delivery\ClaimedDelivery;
use Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddleware;
use Wolfcharaa\MessageBus\Middleware\NextHandler;
use Wolfcharaa\MessageBus\Observability\ObserverFailureSink;
use Wolfcharaa\MessageBus\Observability\RuntimeEvent;
use Wolfcharaa\MessageBus\Observability\RuntimeObserver;

/** @internal Shared validation for public opaque identifiers. */
trait OpaqueIdentity
{
    public function __construct(public readonly string $value)
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}$/D', $this->value) !== 1) {
            throw new \InvalidArgumentException('Opaque MessageBus identity is invalid.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }
}

/**
 * One-shot middleware continuation implementation.
 *
 * @internal
 * @implements NextHandler<mixed>
 */
final class OneShotNextHandler implements NextHandler
{
    private bool $called = false;

    /** @param \Closure(): mixed $next */
    public function __construct(private readonly \Closure $next) {}

    public function handle(): mixed
    {
        if ($this->called) {
            throw new \LogicException('NextHandler can be called only once.');
        }

        $this->called = true;

        return ($this->next)();
    }
}

/** @internal Handler middleware execution mechanism. */
final readonly class HandlerPipeline
{
    /** @param list<HandlerMiddleware<mixed>> $middleware */
    public function __construct(private array $middleware) {}

    /** @param \Closure(): mixed $handler */
    public function invoke(object $message, MessageContext $context, \Closure $handler): mixed
    {
        $next = $handler;

        foreach (array_reverse($this->middleware) as $middleware) {
            $current = $next;
            $next = static fn(): mixed => $middleware->process(
                $message,
                $context,
                new OneShotNextHandler($current),
            );
        }

        return $next();
    }
}

/** @internal Cancellation signal backed by the fenced active claim. */
final readonly class DeliveryCancellationSignal implements CancellationSignal
{
    public function __construct(
        private DurableDeliveryBackend $backend,
        private ClaimedDelivery $claim,
    ) {}

    public function isRequested(): bool
    {
        return $this->backend->cancellationRequested($this->claim);
    }

    public function throwIfRequested(): void
    {
        if ($this->isRequested()) {
            throw new CancellationRequested('Delivery cancellation was requested.');
        }
    }
}

/** @internal Prevents telemetry adapters from changing message/delivery truth. */
final readonly class BestEffortRuntimeObserver implements RuntimeObserver
{
    public function __construct(
        private RuntimeObserver $observer,
        private ObserverFailureSink $failureSink,
    ) {}

    public function observe(RuntimeEvent $event): void
    {
        try {
            $this->observer->observe($event);
        } catch (\Throwable $failure) {
            try {
                $this->failureSink->report($failure, $event);
            } catch (\Throwable) {
                // Telemetry is never authoritative runtime state.
            }
        }
    }
}
