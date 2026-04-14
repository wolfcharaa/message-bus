<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\HandlerRegistry;

use Wolfcharaa\MessageBus\Message\Message;
use Wolfcharaa\MessageBus\Handler\Handler;
use Wolfcharaa\MessageBus\Handler\EventHandlers;

abstract class HandlerRegistry implements HandlerRegistryInterface
{
    /**
     * @template TResult
     * @template TMessage of Message<TResult>|object
     * @param class-string<TMessage> $messageClass
     * @return Handler<TResult, TMessage>
     */
    final public function get(string $messageClass): Handler
    {
        $definition = $this->find($messageClass);

        if ($definition->getHandler() !== null) {
            return $definition->getHandler();
        }

        if ($definition->isEvent() === false) {
            throw new HandlerNotFound($definition->getMessageClass());
        }

        return $definition->setHandler(new EventHandlers())->getHandler();
    }

    /**
     * @template TResult
     * @template TMessage of Message<TResult>|object
     * @param class-string<TMessage> $messageClass
     * @return MessageDefinition<TResult, TMessage>
     */
    abstract public function find(string $messageClass): MessageDefinition;
}
