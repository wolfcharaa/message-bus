<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\HandlerRegistry;

use Wolfcharaa\MessageBus\Message\Event;
use Wolfcharaa\MessageBus\Message\Message;
use Wolfcharaa\MessageBus\Handler\Handler;
use Wolfcharaa\MessageBus\Handler\EventHandlers;

abstract class HandlerRegistry implements HandlerRegistryInterface
{
    /**
     * @template TResult
     * @template TMessage of Message<TResult>
     * @param class-string<TMessage> $messageClass
     * @return Handler<TResult, TMessage>
     */
    final public function get(string $messageClass): Handler
    {
        $handler = $this->find($messageClass);

        if ($handler !== null) {
            return $handler;
        }

        if (is_a($messageClass, Event::class, true)) {
            return new EventHandlers();
        }

        throw new HandlerNotFound($messageClass);
    }

    /**
     * @template TResult
     * @template TMessage of Message<TResult>
     * @param class-string<TMessage> $messageClass
     * @return ?Handler<TResult, TMessage>
     */
    abstract public function find(string $messageClass): ?Handler;
}
