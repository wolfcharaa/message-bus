<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Serialization;

final class SerializedMessage
{
    public function __construct(
        public readonly string $name,
        public readonly string $contentType,
        public readonly string $payload,
        /** @var array<string, mixed> */
        public readonly array $headers = [],
    ) {
    }
}
