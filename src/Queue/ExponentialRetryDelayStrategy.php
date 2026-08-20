<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class ExponentialRetryDelayStrategy implements RetryDelayStrategy
{
    public function __construct(
        private readonly int $initialDelaySeconds,
        private readonly float $multiplier = 2.0,
        private readonly ?int $maxDelaySeconds = null,
    ) {
    }

    public function delaySeconds(int $attempt): int
    {
        $attempt = \max(1, $attempt);
        $delay = (int) \round($this->initialDelaySeconds * ($this->multiplier ** ($attempt - 1)));

        return $this->maxDelaySeconds === null ? $delay : \min($delay, $this->maxDelaySeconds);
    }

    public function initialDelaySeconds(): int
    {
        return $this->initialDelaySeconds;
    }

    public function multiplier(): float
    {
        return $this->multiplier;
    }

    public function maxDelaySeconds(): ?int
    {
        return $this->maxDelaySeconds;
    }
}
