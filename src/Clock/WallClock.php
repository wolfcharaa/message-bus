<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

class WallClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
