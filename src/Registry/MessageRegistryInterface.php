<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

interface MessageRegistryInterface
{
    /** @return list<HandlerBindingDefinition> */
    public function bindingsForMessage(string $messageClass): array;

    public function binding(string $bindingId): HandlerBindingDefinition;

    public function messageName(string $messageClass): ?string;

    /** @return class-string|null */
    public function messageClassForName(string $messageName): ?string;
}
