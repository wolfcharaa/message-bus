<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Message\MessageId;

interface MessageIdGenerator
{
    /**
     * @return non-empty-string
     */
    public function generateMessageId(): string;
}
