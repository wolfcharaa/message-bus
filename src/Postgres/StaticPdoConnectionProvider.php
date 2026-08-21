<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

use PDO;

final class StaticPdoConnectionProvider implements PdoConnectionProviderInterface
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function connection(): PDO
    {
        return $this->pdo;
    }

    public function reset(): void
    {
        throw new \LogicException(
            'StaticPdoConnectionProvider cannot reset PDO connection because it wraps an externally owned PDO instance. '
            . 'Pass CallbackPdoConnectionProvider or a custom reconnect-capable PdoConnectionProviderInterface when retry/reconnect is required.',
        );
    }
}
