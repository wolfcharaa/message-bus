<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use Wolfcharaa\MessageBus\Serialization\MessageNameResolverInterface;

final class CompiledMessageRegistry implements MessageRegistryInterface, MessageNameResolverInterface
{
    public function __construct(private readonly MessageRegistryDefinition $definition)
    {
    }

    public static function fromArray(array $data): self
    {
        return new self(MessageRegistryDefinition::fromArray($data));
    }

    public static function fromFile(string $file): self
    {
        $data = require $file;

        if (!\is_array($data)) {
            throw new RegistryCompilationException('Compiled message registry file must return array.');
        }

        return self::fromArray($data);
    }

    public function definition(): MessageRegistryDefinition
    {
        return $this->definition;
    }

    public function bindingsForMessage(string $messageClass): array
    {
        $bindingIds = $this->definition->messages[$messageClass] ?? [];

        return \array_map(fn (string $bindingId): HandlerBindingDefinition => $this->binding($bindingId), $bindingIds);
    }

    public function binding(string $bindingId): HandlerBindingDefinition
    {
        return $this->definition->bindings[$bindingId] ?? throw new BindingNotFound(\sprintf(
            'Message binding `%s` was not found.',
            $bindingId,
        ));
    }

    public function messageName(string $messageClass): ?string
    {
        return $this->definition->messageNames[$messageClass] ?? null;
    }

    public function messageClassForName(string $messageName): ?string
    {
        return $this->definition->aliases[$messageName] ?? null;
    }

    public function nameOf(object|string $message): string
    {
        $messageClass = \is_object($message) ? $message::class : $message;

        return $this->messageName($messageClass) ?? $messageClass;
    }

    public function classOf(string $name): string
    {
        return $this->messageClassForName($name) ?? (\class_exists($name) ? $name : throw new BindingNotFound(\sprintf(
            'Message class for name `%s` was not found.',
            $name,
        )));
    }
}
