<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

final class PostgresSchemaVersion
{
    public const QUEUE = '5.1';
    public const WORKER_CONTROL = '5.1';

    private function __construct()
    {
    }
}
