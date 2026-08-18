<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class RetryPolicy
{
    public function __construct(
        public readonly int $maxAttempts,
        public readonly RetryDelayStrategy $delayStrategy,
    ) {
    }

    public static function fixed(int $maxAttempts, int $delaySeconds): self
    {
        return new self($maxAttempts, new FixedRetryDelayStrategy($delaySeconds));
    }

    public static function exponential(
        int $maxAttempts,
        int $initialDelaySeconds,
        float $multiplier = 2.0,
        ?int $maxDelaySeconds = null,
    ): self {
        return new self(
            $maxAttempts,
            new ExponentialRetryDelayStrategy($initialDelaySeconds, $multiplier, $maxDelaySeconds),
        );
    }
}
