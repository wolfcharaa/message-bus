---
doc_id: reference.database-schema
contract_revision: 3
---

# Database schema

Owned relations используют fixed prefix `message_bus__` в configured PostgreSQL schema.

- `messages` хранит canonical bytes/metadata; `deliveries` — per-target state; `delivery_attempts` — fenced execution history.

- `message_bus__delivery_operations`, `message_bus__worker_desired_states`, `message_bus__worker_instances`, `message_bus__worker_control_operations`, `message_bus__idempotency_records` и legacy map имеют отдельную ownership.

- SQL всегда schema-qualified; application tables не имеют permanent FK на operational delivery rows.

- Full catalog gate сравнивает exact columns/default expressions, constraint definitions, critical index columns/predicates, common ownership и minimum runtime grants; writable v6 compatibility objects блокируют validation.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
