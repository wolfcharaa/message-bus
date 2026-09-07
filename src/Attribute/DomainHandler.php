<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Attribute;

use Attribute;
use BackedEnum;
use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;
use Wolfcharaa\MessageBus\Registry\HandlerInvocationMode;
use Wolfcharaa\MessageBus\Registry\HandlerRole;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class DomainHandler extends QueryHandler
{
    /**
     * @param class-string $message
     * @param list<class-string> $middleware
     */
    public function __construct(
        string $message,
        string|BackedEnum $flow = 'domain_capability',
        string $method = '__invoke',
        int $priority = 0,
        string|BackedEnum|null $bindingId = null,
        array $middleware = [],
    ) {
        parent::__construct($message, $flow, $method, $priority, $bindingId, $middleware);
    }

    public function toBinding(string $actionClass): HandlerBindingDefinition
    {
        // TODO(next-major): split DomainHandler binding construction from QueryHandler inheritance once projects rely on domain metadata directly.
        return parent::toBinding($actionClass)
            ->withRole(HandlerRole::Domain)
            ->withInvocationMode(HandlerInvocationMode::Contextless);
    }
}
