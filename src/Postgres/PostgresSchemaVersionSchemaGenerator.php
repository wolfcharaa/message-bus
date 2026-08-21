<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Postgres;

final class PostgresSchemaVersionSchemaGenerator
{
    private readonly PostgresSchemaVersionTableDefinition $definition;

    public function __construct(string|PostgresSchemaVersionTableDefinition|null $definition = null)
    {
        $this->definition = match (true) {
            $definition instanceof PostgresSchemaVersionTableDefinition => $definition,
            \is_string($definition) => new PostgresSchemaVersionTableDefinition($definition),
            default => new PostgresSchemaVersionTableDefinition(),
        };
    }

    public function generateTable(): string
    {
        $table = $this->quoteIdentifier($this->definition->tableName);

        return <<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
    component TEXT PRIMARY KEY,
    version TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    checksum TEXT NULL,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
SQL;
    }

    public function generateComponent(PostgresSchemaComponent $component, string $version, string $description = ''): string
    {
        $table = $this->quoteIdentifier($this->definition->tableName);

        return $this->generateTable() . "\n\n" . <<<SQL
INSERT INTO {$table} (component, version, description, applied_at, updated_at)
VALUES ({$this->quoteLiteral($component->value)}, {$this->quoteLiteral($version)}, {$this->quoteLiteral($description)}, NOW(), NOW())
ON CONFLICT (component) DO UPDATE
SET version = EXCLUDED.version,
    description = EXCLUDED.description,
    updated_at = NOW();
SQL;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }

    private function quoteLiteral(string $value): string
    {
        return "'" . \str_replace("'", "''", $value) . "'";
    }
}
