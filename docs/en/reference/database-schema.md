---
doc_id: reference.database-schema
contract_revision: 3
---

# Database schema

Owned relations use the fixed `message_bus__` prefix inside the configured PostgreSQL schema.

- `messages` stores canonical bytes and metadata; `deliveries` stores per-target state; `delivery_attempts` stores fenced execution history.

- `message_bus__delivery_operations`, `message_bus__worker_desired_states`, `message_bus__worker_instances`, `message_bus__worker_control_operations`, `message_bus__idempotency_records`, and the legacy map have separate ownership.

- SQL is always schema-qualified; application tables keep no permanent FK to operational delivery rows.

- The full catalog gate compares exact columns/default expressions, constraint definitions, critical index columns/predicates, common ownership, and minimum runtime grants; writable v6 compatibility objects block validation.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
