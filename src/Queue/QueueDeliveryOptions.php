<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class QueueDeliveryOptions
{
    public function __construct(
        public readonly ?int $priority = null,
        public readonly ?int $delaySeconds = null,
        public readonly ?string $retryPolicy = null,
    ) {
    }

    public function merge(?self $override): self
    {
        if ($override === null) {
            return $this;
        }

        return new self(
            $override->priority ?? $this->priority,
            $override->delaySeconds ?? $this->delaySeconds,
            $override->retryPolicy ?? $this->retryPolicy,
        );
    }

    /** @return array{priority: ?int, delaySeconds: ?int, retryPolicy: ?string} */
    public function toArray(): array
    {
        return [
            'priority' => $this->priority,
            'delaySeconds' => $this->delaySeconds,
            'retryPolicy' => $this->retryPolicy,
        ];
    }

    /** @param array{priority?: ?int, delaySeconds?: ?int, retryPolicy?: ?string}|null $data */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        return new self(
            $data['priority'] ?? null,
            $data['delaySeconds'] ?? null,
            $data['retryPolicy'] ?? null,
        );
    }
}
