<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

final class PostgresRetryConfig
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly int $attempts = 3,
        public readonly int $initialDelayMilliseconds = 100,
        public readonly float $multiplier = 2.0,
        public readonly int $maxDelayMilliseconds = 500,
        public readonly bool $jitter = true,
    ) {
        if ($this->attempts < 1) {
            throw new \InvalidArgumentException('PostgreSQL retry attempts must be greater than zero.');
        }

        if ($this->initialDelayMilliseconds < 0 || $this->maxDelayMilliseconds < 0) {
            throw new \InvalidArgumentException('PostgreSQL retry delays must not be negative.');
        }

        if ($this->multiplier < 1.0) {
            throw new \InvalidArgumentException('PostgreSQL retry multiplier must be greater than or equal to 1.0.');
        }
    }

    public static function disabled(): self
    {
        return new self(enabled: false);
    }

    public static function fast(): self
    {
        return new self(attempts: 2, initialDelayMilliseconds: 50, maxDelayMilliseconds: 250);
    }

    public static function default(): self
    {
        return new self();
    }

    public static function patient(): self
    {
        return new self(attempts: 5, initialDelayMilliseconds: 100, maxDelayMilliseconds: 2000);
    }

    public static function fromProfile(PostgresRetryProfile|string $profile): self
    {
        $profile = \is_string($profile) ? PostgresRetryProfile::from($profile) : $profile;

        return match ($profile) {
            PostgresRetryProfile::Fast => self::fast(),
            PostgresRetryProfile::Default => self::default(),
            PostgresRetryProfile::Patient => self::patient(),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        if (isset($config['profile'])) {
            $profile = self::fromProfile((string) $config['profile']);
        } else {
            $profile = self::default();
        }

        return new self(
            enabled: (bool) ($config['enabled'] ?? $profile->enabled),
            attempts: (int) ($config['attempts'] ?? $profile->attempts),
            initialDelayMilliseconds: (int) ($config['initialDelayMilliseconds'] ?? $config['initial_delay_ms'] ?? $profile->initialDelayMilliseconds),
            multiplier: (float) ($config['multiplier'] ?? $profile->multiplier),
            maxDelayMilliseconds: (int) ($config['maxDelayMilliseconds'] ?? $config['max_delay_ms'] ?? $profile->maxDelayMilliseconds),
            jitter: (bool) ($config['jitter'] ?? $profile->jitter),
        );
    }

    public function delayMillisecondsForRetry(int $retryNumber): int
    {
        if ($retryNumber < 1 || $this->initialDelayMilliseconds === 0) {
            return 0;
        }

        $delay = (int) \round($this->initialDelayMilliseconds * ($this->multiplier ** ($retryNumber - 1)));
        $delay = \min($delay, $this->maxDelayMilliseconds);

        if (!$this->jitter || $delay === 0) {
            return $delay;
        }

        return \random_int((int) \floor($delay / 2), $delay);
    }
}
