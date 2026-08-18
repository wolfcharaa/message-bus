<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Message;

final class RandomMessageIdGenerator implements MessageIdGenerator
{
    public function generate(): string
    {
        return \bin2hex(\random_bytes(16));
    }
}
