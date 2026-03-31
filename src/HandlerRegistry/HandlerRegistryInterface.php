<?php

declare(strict_types=1);

namespace App\MessageBus\HandlerRegistry;

use App\MessageBus\Message\Message;
use App\MessageBus\Handler\Handler;

interface HandlerRegistryInterface
{
    /**
     * @template TResult
     * @template TMessage of Message<TResult>|object
     * @param class-string<TMessage> $messageClass
     * @return Handler<TResult, TMessage>
     */
    public function get(string $messageClass): Handler;

    /**
     * @template TResult
     * @template TMessage of Message<TResult>|object
     * @param class-string<TMessage> $messageClass
     * @return MessageDefinition
     */
    public function find(string $messageClass): MessageDefinition;
}
