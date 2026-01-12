<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Message\MessageId;

final class IncrementalMessageIdGenerator implements MessageIdGenerator
{
    public function __construct(
        private int $id = 1,
    ) {
    }

    public function generateMessageId(): string
    {
        $id = $this->id;
        ++$this->id;

        return (string)$id;
    }
}
