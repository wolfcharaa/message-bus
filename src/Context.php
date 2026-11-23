<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Context;

use Wolfcharaa\MessageBus\Contract\Message;
use Wolfcharaa\MessageBus\Contract\MessageBus;
use Wolfcharaa\MessageBus\Metadata\MessageMetadata;

/**
 *  Read-only cooperative cancellation capability exposed to long handlers.
 *
 * @api application
 */
interface CancellationSignal
{
    public function isRequested(): bool;

    public function throwIfRequested(): void;
}

/**
 *  Raised only after a handler explicitly observes a cancellation request.
 *
 * @api application
 */
final class CancellationRequested extends \RuntimeException {}

/**
 *  Cancellation signal for inline execution and non-cancellable work.
 *
 * @api application
 */
final readonly class NullCancellationSignal implements CancellationSignal
{
    public function isRequested(): bool
    {
        return false;
    }

    public function throwIfRequested(): void {}
}

/**
 *  Handler-side view of nested dispatch, portable metadata and cancellation.
 *
 * @api application
 */
interface MessageContext
{
    /**
     * @template TResult
     * @param Message<TResult> $message
     * @return TResult
     */
    public function dispatch(Message $message): mixed;

    public function metadata(): MessageMetadata;

    public function cancellation(): CancellationSignal;
}

/**
 *  Restricted input from which a context factory creates one invocation context.
 *
 * @api application
 */
final readonly class MessageContextSeed
{
    public function __construct(
        public MessageBus $dispatcher,
        public MessageMetadata $metadata,
        public CancellationSignal $cancellation,
    ) {}
}

/**
 * Creates the exact context type requested by a handler's second parameter.
 *
 * @template TContext of MessageContext
 * @api spi
 */
interface MessageContextFactory
{
    /** @return TContext */
    public function create(MessageContextSeed $seed): MessageContext;
}

/**
 *  Default immutable context.
 *
 * @api application
 */
final readonly class DefaultMessageContext implements MessageContext
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

/** Built-in factory for the base MessageContext contract. */
/**
 *  @implements MessageContextFactory<MessageContext>
 *
 * @api adapter
 */
final readonly class DefaultMessageContextFactory implements MessageContextFactory
{
    public function create(MessageContextSeed $seed): MessageContext
    {
        return new DefaultMessageContext($seed);
    }
}

/** Resolves the exact context factory from the current application scope. */
/** @api spi */
interface MessageContextFactoryResolver
{
    /** @param class-string<MessageContext> $context */
    /** @return MessageContextFactory<MessageContext> */
    public function factory(string $context): MessageContextFactory;

    /** @param class-string<MessageContext> $context */
    public function has(string $context): bool;
}

/**
 * Exact fixed factory map for applications that do not need scoped resolution.
 *
 * @api application
 */
final readonly class MessageContextFactories implements MessageContextFactoryResolver
{
    /** @var array<class-string<MessageContext>, MessageContextFactory<MessageContext>> */
    private array $factories;

    /** @param array<class-string<MessageContext>, MessageContextFactory<MessageContext>> $factories */
    public function __construct(array $factories = [])
    {
        $base = $factories[MessageContext::class] ?? new DefaultMessageContextFactory();
        $this->factories = [MessageContext::class => $base] + $factories;
    }

    /** @param class-string<MessageContext> $context */
    /** @return MessageContextFactory<MessageContext> */
    public function factory(string $context): MessageContextFactory
    {
        return $this->factories[$context]
            ?? throw new \LogicException('No MessageContextFactory is registered for ' . $context . '.');
    }

    /** @param class-string<MessageContext> $context */
    public function has(string $context): bool
    {
        return isset($this->factories[$context]);
    }
}
