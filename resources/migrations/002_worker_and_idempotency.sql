CREATE TABLE __SCHEMA__.message_bus__worker_desired_states (
    scope text PRIMARY KEY,
    desired_state text NOT NULL CHECK (desired_state IN ('running', 'paused')),
    revision bigint NOT NULL CHECK (revision > 0),
    actor text NOT NULL,
    reason text NOT NULL,
    updated_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

CREATE TABLE __SCHEMA__.message_bus__worker_instances (
    worker_instance_id text PRIMARY KEY,
    profile text NOT NULL,
    lane text NOT NULL,
    host text NOT NULL,
    pid integer NOT NULL,
    build_id text NOT NULL,
    registry_hash char(64) NOT NULL,
    lifecycle text NOT NULL CHECK (lifecycle IN (
        'starting', 'running', 'paused', 'draining', 'stopped', 'failed'
    )),
    capacity integer NOT NULL CHECK (capacity > 0),
    active_attempts integer NOT NULL DEFAULT 0 CHECK (active_attempts >= 0),
    applied_global_revision bigint NOT NULL DEFAULT 0 CHECK (applied_global_revision >= 0),
    applied_profile_revision bigint NOT NULL DEFAULT 0 CHECK (applied_profile_revision >= 0),
    started_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    heartbeat_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    stopped_at timestamptz NULL
);

CREATE INDEX message_bus__worker_instances_profile_idx
    ON __SCHEMA__.message_bus__worker_instances (profile, heartbeat_at, worker_instance_id);

CREATE TABLE __SCHEMA__.message_bus__worker_control_operations (
    operation_id text PRIMARY KEY,
    idempotency_key text NOT NULL UNIQUE,
    scope text NOT NULL,
    previous_state text NOT NULL CHECK (previous_state IN ('running', 'paused')),
    desired_state text NOT NULL CHECK (desired_state IN ('running', 'paused')),
    outcome text NOT NULL CHECK (outcome IN ('applied', 'already_desired', 'revision_conflict')),
    resulting_revision bigint NOT NULL CHECK (resulting_revision >= 0),
    actor text NOT NULL,
    reason text NOT NULL,
    accepted_at timestamptz NOT NULL DEFAULT clock_timestamp()
);

CREATE INDEX message_bus__worker_control_operations_scope_idx
    ON __SCHEMA__.message_bus__worker_control_operations (scope, accepted_at, operation_id);

CREATE TABLE __SCHEMA__.message_bus__idempotency_records (
    target_id text NOT NULL,
    key_digest char(64) NOT NULL,
    fingerprint_algorithm text NOT NULL,
    fingerprint_version integer NOT NULL CHECK (fingerprint_version > 0),
    fingerprint_digest char(64) NOT NULL,
    delivery_id text NOT NULL REFERENCES __SCHEMA__.message_bus__deliveries(delivery_id) ON DELETE CASCADE,
    state text NOT NULL CHECK (state IN ('claimed', 'completed')),
    claimed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    completed_at timestamptz NULL,
    retain_until timestamptz NOT NULL,
    PRIMARY KEY (target_id, key_digest)
);

CREATE INDEX message_bus__idempotency_records_delivery_idx
    ON __SCHEMA__.message_bus__idempotency_records (delivery_id);

CREATE INDEX message_bus__idempotency_records_retention_idx
    ON __SCHEMA__.message_bus__idempotency_records (retain_until, target_id, key_digest)
    WHERE state = 'completed';
