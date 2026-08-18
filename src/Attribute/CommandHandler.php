<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Attribute;

use Attribute;
use BackedEnum;
use Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class CommandHandler extends AbstractMessageHandlerAttribute
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
        public readonly ?bool $primary = null,
        public readonly string|BackedEnum|null $bindingId = null,
        public readonly array $middleware = [],
        public readonly ?int $delaySeconds = null,
        public readonly ?string $retryPolicy = null,
    ) {
        parent::__construct($message, $flow, $method, $priority);
    }

    public function toBinding(string $actionClass): HandlerBindingDefinition
    {
        return HandlerBindingDefinition::command(
            $this->message(),
            $actionClass,
            $this->method(),
            $this->flow(),
            $this->primary,
            $this->priority(),
            $this->bindingId,
            $this->middleware,
            $this->delivery(),
        );
    }

    private function delivery(): ?QueueDeliveryOptions
    {
        if ($this->delaySeconds === null && $this->retryPolicy === null && $this->priority === 0) {
            return null;
        }

        return new QueueDeliveryOptions($this->priority !== 0 ? $this->priority : null, $this->delaySeconds, $this->retryPolicy);
    }
}
