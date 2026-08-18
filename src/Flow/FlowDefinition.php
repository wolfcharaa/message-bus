<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Flow;

use BackedEnum;
use Wolfcharaa\MessageBus\Context\DefaultMessageContextFactory;
use Wolfcharaa\MessageBus\Context\MessageContextInterface;
use Wolfcharaa\MessageBus\Execution\QueueExecutionStrategy;
use Wolfcharaa\MessageBus\Execution\SequentialExecutionStrategy;
use Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions;

final class FlowDefinition
{
    /**
     * @param list<class-string> $middleware
     */
    private function __construct(
        public readonly string $key,
        public readonly FlowMode $mode,
        public readonly string $contextInterface,
        public readonly ?string $contextFactory,
        public readonly string $strategy,
        public readonly array $middleware,
        public readonly ?TransportDefinition $transport,
        public readonly ?QueueDeliveryOptions $delivery,
    ) {
    }

    public static function sync(string|BackedEnum $key): self
    {
        return new self(
            self::normalize($key),
            FlowMode::Sync,
            MessageContextInterface::class,
            DefaultMessageContextFactory::class,
            SequentialExecutionStrategy::class,
            [],
            null,
            null,
        );
    }

    public static function async(string|BackedEnum $key): self
    {
        return new self(
            self::normalize($key),
            FlowMode::Async,
            MessageContextInterface::class,
            DefaultMessageContextFactory::class,
            QueueExecutionStrategy::class,
            [],
            null,
            null,
        );
    }

    public function context(string $interface, ?string $factory = null): self
    {
        return new self(
            $this->key,
            $this->mode,
            $interface,
            $factory ?? ($interface === MessageContextInterface::class ? DefaultMessageContextFactory::class : null),
            $this->strategy,
            $this->middleware,
            $this->transport,
            $this->delivery,
        );
    }

    public function strategy(string $strategy): self
    {
        return new self(
            $this->key,
            $this->mode,
            $this->contextInterface,
            $this->contextFactory,
            $strategy,
            $this->middleware,
            $this->transport,
            $this->delivery,
        );
    }

    public function middleware(string ...$middleware): self
    {
        return new self(
            $this->key,
            $this->mode,
            $this->contextInterface,
            $this->contextFactory,
            $this->strategy,
            $middleware,
            $this->transport,
            $this->delivery,
        );
    }

    public function transport(string|BackedEnum $transport, string|BackedEnum $queue): self
    {
        return new self(
            $this->key,
            $this->mode,
            $this->contextInterface,
            $this->contextFactory,
            $this->strategy,
            $this->middleware,
            TransportDefinition::from($transport, $queue),
            $this->delivery,
        );
    }

    public function delivery(QueueDeliveryOptions $delivery): self
    {
        return new self(
            $this->key,
            $this->mode,
            $this->contextInterface,
            $this->contextFactory,
            $this->strategy,
            $this->middleware,
            $this->transport,
            $delivery,
        );
    }

    public function isSync(): bool
    {
        return $this->mode === FlowMode::Sync;
    }

    public function isAsync(): bool
    {
        return $this->mode === FlowMode::Async;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'mode' => $this->mode->value,
            'contextInterface' => $this->contextInterface,
            'contextFactory' => $this->contextFactory,
            'strategy' => $this->strategy,
            'middleware' => $this->middleware,
            'transport' => $this->transport?->toArray(),
            'delivery' => $this->delivery?->toArray(),
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['key'],
            FlowMode::from($data['mode']),
            $data['contextInterface'],
            $data['contextFactory'],
            $data['strategy'],
            $data['middleware'] ?? [],
            TransportDefinition::fromArray($data['transport'] ?? null),
            QueueDeliveryOptions::fromArray($data['delivery'] ?? null),
        );
    }

    private static function normalize(string|BackedEnum $value): string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }
}
