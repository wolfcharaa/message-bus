<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Message;

final class IncrementalMessageIdGenerator implements MessageIdGenerator
{
    private int $next = 1;

    public function generate(): string
    {
        return (string) $this->next++;
    }
}
