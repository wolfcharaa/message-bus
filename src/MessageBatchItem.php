<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

final class MessageBatchItem
{
    public function __construct(
        public readonly object $message,
        public readonly PublishOptions $options = new PublishOptions(),
    ) {
    }
}
