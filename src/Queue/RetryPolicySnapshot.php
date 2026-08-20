<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue;

final class RetryPolicySnapshot
{
    public const DEFAULT_KEY = 'default';

    /** @param array<string, int|float|string|null> $parameters */
    public function __construct(
        public readonly int $maxAttempts = 3,
        public readonly string $strategy = 'exponential',
        public readonly array $parameters = [
            'initialDelaySeconds' => 30,
            'multiplier' => 2.0,
            'maxDelaySeconds' => 300,
        ],
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    public static function fromPolicy(RetryPolicy $policy): self
    {
        $strategy = $policy->delayStrategy;

        if ($strategy instanceof FixedRetryDelayStrategy) {
            return new self(
                $policy->maxAttempts,
                'fixed',
                ['delaySeconds' => $strategy->delaySecondsValue()],
            );
        }

        if ($strategy instanceof ExponentialRetryDelayStrategy) {
            return new self(
                $policy->maxAttempts,
                'exponential',
                [
                    'initialDelaySeconds' => $strategy->initialDelaySeconds(),
                    'multiplier' => $strategy->multiplier(),
                    'maxDelaySeconds' => $strategy->maxDelaySeconds(),
                ],
            );
        }

        return new self($policy->maxAttempts, $strategy::class);
    }

    /** @return array{maxAttempts: int, strategy: string, parameters: array<string, int|float|string|null>} */
    public function toArray(): array
    {
        return [
            'maxAttempts' => $this->maxAttempts,
            'strategy' => $this->strategy,
            'parameters' => $this->parameters,
        ];
    }
}
