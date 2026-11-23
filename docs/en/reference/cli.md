---
doc_id: reference.cli
contract_revision: 4
---

# CLI reference

The optional Symfony Console adapter provides versioned text/JSON commands; binaries receive composition from `MESSAGE_BUS_BOOTSTRAP`.

- `registry:check`, `registry:compile`, and `schema:validate` are build/deploy tooling.

- `delivery:status`, `delivery:cancel`, `delivery:replay`, `delivery:recover`, and `delivery:prune` expose bounded delivery operations. `delivery:prune` is a dry-run unless `--execute` is present.

- `idempotency:prune` plans or deletes only completed records past `retainUntil`; it is also dry-run by default.

- `worker:run`, `worker:status`, `worker:pause`, `worker:resume`, and `worker:prune` are runtime operations. Status accepts `--profile` or an exact `--instance` and exposes convergence/stale/drained; prune deletes only terminal worker history and remains a dry-run without `--execute`.

- A JSON document includes `schema` and `command`; invalid input, conflict/timeout, and schema failure use non-zero exit codes.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
