<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

final class PublishOptions
{
    /**
     * @var ?non-empty-string $messageId
     */
    public ?string $messageId;

    /**
     * @var Header $header
     */
    public Header $header;

    public function __construct(
        ?string $messageId = null,
        ?Header $header = null
    ) {
        $this->messageId = $messageId;
        $this->header = $header ?? new Header();
    }
}
