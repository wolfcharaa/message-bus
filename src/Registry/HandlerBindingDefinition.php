<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use BackedEnum;
use Wolfcharaa\MessageBus\Cache\CachePolicy;
use Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions;
use Wolfcharaa\MessageBus\Registry\Metadata\RegistryOwner;
use Wolfcharaa\MessageBus\Registry\Metadata\RegistrySource;

final class HandlerBindingDefinition
{
    /**
     * @param class-string $message
     * @param class-string $action
     * @param list<class-string> $middleware
     */
    public function __construct(
        public readonly ?string $bindingId,
        public readonly string $message,
        public readonly string $action,
        public readonly string $method,
        public readonly string $flow,
        public readonly HandlerKind $kind,
        public readonly ?bool $primary,
        public readonly int $priority,
        public readonly array $middleware = [],
        public readonly ?QueueDeliveryOptions $delivery = null,
        public readonly ?CachePolicy $cache = null,
        public readonly HandlerInvocationMode $invocationMode = HandlerInvocationMode::ContextAware,
        public readonly ?RegistryOwner $owner = null,
        public readonly ?RegistrySource $source = null,
    ) {
    }

    public static function command(
        string $message,
        string $action,
        string $method,
        string $flow,
        ?bool $primary,
        int $priority,
        string|BackedEnum|null $bindingId = null,
        array $middleware = [],
        ?QueueDeliveryOptions $delivery = null,
        ?CachePolicy $cache = null,
        HandlerInvocationMode $invocationMode = HandlerInvocationMode::ContextAware,
        ?RegistryOwner $owner = null,
        ?RegistrySource $source = null,
    ): self {
        return new self(
            self::normalize($bindingId),
            $message,
            $action,
            $method,
            $flow,
            HandlerKind::Command,
            $primary,
            $priority,
            $middleware,
            $delivery,
            $cache,
            $invocationMode,
            $owner,
            $source,
        );
    }

    public static function query(
        string $message,
        string $action,
        string $method,
        string $flow,
        int $priority,
        string|BackedEnum|null $bindingId = null,
        array $middleware = [],
        ?QueueDeliveryOptions $delivery = null,
        ?CachePolicy $cache = null,
        HandlerInvocationMode $invocationMode = HandlerInvocationMode::ContextAware,
        ?RegistryOwner $owner = null,
        ?RegistrySource $source = null,
    ): self {
        return new self(
            self::normalize($bindingId),
            $message,
            $action,
            $method,
            $flow,
            HandlerKind::Query,
            true,
            $priority,
            $middleware,
            $delivery,
            $cache,
            $invocationMode,
            $owner,
            $source,
        );
    }

    public static function event(
        string $message,
        string $action,
        string $method,
        string $flow,
        int $priority,
        string|BackedEnum|null $bindingId = null,
        array $middleware = [],
        ?QueueDeliveryOptions $delivery = null,
        ?CachePolicy $cache = null,
        HandlerInvocationMode $invocationMode = HandlerInvocationMode::ContextAware,
        ?RegistryOwner $owner = null,
        ?RegistrySource $source = null,
    ): self {
        return new self(
            self::normalize($bindingId),
            $message,
            $action,
            $method,
            $flow,
            HandlerKind::Event,
            false,
            $priority,
            $middleware,
            $delivery,
            $cache,
            $invocationMode,
            $owner,
            $source,
        );
    }

    public function withCache(?CachePolicy $cache): self
    {
        return new self(
            $this->bindingId,
            $this->message,
            $this->action,
            $this->method,
            $this->flow,
            $this->kind,
            $this->primary,
            $this->priority,
            $this->middleware,
            $this->delivery,
            $cache,
            $this->invocationMode,
            $this->owner,
            $this->source,
        );
    }

    public function withBindingId(string $bindingId): self
    {
        return new self(
            $bindingId,
            $this->message,
            $this->action,
            $this->method,
            $this->flow,
            $this->kind,
            $this->primary,
            $this->priority,
            $this->middleware,
            $this->delivery,
            $this->cache,
            $this->invocationMode,
            $this->owner,
            $this->source,
        );
    }

    public function withPrimary(bool $primary): self
    {
        return new self(
            $this->bindingId,
            $this->message,
            $this->action,
            $this->method,
            $this->flow,
            $this->kind,
            $primary,
            $this->priority,
            $this->middleware,
            $this->delivery,
            $this->cache,
            $this->invocationMode,
            $this->owner,
            $this->source,
        );
    }

    public function withInvocationMode(HandlerInvocationMode $invocationMode): self
    {
        return new self(
            $this->bindingId,
            $this->message,
            $this->action,
            $this->method,
            $this->flow,
            $this->kind,
            $this->primary,
            $this->priority,
            $this->middleware,
            $this->delivery,
            $this->cache,
            $invocationMode,
            $this->owner,
            $this->source,
        );
    }

    public function withRegistrationMetadata(?RegistryOwner $owner, ?RegistrySource $source): self
    {
        return new self(
            $this->bindingId,
            $this->message,
            $this->action,
            $this->method,
            $this->flow,
            $this->kind,
            $this->primary,
            $this->priority,
            $this->middleware,
            $this->delivery,
            $this->cache,
            $this->invocationMode,
            $owner,
            $source,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'bindingId' => $this->bindingId,
            'message' => $this->message,
            'action' => $this->action,
            'method' => $this->method,
            'flow' => $this->flow,
            'kind' => $this->kind->value,
            'primary' => $this->primary,
            'priority' => $this->priority,
            'middleware' => $this->middleware,
            'delivery' => $this->delivery?->toArray(),
            'cache' => $this->cache?->toArray(),
            'invocationMode' => $this->invocationMode->value,
        ];

        if ($this->owner !== null) {
            $data['owner'] = $this->owner->toArray();
        }

        if ($this->source !== null) {
            $data['source'] = $this->source->toArray();
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['bindingId'],
            $data['message'],
            $data['action'],
            $data['method'],
            $data['flow'],
            HandlerKind::from($data['kind']),
            $data['primary'],
            $data['priority'],
            $data['middleware'] ?? [],
            QueueDeliveryOptions::fromArray($data['delivery'] ?? null),
            CachePolicy::fromArray($data['cache'] ?? null),
            HandlerInvocationMode::from($data['invocationMode'] ?? HandlerInvocationMode::ContextAware->value),
            self::ownerFromArray($data),
            self::sourceFromArray($data),
        );
    }

    /** @param array<string, mixed> $data */
    private static function ownerFromArray(array $data): ?RegistryOwner
    {
        if (!\array_key_exists('owner', $data) || $data['owner'] === null) {
            return null;
        }

        if (!\is_array($data['owner'])) {
            throw new \InvalidArgumentException('Binding owner metadata must be an array or null.');
        }

        return RegistryOwner::fromArray($data['owner']);
    }

    /** @param array<string, mixed> $data */
    private static function sourceFromArray(array $data): ?RegistrySource
    {
        if (!\array_key_exists('source', $data) || $data['source'] === null) {
            return null;
        }

        if (!\is_array($data['source'])) {
            throw new \InvalidArgumentException('Binding source metadata must be an array or null.');
        }

        return RegistrySource::fromArray($data['source']);
    }

    private static function normalize(string|BackedEnum|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }
}
