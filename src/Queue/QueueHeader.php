<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class QueueHeader implements \JsonSerializable
{
    public bool $isStarted;

    public function __construct(bool $isStarted = false)
    {
        $this->isStarted = $isStarted;
    }

    public static function started(): self
    {
        return new self(true);
    }

    public function jsonSerialize(): array
    {
        return \get_object_vars($this);
    }
}
