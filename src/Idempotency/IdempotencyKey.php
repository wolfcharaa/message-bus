<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use InvalidArgumentException;

final readonly class IdempotencyKey
{
    public string $value;

    public string $digest;

    public function __construct(string $value)
    {
        $value = \trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Idempotency key must be non-empty.');
        }

        $this->value = $value;
        $this->digest = \hash('sha256', $value);
    }
}
