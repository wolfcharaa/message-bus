<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

enum IdempotencyMiddlewareRole: string
{
    case Guard = 'idempotency_guard';
}
