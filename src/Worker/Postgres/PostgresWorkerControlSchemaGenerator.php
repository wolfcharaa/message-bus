<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker\Postgres;

use Wolfcharaa\MessageBus\Postgres\PostgresSchemaComponent;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaVersion;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaVersionSchemaGenerator;
use Wolfcharaa\MessageBus\Worker\WorkerControlTableDefinition;

final class PostgresWorkerControlSchemaGenerator
{
    public function generate(WorkerControlTableDefinition $definition = new WorkerControlTableDefinition()): string
    {
        $commands = $this->quoteIdentifier($definition->commandsTable);
        $desiredStates = $this->quoteIdentifier($definition->desiredStatesTable);
        $workers = $this->quoteIdentifier($definition->workerInstancesTable);
        $children = $this->quoteIdentifier($definition->childInstancesTable);
        $deliveries = $this->quoteIdentifier($definition->commandDeliveriesTable);
        $acks = $this->quoteIdentifier($definition->acknowledgementsTable);
        $audit = $this->quoteIdentifier($definition->commandAuditTable);

        $sql = <<<SQL
CREATE TABLE IF NOT EXISTS {$commands} (
    id BIGSERIAL PRIMARY KEY,
    command_id TEXT NOT NULL UNIQUE,
    type TEXT NOT NULL,
    target_type TEXT NOT NULL DEFAULT 'legacy',
    target_actor_type TEXT NULL,
    target_actor_id TEXT NULL,
    target_parent_actor_id TEXT NULL,
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
    initiator_actor_id TEXT NULL,
    initiator_actor_type TEXT NULL,
    initiator_display_name TEXT NULL,
    initiator_metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    reason TEXT NULL,
    request_id TEXT NULL,
    correlation_id TEXT NULL,
    deadline_at TIMESTAMPTZ NULL,
    expires_at TIMESTAMPTZ NULL,
    idempotency_key TEXT NULL UNIQUE,
    override BOOLEAN NOT NULL DEFAULT FALSE,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb
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
    scope_type TEXT NULL,
    scope_id TEXT NULL,
    concurrency_override INTEGER NULL CHECK (concurrency_override IS NULL OR concurrency_override >= 0),
    drain_requested_at TIMESTAMPTZ NULL,
    stop_deadline_at TIMESTAMPTZ NULL,
    restart_deadline_at TIMESTAMPTZ NULL,
    runtime_overrides JSONB NOT NULL DEFAULT '{}'::jsonb,
    updated_command_id TEXT NULL,
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
    active BOOLEAN NOT NULL DEFAULT TRUE,
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS {$definition->desiredStatesTable}_active_idx
    ON {$desiredStates} (active, target_specificity DESC, created_at DESC)
    WHERE active = TRUE;

CREATE INDEX IF NOT EXISTS {$definition->desiredStatesTable}_target_group_idx
    ON {$desiredStates} (target_worker_group)
    WHERE target_worker_group IS NOT NULL;

CREATE TABLE IF NOT EXISTS {$workers} (
    worker_instance_id TEXT PRIMARY KEY,
    actor_id TEXT NULL UNIQUE,
    actor_type TEXT NOT NULL DEFAULT 'main_worker',
    parent_actor_id TEXT NULL,
    display_name TEXT NULL,
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
    actor_id TEXT NULL UNIQUE,
    actor_type TEXT NOT NULL DEFAULT 'child_worker',
    parent_actor_id TEXT NULL,
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

CREATE TABLE IF NOT EXISTS {$deliveries} (
    id BIGSERIAL PRIMARY KEY,
    delivery_id TEXT NOT NULL UNIQUE,
    command_id TEXT NOT NULL REFERENCES {$commands} (command_id) ON DELETE CASCADE,
    target_type TEXT NOT NULL,
    target_actor_type TEXT NULL,
    target_actor_id TEXT NULL,
    target_parent_actor_id TEXT NULL,
    target_worker_group TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    seen_at TIMESTAMPTZ NULL,
    applying_at TIMESTAMPTZ NULL,
    applied_at TIMESTAMPTZ NULL,
    skipped_at TIMESTAMPTZ NULL,
    expired_at TIMESTAMPTZ NULL,
    failed_at TIMESTAMPTZ NULL,
    error_message TEXT NULL,
    created_at TIMESTAMPTZ NOT NULL,
    updated_at TIMESTAMPTZ NOT NULL,
    UNIQUE (command_id, target_actor_type, target_actor_id)
);

CREATE INDEX IF NOT EXISTS {$definition->commandDeliveriesTable}_command_idx
    ON {$deliveries} (command_id, status, updated_at);

CREATE INDEX IF NOT EXISTS {$definition->commandDeliveriesTable}_target_idx
    ON {$deliveries} (target_type, target_actor_type, target_actor_id);

CREATE TABLE IF NOT EXISTS {$acks} (
    id BIGSERIAL PRIMARY KEY,
    acknowledgement_id TEXT NULL UNIQUE,
    command_id TEXT NOT NULL REFERENCES {$commands} (command_id) ON DELETE CASCADE,
    delivery_id TEXT NULL,
    worker_instance_id TEXT NOT NULL REFERENCES {$workers} (worker_instance_id) ON DELETE CASCADE,
    actor_type TEXT NULL,
    actor_id TEXT NULL,
    parent_actor_id TEXT NULL,
    stage TEXT NOT NULL DEFAULT 'applied',
    result TEXT NULL,
    state TEXT NOT NULL,
    acknowledged_at TIMESTAMPTZ NOT NULL,
    message TEXT NULL,
    error_class TEXT NULL,
    error_message TEXT NULL,
    metadata JSONB NOT NULL DEFAULT '{}'::jsonb,
    UNIQUE (command_id, worker_instance_id)
);

CREATE UNIQUE INDEX IF NOT EXISTS {$definition->acknowledgementsTable}_actor_stage_uidx
    ON {$acks} (command_id, actor_type, actor_id, stage)
    WHERE actor_type IS NOT NULL AND actor_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS {$definition->acknowledgementsTable}_command_idx
    ON {$acks} (command_id, acknowledged_at);

CREATE INDEX IF NOT EXISTS {$definition->acknowledgementsTable}_worker_idx
    ON {$acks} (worker_instance_id, acknowledged_at);

CREATE TABLE IF NOT EXISTS {$audit} (
    id BIGSERIAL PRIMARY KEY,
    audit_id TEXT NULL UNIQUE,
    command_id TEXT NULL REFERENCES {$commands} (command_id) ON DELETE SET NULL,
    delivery_id TEXT NULL,
    actor_type TEXT NULL,
    actor_id TEXT NULL,
    parent_actor_id TEXT NULL,
    event TEXT NOT NULL,
    level TEXT NOT NULL DEFAULT 'info',
    message TEXT NULL,
    sanitized_context JSONB NOT NULL DEFAULT '{}'::jsonb,
    created_at TIMESTAMPTZ NOT NULL
);

CREATE INDEX IF NOT EXISTS {$definition->commandAuditTable}_command_idx
    ON {$audit} (command_id, created_at);

CREATE INDEX IF NOT EXISTS {$definition->commandAuditTable}_actor_idx
    ON {$audit} (actor_type, actor_id, created_at);
SQL;

        return $sql . "\n\n" . (new PostgresSchemaVersionSchemaGenerator($definition->schemaVersionsTable))->generateComponent(
            PostgresSchemaComponent::WorkerControl,
            PostgresSchemaVersion::WORKER_CONTROL,
            'MessageBus PostgreSQL worker control schema.',
        );
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }
}
