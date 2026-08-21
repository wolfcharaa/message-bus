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
