<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

final class PostgresSchemaValidationIssue
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly ?PostgresSchemaComponent $component = null,
    ) {
    }
}
