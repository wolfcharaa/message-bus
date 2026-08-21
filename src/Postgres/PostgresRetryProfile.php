<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

enum PostgresRetryProfile: string
{
    case Fast = 'fast';
    case Default = 'default';
    case Patient = 'patient';
}
