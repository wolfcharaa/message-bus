<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\MessageBus;

final class PublishOptions
{
    /**
     * @param ?non-empty-string $messageId
     * @param array<string, mixed> $headers
     */
    public function __construct(
        public ?string $messageId = null,
        public array $headers = [],
    ) {
    }
}
