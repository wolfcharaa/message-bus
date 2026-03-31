<?php

declare(strict_types=1);

namespace App\MessageBus\HandlerRegistry;

use App\MessageBus\Message\Message;
use App\MessageBus\Middleware\Middleware;

final class MessageDefinition
{
    /** @var class-string<Message> $messageClass */
    private string $messageClass;

    /** @var array<array{0: class-string, 1: string}> $handlers */
    private array $handlers = [];

    /**
     * @var array<class-string<Middleware>> $middleware
     */
    private array $middleware = [];

    public function __construct(
        string $messageClass
    ) {
        if (!is_a($messageClass, Message::class, true)) {
            throw new \LogicException(sprintf(
                'This class `%s` is not implemented `%s`',
                $messageClass,
                Message::class
            ));
        }

        $this->messageClass = $messageClass;
    }

    /**
     * @param array{0: class-string, 1: string} $handlerFactory
     */
    public function setHandler(array $handlerFactory): self
    {
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
    public function getHandlers(): array
    {
        return $this->handlers;
    }

    /** @return class-string<Message> */
    public function getMessageClass(): string
    {
        return $this->messageClass;
    }

    public function getMiddleware(): array
    {
        return $this->middleware;
    }
}
