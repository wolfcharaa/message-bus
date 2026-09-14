<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Attribute;

use BackedEnum;
use Wolfcharaa\MessageBus\Registry\Metadata\RegistryOwner;
use Wolfcharaa\MessageBus\Registry\Metadata\RegistrySource;

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

    protected function registrationOwner(?string $ownerKind, ?string $ownerId): ?RegistryOwner
    {
        if ($ownerKind === null && $ownerId === null) {
            return null;
        }

        if ($ownerKind === null || $ownerId === null) {
            throw new \InvalidArgumentException('Handler binding owner requires both ownerKind and ownerId.');
        }

        return new RegistryOwner($ownerKind, $ownerId);
    }

    protected function registrationSource(
        string $handlerClass,
        ?string $sourceType,
        ?string $sourceName,
        ?string $sourcePackage,
        ?string $sourceLocation,
    ): ?RegistrySource {
        if ($sourceType === null && $sourceName === null && $sourcePackage === null && $sourceLocation === null) {
            return null;
        }

        return new RegistrySource(
            $sourceType ?? 'handler',
            $sourceName ?? $handlerClass,
            providerClass: $handlerClass,
            package: $sourcePackage,
            location: $sourceLocation,
        );
    }
}
