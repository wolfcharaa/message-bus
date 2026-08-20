<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

use Wolfcharaa\MessageBus\Envelope\Headers;
use Wolfcharaa\MessageBus\Queue\QueueDeliveryOptions;

final class PublishOptions
{
    public function __construct(
        public readonly ?string $messageId = null,
        public readonly Headers $headers = new Headers(),
        public readonly ?QueueDeliveryOptions $delivery = null,
    ) {
    }

    public function merge(self $override): self
    {
        $delivery = $this->delivery;
        if ($delivery !== null) {
            $delivery = $delivery->merge($override->delivery);
        } else {
            $delivery = $override->delivery;
        }

        return new self(
            $override->messageId ?? $this->messageId,
            $this->headers->merge($override->headers),
            $delivery,
        );
    }
}
