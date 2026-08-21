<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

use PDO;

final class CallbackPdoConnectionProvider implements PdoConnectionProviderInterface
{
    /** @var callable(): PDO */
    private $factory;
    private ?PDO $connection = null;

    /** @param callable(): PDO $factory */
    public function __construct(callable $factory)
    {
        $this->factory = $factory;
    }

    public function connection(): PDO
    {
        if ($this->connection === null) {
            $connection = ($this->factory)();
            if (!$connection instanceof PDO) {
                throw new \RuntimeException('PDO connection factory must return PDO.');
            }

            $this->connection = $connection;
        }

        return $this->connection;
    }

    public function reset(): void
    {
        $this->connection = null;
    }
}
