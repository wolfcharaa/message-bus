<?php

declare(strict_types=1);

namespace App\MessageBus\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

class WallClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
