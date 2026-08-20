<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cache;

final class SerializedResult
{
    public function __construct(
        public readonly string $contentType,
        public readonly string $payload,
        public readonly ?string $className = null,
    ) {
    }
}
