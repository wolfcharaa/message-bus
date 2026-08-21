CREATE TABLE IF NOT EXISTS "message_bus__queue_jobs" (
    id BIGSERIAL PRIMARY KEY,
    transport TEXT NOT NULL,
    queue TEXT NOT NULL,
    status TEXT NOT NULL CHECK (status IN ('pending', 'running', 'succeeded', 'failed', 'cancelled', 'interrupted')),
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

CREATE INDEX IF NOT EXISTS message_bus__queue_jobs_pending_idx
    ON "message_bus__queue_jobs" (transport, queue, status, priority DESC, available_at ASC, id ASC)
    WHERE status = 'pending';

CREATE INDEX IF NOT EXISTS message_bus__queue_jobs_message_id_idx
    ON "message_bus__queue_jobs" (message_id);

CREATE INDEX IF NOT EXISTS message_bus__queue_jobs_correlation_id_idx
    ON "message_bus__queue_jobs" (correlation_id);

CREATE INDEX IF NOT EXISTS message_bus__queue_jobs_binding_id_idx
    ON "message_bus__queue_jobs" (binding_id);

CREATE INDEX IF NOT EXISTS message_bus__queue_jobs_running_heartbeat_idx
    ON "message_bus__queue_jobs" (status, heartbeat_at, locked_at)
    WHERE status = 'running';

CREATE INDEX IF NOT EXISTS message_bus__queue_jobs_interrupted_idx
    ON "message_bus__queue_jobs" (updated_at ASC, id ASC)
    WHERE status = 'interrupted';

CREATE TABLE IF NOT EXISTS "message_bus__schema_versions" (
    component TEXT PRIMARY KEY,
    version TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    checksum TEXT NULL,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO "message_bus__schema_versions" (component, version, description, applied_at, updated_at)
VALUES ('queue', '5.1', 'MessageBus PostgreSQL queue schema.', NOW(), NOW())
ON CONFLICT (component) DO UPDATE
SET version = EXCLUDED.version,
    description = EXCLUDED.description,
    updated_at = NOW();

CREATE TABLE IF NOT EXISTS "message_bus__worker_control_commands" (
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

CREATE INDEX IF NOT EXISTS message_bus__worker_control_commands_created_at_idx
    ON "message_bus__worker_control_commands" (id ASC, created_at ASC);

CREATE INDEX IF NOT EXISTS message_bus__worker_control_commands_expires_at_idx
    ON "message_bus__worker_control_commands" (expires_at)
    WHERE expires_at IS NOT NULL;

CREATE INDEX IF NOT EXISTS message_bus__worker_control_commands_target_instance_idx
    ON "message_bus__worker_control_commands" (target_worker_instance_id)
    WHERE target_worker_instance_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS message_bus__worker_control_commands_target_group_idx
    ON "message_bus__worker_control_commands" (target_worker_group)
    WHERE target_worker_group IS NOT NULL;

CREATE TABLE IF NOT EXISTS "message_bus__worker_desired_state" (
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

CREATE INDEX IF NOT EXISTS message_bus__worker_desired_state_active_idx
    ON "message_bus__worker_desired_state" (active, target_specificity DESC, created_at DESC)
    WHERE active = TRUE;

CREATE INDEX IF NOT EXISTS message_bus__worker_desired_state_target_group_idx
    ON "message_bus__worker_desired_state" (target_worker_group)
    WHERE target_worker_group IS NOT NULL;

CREATE TABLE IF NOT EXISTS "message_bus__worker_instances" (
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

CREATE INDEX IF NOT EXISTS message_bus__worker_instances_heartbeat_idx
    ON "message_bus__worker_instances" (heartbeat_at);

CREATE INDEX IF NOT EXISTS message_bus__worker_instances_state_idx
    ON "message_bus__worker_instances" (state, activity);

CREATE INDEX IF NOT EXISTS message_bus__worker_instances_target_idx
    ON "message_bus__worker_instances" (worker_group, host, transport, queue, mode);

CREATE TABLE IF NOT EXISTS "message_bus__worker_child_instances" (
    child_instance_id TEXT PRIMARY KEY,
    actor_id TEXT NULL UNIQUE,
    actor_type TEXT NOT NULL DEFAULT 'child_worker',
    parent_actor_id TEXT NULL,
    parent_worker_instance_id TEXT NOT NULL REFERENCES "message_bus__worker_instances" (worker_instance_id) ON DELETE CASCADE,
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

CREATE INDEX IF NOT EXISTS message_bus__worker_child_instances_parent_idx
    ON "message_bus__worker_child_instances" (parent_worker_instance_id);

CREATE INDEX IF NOT EXISTS message_bus__worker_child_instances_state_idx
    ON "message_bus__worker_child_instances" (state, heartbeat_at);

CREATE INDEX IF NOT EXISTS message_bus__worker_child_instances_queue_message_idx
    ON "message_bus__worker_child_instances" (queue_message_id)
    WHERE queue_message_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS "message_bus__worker_control_command_deliveries" (
    id BIGSERIAL PRIMARY KEY,
    delivery_id TEXT NOT NULL UNIQUE,
    command_id TEXT NOT NULL REFERENCES "message_bus__worker_control_commands" (command_id) ON DELETE CASCADE,
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

CREATE INDEX IF NOT EXISTS message_bus__worker_control_command_deliveries_command_idx
    ON "message_bus__worker_control_command_deliveries" (command_id, status, updated_at);

CREATE INDEX IF NOT EXISTS message_bus__worker_control_command_deliveries_target_idx
    ON "message_bus__worker_control_command_deliveries" (target_type, target_actor_type, target_actor_id);

CREATE TABLE IF NOT EXISTS "message_bus__worker_control_command_acknowledgements" (
    id BIGSERIAL PRIMARY KEY,
    acknowledgement_id TEXT NULL UNIQUE,
    command_id TEXT NOT NULL REFERENCES "message_bus__worker_control_commands" (command_id) ON DELETE CASCADE,
    delivery_id TEXT NULL,
    worker_instance_id TEXT NOT NULL REFERENCES "message_bus__worker_instances" (worker_instance_id) ON DELETE CASCADE,
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

CREATE UNIQUE INDEX IF NOT EXISTS message_bus__worker_control_command_acknowledgements_actor_stage_uidx
    ON "message_bus__worker_control_command_acknowledgements" (command_id, actor_type, actor_id, stage)
    WHERE actor_type IS NOT NULL AND actor_id IS NOT NULL;

CREATE INDEX IF NOT EXISTS message_bus__worker_control_command_acknowledgements_command_idx
    ON "message_bus__worker_control_command_acknowledgements" (command_id, acknowledged_at);

CREATE INDEX IF NOT EXISTS message_bus__worker_control_command_acknowledgements_worker_idx
    ON "message_bus__worker_control_command_acknowledgements" (worker_instance_id, acknowledged_at);

CREATE TABLE IF NOT EXISTS "message_bus__worker_control_command_audit" (
    id BIGSERIAL PRIMARY KEY,
    audit_id TEXT NULL UNIQUE,
    command_id TEXT NULL REFERENCES "message_bus__worker_control_commands" (command_id) ON DELETE SET NULL,
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

CREATE INDEX IF NOT EXISTS message_bus__worker_control_command_audit_command_idx
    ON "message_bus__worker_control_command_audit" (command_id, created_at);

CREATE INDEX IF NOT EXISTS message_bus__worker_control_command_audit_actor_idx
    ON "message_bus__worker_control_command_audit" (actor_type, actor_id, created_at);

CREATE TABLE IF NOT EXISTS "message_bus__schema_versions" (
    component TEXT PRIMARY KEY,
    version TEXT NOT NULL,
    description TEXT NOT NULL DEFAULT '',
    checksum TEXT NULL,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

INSERT INTO "message_bus__schema_versions" (component, version, description, applied_at, updated_at)
VALUES ('worker_control', '5.1', 'MessageBus PostgreSQL worker control schema.', NOW(), NOW())
ON CONFLICT (component) DO UPDATE
SET version = EXCLUDED.version,
    description = EXCLUDED.description,
    updated_at = NOW();
