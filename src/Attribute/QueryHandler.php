<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Attribute;

use Attribute;
use BackedEnum;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class QueryHandler extends AbstractMessageHandlerAttribute
{
    /**
     * @param class-string $message
     * @param list<class-string> $middleware
     */
    public function __construct(
        string $message,
        string|BackedEnum $flow = 'default',
        string $method = '__invoke',
        int $priority = 0,
        public readonly string|BackedEnum|null $bindingId = null,
        public readonly array $middleware = [],
    ) {
        parent::__construct($message, $flow, $method, $priority);
    }

    public function toBinding(string $actionClass): HandlerBindingDefinition
    {
        return HandlerBindingDefinition::query(
            $this->message(),
            $actionClass,
            $this->method(),
            $this->flow(),
            $this->priority(),
            $this->bindingId,
            $this->middleware,
        );
    }
}
