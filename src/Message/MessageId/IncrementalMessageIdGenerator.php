<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Message\MessageId;

final class IncrementalMessageIdGenerator implements MessageIdGenerator
{
    private int $id;

    public function __construct(
        int $id = 1
    ) {
        $this->id = $id;
    }

    public function generateMessageId(): string
    {
        $id = $this->id;
        ++$this->id;

        return (string)$id;
    }
}
