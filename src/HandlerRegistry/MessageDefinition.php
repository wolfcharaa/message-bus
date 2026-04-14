<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\HandlerRegistry;

use Wolfcharaa\MessageBus\Handler\Handler;
use Wolfcharaa\MessageBus\Message\Event;
use Wolfcharaa\MessageBus\Message\Message;
use Wolfcharaa\MessageBus\Middleware\Middleware;

/**
 * @template TResult
 * @template TMessage of Message<TResult>|object
 */
final class MessageDefinition
{
    /** @var class-string<Message|object> $messageClass */
    private string $messageClass;
    private bool $isEvent = false;

    /** @var array<array{0: class-string, 1: string}> $handlers */
    private array $handlers = [];

    /**
     * @var array<class-string<Middleware>> $middleware
     */
    private array $middleware = [];

    private ?Handler $handler = null;

    /** @var string|null $alias */
    private ?string $alias = null;

    /**
     * @param TMessage $messageClass
     */
    public function __construct(string $messageClass)
    {
        $this->messageClass = $messageClass;
    }

    /**
     * @param Handler<TResult, TMessage>
     */
    public function setHandler(Handler $handler): self
    {
        $this->handler = $handler;

        return $this;
    }

    public function setIsEvent(bool $value): self
    {
        $this->isEvent = $value;

        return $this;
    }

    public function setAlias(string $alias): self
    {
        $this->alias = $alias;

        return $this;
    }

    /**
     * @param array{0: class-string, 1: string} $handlerFactory
     */
    public function setHandlerFactory(array $handlerFactory): self
    {
        if ($this->isEvent === false && \count($this->handlers) === 1) {
            throw new \LogicException(\sprintf(
                'This `%s` message has multiple handlers. Message class is not `%s`'
                . ' or does not inherit the logic of processing by multiple handlers',
                $this->messageClass,
                Event::class
            ));
        }

        $this->handlers[] = $handlerFactory;

        return $this;
    }

    /**
     * @param class-string<Middleware> ...$middleware
     * @return MessageDefinition
     */
    public function setMiddleware(string ...$middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }

    /** @return array<array{0: class-string, 1: string}> $handlers */
    public function getFactoryHandlers(): array
    {
        return $this->handlers;
    }

    /** @return class-string<Message|object> */
    public function getMessageClass(): string
    {
        return $this->messageClass;
    }

    /** @return array<class-string<Middleware>> */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    public function isEvent(): bool
    {
        return $this->isEvent;
    }

    public function getAlias(): ?string
    {
        return $this->alias;
    }

    /**
     * @return ?Handler<TResult, TMessage>
     */
    public function getHandler(): ?Handler
    {
        return $this->handler;
    }
}
