<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker\Postgres;

use Wolfcharaa\MessageBus\Worker\WorkerControlTableDefinition;

final class PostgresWorkerControlSchemaGenerator
{
    public function generate(WorkerControlTableDefinition $definition = new WorkerControlTableDefinition()): string
    {
        $commands = $this->quoteIdentifier($definition->commandsTable);
        $desiredStates = $this->quoteIdentifier($definition->desiredStatesTable);
        $workers = $this->quoteIdentifier($definition->workerInstancesTable);
        $children = $this->quoteIdentifier($definition->childInstancesTable);
        $acks = $this->quoteIdentifier($definition->acknowledgementsTable);

        return <<<SQL
CREATE TABLE IF NOT EXISTS {$commands} (
    id BIGSERIAL PRIMARY KEY,
    command_id TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL,
    target_worker_id TEXT NULL,
    target_worker_name TEXT NULL,
    target_worker_instance_id TEXT NULL,
    target_worker_group TEXT NULL,
    target_transport TEXT NULL,
    target_queue TEXT NULL,
    target_flows JSONB NOT NULL DEFAULT '[]'::jsonb,
    target_binding_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
    target_binding_patterns JSONB NOT NULL DEFAULT '[]'::jsonb,
    target_mode TEXT NULL,
    target_host TEXT NULL,
    target_all BOOLEAN NOT NULL DEFAULT FALSE,
    target_specificity INTEGER NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    created_by TEXT NULL,
    source TEXT NOT NULL,
    reason TEXT NULL,
    request_id TEXT NULL,
    correlation_id TEXT NULL,
    expires_at TIMESTAMPTZ NULL,
    idempotency_key TEXT NULL UNIQUE,
    override BOOLEAN NOT NULL DEFAULT FALSE
);

CREATE INDEX IF NOT EXISTS {$definition->commandsTable}_created_at_idx
    ON {$commands} (id ASC, created_at ASC);

CREATE INDEX IF NOT EXISTS {$definition->commandsTable}_expires_at_idx
    ON {$commands} (expires_at)
    WHERE expires_at IS NOT NULL;

CREATE INDEX IF NOT EXISTS {$definition->commandsTable}_target_instance_idx
    ON {$commands} (target_worker_instance_id)
    WHERE target_worker_instance_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS {$definition->commandsTable}_target_group_idx
    ON {$commands} (target_worker_group)
    WHERE target_worker_group IS NOT NULL;

CREATE TABLE IF NOT EXISTS {$desiredStates} (
    id BIGSERIAL PRIMARY KEY,
    desired_state_id TEXT NOT NULL UNIQUE,
    state TEXT NOT NULL,
    target_worker_id TEXT NULL,
    target_worker_name TEXT NULL,
    target_worker_instance_id TEXT NULL,
    target_worker_group TEXT NULL,
    target_transport TEXT NULL,
    target_queue TEXT NULL,
    target_flows JSONB NOT NULL DEFAULT '[]'::jsonb,
    target_binding_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
    target_binding_patterns JSONB NOT NULL DEFAULT '[]'::jsonb,
    target_mode TEXT NULL,
    target_host TEXT NULL,
    target_all BOOLEAN NOT NULL DEFAULT FALSE,
    target_specificity INTEGER NOT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    created_by TEXT NULL,
    source TEXT NOT NULL,
    reason TEXT NULL,
    request_id TEXT NULL,
    correlation_id TEXT NULL,
    override BOOLEAN NOT NULL DEFAULT FALSE,
    active BOOLEAN NOT NULL DEFAULT TRUE
);

CREATE INDEX IF NOT EXISTS {$definition->desiredStatesTable}_active_idx
    ON {$desiredStates} (active, target_specificity DESC, created_at DESC)
    WHERE active = TRUE;

CREATE INDEX IF NOT EXISTS {$definition->desiredStatesTable}_target_group_idx
    ON {$desiredStates} (target_worker_group)
    WHERE target_worker_group IS NOT NULL;

CREATE TABLE IF NOT EXISTS {$workers} (
    worker_instance_id TEXT PRIMARY KEY,
    worker_id TEXT NULL,
    worker_name TEXT NOT NULL,
    worker_group TEXT NULL,
    host TEXT NOT NULL,
    pid INTEGER NOT NULL,
    started_at TIMESTAMPTZ NOT NULL,
    mode TEXT NOT NULL,
    transport TEXT NOT NULL,
    queue TEXT NOT NULL,
    flows JSONB NOT NULL DEFAULT '[]'::jsonb,
    binding_ids JSONB NOT NULL DEFAULT '[]'::jsonb,
    binding_patterns JSONB NOT NULL DEFAULT '[]'::jsonb,
    state TEXT NOT NULL,
    activity TEXT NOT NULL,
    heartbeat_at TIMESTAMPTZ NOT NULL,
    children_count INTEGER NOT NULL DEFAULT 0,
    last_command_id TEXT NULL,
    last_command_at TIMESTAMPTZ NULL,
    stopped_at TIMESTAMPTZ NULL,
    failure_message TEXT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS {$definition->workerInstancesTable}_heartbeat_idx
    ON {$workers} (heartbeat_at);

CREATE INDEX IF NOT EXISTS {$definition->workerInstancesTable}_state_idx
    ON {$workers} (state, activity);

CREATE INDEX IF NOT EXISTS {$definition->workerInstancesTable}_target_idx
    ON {$workers} (worker_group, host, transport, queue, mode);

CREATE TABLE IF NOT EXISTS {$children} (
    child_instance_id TEXT PRIMARY KEY,
    parent_worker_instance_id TEXT NOT NULL REFERENCES {$workers} (worker_instance_id) ON DELETE CASCADE,
    pid INTEGER NOT NULL,
    state TEXT NOT NULL,
    started_at TIMESTAMPTZ NOT NULL,
    heartbeat_at TIMESTAMPTZ NOT NULL,
    queue_message_id TEXT NULL,
    message_id TEXT NULL,
    correlation_id TEXT NULL,
    binding_id TEXT NULL,
    finished_at TIMESTAMPTZ NULL,
    failure_message TEXT NULL,
    updated_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS {$definition->childInstancesTable}_parent_idx
    ON {$children} (parent_worker_instance_id);

CREATE INDEX IF NOT EXISTS {$definition->childInstancesTable}_state_idx
    ON {$children} (state, heartbeat_at);

CREATE INDEX IF NOT EXISTS {$definition->childInstancesTable}_queue_message_idx
    ON {$children} (queue_message_id)
    WHERE queue_message_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS {$acks} (
    id BIGSERIAL PRIMARY KEY,
    command_id TEXT NOT NULL REFERENCES {$commands} (command_id) ON DELETE CASCADE,
    worker_instance_id TEXT NOT NULL REFERENCES {$workers} (worker_instance_id) ON DELETE CASCADE,
    state TEXT NOT NULL,
    acknowledged_at TIMESTAMPTZ NOT NULL,
    message TEXT NULL,
    error_class TEXT NULL,
    error_message TEXT NULL,
    UNIQUE (command_id, worker_instance_id)
);

CREATE INDEX IF NOT EXISTS {$definition->acknowledgementsTable}_command_idx
    ON {$acks} (command_id, acknowledged_at);

CREATE INDEX IF NOT EXISTS {$definition->acknowledgementsTable}_worker_idx
    ON {$acks} (worker_instance_id, acknowledged_at);
SQL;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }
}
