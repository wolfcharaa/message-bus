<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Handler;

use Wolfcharaa\MessageBus\Message\Context;
use Wolfcharaa\MessageBus\Message\Event;

/**
 * @template TEvent of Event
 * @implements Handler<null, TEvent>
 */
final class EventHandlers implements Handler
{
    /**
     * @var array<Handler> $handlers
     */
    private iterable $handlers;
    public function __construct(
        array $handlers = []
    ) {
        $this->handlers = $handlers;
    }

    public function withHandler(Handler ...$handler): self
    {
        $clone = clone $this;
        $clone->handlers = [...$clone->handlers, ...$handler];

        return $clone;
    }

    public function handle(Context $context)
    {
        foreach ($this->handlers as $handler) {
            $handler->handle($context);
        }

        return null;
    }
}
