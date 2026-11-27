---
doc_id: reference.cli
contract_revision: 6
---

# CLI reference

The optional Symfony Console adapter provides versioned text/JSON commands; binaries receive composition from `MESSAGE_BUS_BOOTSTRAP`.

- `registry:check`, `registry:compile`, and `schema:validate` are build/deploy tooling.

- `delivery:status`, `delivery:cancel`, `delivery:replay`, `delivery:recover`, and `delivery:prune` expose bounded delivery operations. `delivery:prune` is a dry-run unless `--execute` is present.

- `idempotency:prune` plans or deletes only completed records past `retainUntil`; it is also dry-run by default.

- `worker:run`, `worker:status`, `worker:pause`, `worker:resume`, and `worker:prune` are runtime operations. Status accepts `--profile` or an exact `--instance` and exposes convergence/stale/drained; prune deletes only terminal worker history and remains a dry-run without `--execute`.

- Every JSON document uses output `schema: 2` and includes `command`. A caught command failure uses one stable `error` object with `code`, `type`, `stage`, safe `message`, `exception_type`, and optional `{type,id}` subject. Raw exception messages are not emitted.

- Error codes are `MBCLI001` invalid input, `MBCLI002` retained-state conflict, `MBCLI003` backend failure, and `MBCLI004` unexpected internal failure. The safe message repeats the code, `stage`, subject, and exception type without copying the arbitrary exception text. Conditions and recovery steps are in the [diagnostics reference](diagnostics.md); non-zero exit codes remain command-specific.

- `delivery:status` exposes `last_failure_category`, `last_failure_attempt_id`, and the redacted `last_failure` descriptor when canonical diagnostics were retained.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
