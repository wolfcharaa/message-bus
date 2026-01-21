<?php

declare(strict_types=1);

namespace App\MessageBus\Message\MessageId;

final class RandomMessageIdGenerator implements MessageIdGenerator
{
    /**
     * @var positive-int $bytes
     */
    private int $bytes;

    public function __construct(
        int $bytes = 16
    ) {
        $this->bytes = $bytes;
    }

    public function generateMessageId(): string
    {
        return bin2hex(random_bytes($this->bytes));
    }
}
