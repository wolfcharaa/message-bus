<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Message;

interface MessageIdGenerator
{
    public function generate(): string;
}
