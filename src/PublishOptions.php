<?php

declare(strict_types=1);

namespace App\MessageBus;

final class PublishOptions
{
    /**
     * @var ?non-empty-string $messageId
     */
    public ?string $messageId;

    /**
     * @var array<string, mixed> $headers
     */
    public array $headers;
    public function __construct(
        ?string $messageId = null,
        array $headers = []
    ) {
        $this->messageId = $messageId;
        $this->headers = $headers;
    }
}
