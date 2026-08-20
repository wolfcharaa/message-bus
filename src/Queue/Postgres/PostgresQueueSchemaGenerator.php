<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Queue\Postgres;

use Wolfcharaa\MessageBus\Queue\QueueTableDefinition;

final class PostgresQueueSchemaGenerator
{
    public function generate(QueueTableDefinition $definition = new QueueTableDefinition()): string
    {
        $table = $this->quoteIdentifier($definition->tableName);

        return <<<SQL
CREATE TABLE IF NOT EXISTS {$table} (
    id BIGSERIAL PRIMARY KEY,
    transport TEXT NOT NULL,
    queue TEXT NOT NULL,
    status TEXT NOT NULL,
    message_name TEXT NOT NULL,
    message_id TEXT NOT NULL,
    correlation_id TEXT NOT NULL,
    flow TEXT NOT NULL,
    binding_id TEXT NOT NULL,
    priority INTEGER NOT NULL DEFAULT 0,
    attempts INTEGER NOT NULL DEFAULT 0,
    max_attempts INTEGER NOT NULL,
    retry_policy_key TEXT NOT NULL,
    retry_policy_snapshot JSONB NOT NULL,
    available_at TIMESTAMPTZ NOT NULL,
    locked_at TIMESTAMPTZ NULL,
    locked_by TEXT NULL,
    heartbeat_at TIMESTAMPTZ NULL,
    started_at TIMESTAMPTZ NULL,
    finished_at TIMESTAMPTZ NULL,
    cancellation_requested BOOLEAN NOT NULL DEFAULT FALSE,
    last_error TEXT NULL,
    last_error_details JSONB NULL,
    serialized_envelope JSONB NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS {$definition->tableName}_pending_idx
    ON {$table} (transport, queue, status, priority DESC, available_at ASC, id ASC)
    WHERE status = 'pending';

CREATE INDEX IF NOT EXISTS {$definition->tableName}_message_id_idx
    ON {$table} (message_id);

CREATE INDEX IF NOT EXISTS {$definition->tableName}_correlation_id_idx
    ON {$table} (correlation_id);

CREATE INDEX IF NOT EXISTS {$definition->tableName}_binding_id_idx
    ON {$table} (binding_id);

CREATE INDEX IF NOT EXISTS {$definition->tableName}_running_heartbeat_idx
    ON {$table} (status, heartbeat_at, locked_at)
    WHERE status = 'running';
SQL;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }
}
