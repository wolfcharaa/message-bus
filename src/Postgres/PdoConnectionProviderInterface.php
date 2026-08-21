<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

use PDO;

interface PdoConnectionProviderInterface
{
    public function connection(): PDO;

    /**
     * Drop the current connection so the next connection() call can return a fresh PDO instance.
     *
     * @throws \Throwable When the provider cannot reset/reconnect its connection.
     */
    public function reset(): void;
}
