<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use BackedEnum;
use Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions;

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
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
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
        ];
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
        );
    }

    private static function normalize(string|BackedEnum|null $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }
}
