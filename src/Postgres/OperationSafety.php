<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

enum OperationSafety: string
{
    case ReadOnly = 'read_only';
    case Idempotent = 'idempotent';
    case IdempotentWithUniqueKey = 'idempotent_with_unique_key';
    case NonIdempotent = 'non_idempotent';

    public function allowsRetry(?string $idempotencyKey = null): bool
    {
        return match ($this) {
            self::ReadOnly, self::Idempotent => true,
            self::IdempotentWithUniqueKey => $idempotencyKey !== null && $idempotencyKey !== '',
            self::NonIdempotent => false,
        };
    }
}
