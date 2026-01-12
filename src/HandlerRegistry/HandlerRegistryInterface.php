<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\HandlerRegistry;

use Wolfcharaa\MessageBus\Message\Message;
use Wolfcharaa\MessageBus\Handler\Handler;

interface HandlerRegistryInterface
{
    /**
     * @template TResult
     * @template TMessage of Message<TResult>
     * @param class-string<TMessage> $messageClass
     * @return Handler<TResult, TMessage>
     */
    public function get(string $messageClass): Handler;

    /**
     * @template TResult
     * @template TMessage of Message<TResult>
     * @param class-string<TMessage> $messageClass
     * @return ?Handler<TResult, TMessage>
     */
    public function find(string $messageClass): ?Handler;

    /**
     * @param class-string<Message> $messageClass
     * @param Handler $handler
     * @throws HandlerMessageExists
     */
    public function addHandler(string $messageClass, Handler $handler): HandlerRegistryInterface;

    /**
     * @param array<class-string<Message>, Handler> $handlers
     * @throws HandlerMessageExists
     */
    public function addHandlers(array $handlers): HandlerRegistryInterface;
}
