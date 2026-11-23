---
name: integrate-message-bus
description: Integrate romanfedorskij/message-bus 7.x into a PHP 8.3+ application after inspecting its message roles, handler composition, transaction boundary, PostgreSQL ownership, codecs, worker model, and operations. Use for orchestration-only or durable PostgreSQL wiring, registry artifacts, migration planning, and integration verification. Do not use for developing the package itself.
---

# Integrate MessageBus

Work in the consumer repository. Follow its instructions, use the public API,
docs and examples installed with the exact package version, and never edit
`vendor/` or import `Wolfcharaa\MessageBus\Internal`.

## Establish the integration contract first

Before changing Composer, configuration, migrations or source, inspect:

- PHP/package versions, framework/container and existing bootstrap;
- current Query/Request/Command/Event objects, handlers and result semantics;
- whether the host needs only synchronous orchestration or durable delivery;
- application transaction/outbox boundary, PDO ownership and migration flow;
- durable lanes, retry/deadline/priority, cancellation/replay and idempotency;
- portable metadata, payload versions/upcasters and custom context data;
- worker process/deployment ownership and existing logging/metrics conventions;
- tests and operational success criteria.

Produce a short Integration Contract and configuration map:

```text
application requirement
→ public MessageBus capability
→ application-owned code/configuration
→ verification
```

Resolve ambiguity about message role from handler effect and result contract,
not from class names. Do not add PostgreSQL, PCNTL, custom contexts, upcasters,
idempotency or observers for hypothetical future use.

## Choose the smallest profile

For orchestration-only, start from
`examples/orchestration/run.php` and `docs/en/getting-started/orchestration.md`.
Compose the bounded registry source/compiler, exact handler service map, routes,
and only required context factories or middleware. Build the registry artifact
during release assembly; production startup loads and verifies it.

For durable PostgreSQL, additionally read
`docs/en/getting-started/durable-postgres.md` and the database/worker operations
references. Apply packaged immutable SQL through the host migration system.
Use public PostgreSQL backend/schema/control roots and a child-runtime factory
that creates fresh process-local connections after fork. Keep credentials,
deployment supervision, business transactions and durable business history in
the host application.

## Preserve role and wire contracts

Every message implements exactly one role. Query and Request have one immediate
result handler. Command has one durable target and returns acceptance, not the
handler result. Event has stable subscription IDs and returns aggregate
publication evidence.

Durable Command/Event classes have stable `MessageName`, payload version and
registered codecs. Inventory retained payload versions before changing a codec
or removing an upcaster. Use `php-native` only with the generated allowlist
graph and an explicit compatibility reason.

Metadata is schema-bounded scalar/null data. Never persist secrets, containers,
PDO objects or arbitrary headers. Application effects requiring retry safety
need an atomic idempotency boundary in the same transaction as the effect;
ordinary retry is not exactly-once.

## Verify before mutation

Compile and validate the registry before execution. Run the closest packaged
example, consumer static/unit tests, then PostgreSQL/process tests for every
durable integration. Verify Command acceptance, worker settlement/status,
Event partial failure, cancellation/replay races and clean shutdown as relevant.

For v6 migration, create a symbol/behavior mapping and use the documented
offline sequence: quiesce producers, drain workers, prove zero running rows,
plan/dry-run import, apply immutable v7 schema, import and backfill application
references, then reconcile counts and behavior. Never introduce v6/v7
dual-write.

Do not apply DDL/data migration, start or stop production workers, pause/resume,
cancel, replay, recover or prune without explicit authority for that mutation
and environment. Before pruning, preserve application-owned business history.
After the first v7 acceptance, treat recovery as forward-only.

Report the selected profile, Integration Contract, public capabilities used,
host/package ownership, files changed, checks and runtime evidence, unresolved
compatibility risks, and every mutation that was or was not performed.
