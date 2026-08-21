<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

interface PostgresTransientFailureDetectorInterface
{
    public function isTransient(\Throwable $error): bool;

    public function reason(\Throwable $error): ?string;
}
