<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

final class PostgresSchemaVersionTableDefinition
{
    public function __construct(
        public readonly string $tableName = 'message_bus__schema_versions',
    ) {
    }
}
