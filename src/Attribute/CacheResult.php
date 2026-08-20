<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Attribute;

use Attribute;
use Wolfcharaa\MessageBus\Cache\CachePolicy;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final class CacheResult
{
    /**
     * @param list<string> $varyHeaders
     */
    public function __construct(
        public readonly ?int $ttlSeconds = null,
        public readonly ?string $identityKey = null,
        public readonly array $varyHeaders = [],
        public readonly bool $enabled = true,
        public readonly ?string $bindingId = null,
    ) {
    }

    public function toPolicy(): CachePolicy
    {
        return new CachePolicy($this->ttlSeconds, $this->identityKey, $this->varyHeaders, $this->enabled);
    }
}
