<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cache;

final class CachePolicy
{
    /**
     * @param list<string> $varyHeaders
     */
    public function __construct(
        public readonly ?int $ttlSeconds = null,
        public readonly ?string $identityKey = null,
        public readonly array $varyHeaders = [],
        public readonly bool $enabled = true,
    ) {
    }

    /** @return array{ttlSeconds: ?int, identityKey: ?string, varyHeaders: list<string>, enabled: bool} */
    public function toArray(): array
    {
        return [
            'ttlSeconds' => $this->ttlSeconds,
            'identityKey' => $this->identityKey,
            'varyHeaders' => $this->varyHeaders,
            'enabled' => $this->enabled,
        ];
    }

    /** @param array{ttlSeconds?: ?int, identityKey?: ?string, varyHeaders?: list<string>, enabled?: bool}|null $data */
    public static function fromArray(?array $data): ?self
    {
        if ($data === null) {
            return null;
        }

        return new self(
            $data['ttlSeconds'] ?? null,
            $data['identityKey'] ?? null,
            $data['varyHeaders'] ?? [],
            $data['enabled'] ?? true,
        );
    }
}
