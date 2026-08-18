<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Attribute;

use BackedEnum;

abstract class AbstractMessageHandlerAttribute implements MessageHandlerAttributeInterface
{
    /**
     * @param class-string $message
     */
    public function __construct(
        public readonly string $message,
        public readonly string|BackedEnum $flow = 'default',
        public readonly string $method = '__invoke',
        public readonly int $priority = 0,
    ) {
    }

    public function message(): string
    {
        return $this->message;
    }

    public function flow(): string
    {
        return $this->flow instanceof BackedEnum ? (string) $this->flow->value : $this->flow;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function priority(): int
    {
        return $this->priority;
    }
}
