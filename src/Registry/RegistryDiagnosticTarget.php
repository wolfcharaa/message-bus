<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

final readonly class RegistryDiagnosticTarget
{
    public function __construct(
        public ?string $bindingId = null,
        public ?string $messageName = null,
        public ?string $messageClass = null,
        public ?string $handlerClass = null,
        public ?string $method = null,
        public ?string $flow = null,
        public ?string $middlewareClass = null,
        public ?string $alias = null,
    ) {
    }

    public static function fromBinding(HandlerBindingDefinition $binding): self
    {
        return new self(
            bindingId: $binding->bindingId,
            messageClass: $binding->message,
            handlerClass: $binding->action,
            method: $binding->method,
            flow: $binding->flow,
        );
    }
}
