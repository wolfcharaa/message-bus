<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\HandlerRegistry;

use Wolfcharaa\MessageBus\Handler\Handler;
use Wolfcharaa\MessageBus\Handler\EventHandlers;
use Wolfcharaa\MessageBus\Message\Event;
use Wolfcharaa\MessageBus\Message\Message;

final class ArrayHandlerRegistry extends HandlerRegistry
{
    /**
     * @param array<class-string<Message>, Handler> $handlersByMessageClass
     */
    public function __construct(
        private array $handlersByMessageClass = [],
    ) {
    }

    /**
     * @template TResult
     * @template TMessage of Message<TResult>
     * @param class-string<TMessage> $messageClass
     * @return ?Handler<TResult, TMessage>
     */
    public function find(string $messageClass): ?Handler
    {
        return $this->handlersByMessageClass[$messageClass] ?? null;
    }

    public function addHandler(string $messageClass, Handler $handler): HandlerRegistryInterface
    {
        if (is_a($messageClass, Event::class, true)) {
            $handler = ($this->handlers[$messageClass] ?? new EventHandlers())
                ->withHandler($handler);
        } elseif (isset($this->handlers[$messageClass])) {
            throw new HandlerMessageExists($messageClass);
        }

        $this->handlersByMessageClass[$messageClass] = $handler;

        return $this;
    }

    public function addHandlers(array $handlers): HandlerRegistryInterface
    {
        foreach ($handlers as $messageClass => $handler) {
            $this->addHandler($messageClass, $handler);
        }

        return $this;
    }
}
