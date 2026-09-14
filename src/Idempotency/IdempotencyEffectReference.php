<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

final readonly class IdempotencyEffectReference
{
    public function __construct(
        public string $type,
        public string $id,
        public ?string $version = null,
    ) {
        if (\trim($this->type) === '' || \trim($this->id) === '') {
            throw new \InvalidArgumentException('Idempotency effect reference type and id must be non-empty.');
        }
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return \array_filter([
            'type' => $this->type,
            'id' => $this->id,
            'version' => $this->version,
        ], static fn (?string $value): bool => $value !== null);
    }
}
