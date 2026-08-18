<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Attribute;

use Wolfcharaa\MessageBus\Registry\HandlerBindingDefinition;

interface MessageHandlerAttributeInterface
{
    public function message(): string;

    public function flow(): string;

    public function method(): string;

    public function priority(): int;

    public function toBinding(string $actionClass): HandlerBindingDefinition;
}
