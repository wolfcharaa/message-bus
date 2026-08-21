<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

enum PostgresSchemaComponent: string
{
    case Queue = 'queue';
    case WorkerControl = 'worker_control';
}
