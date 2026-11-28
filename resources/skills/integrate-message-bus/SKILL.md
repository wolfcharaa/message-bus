---
name: integrate-message-bus
description: Integrate and troubleshoot romanfedorskij/message-bus 7.x in a PHP 8.3+ application after inspecting message roles, handlers, Composer discovery, DI, transaction boundaries, PostgreSQL ownership, codecs, workers, operations, and stable diagnostic codes. Use for orchestration-only or durable PostgreSQL wiring, registry artifacts and development refreshes, migration planning, integration verification, and MBR/MBC/MBD/MBCLI failure triage. Do not use for developing the package itself.
---

# Integrate MessageBus

Work in the consumer repository. Follow its instructions, use the public API,
docs and examples installed with the exact package version, and never edit
`vendor/` or import `Wolfcharaa\MessageBus\Internal`.

If this skill was expected but is not available, run
`composer skills:show romanfedorskij/message-bus` from the consumer repository.
An installed copy must report `integrate-message-bus` as `OK`; synchronize it
with `composer skills:update romanfedorskij/message-bus` rather than editing the
copy under `vendor/`.

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

When the host exposes PSR-11, use `PsrContainerHandlerServiceResolver` instead
of copying container services into an application map. Do not catch or replace
the container's `get()`/`has()` failures; the adapter deliberately preserves
their exact type and chain. Use `HandlerServices` only for a small fixed object
map or tests.

Choose the registry source explicitly:

- use `ExplicitClassSource` when the application already owns a small complete
  class list;
- for a fast bounded scan, read
  `docs/en/getting-started/composer-directory-discovery.md`, install
  `composer/class-map-generator:^1.8` as a build/development dependency, and use
  `ComposerDirectoryClassSource::fromComposerJson()` with the host's explicit
  root composer.json path;
- do not turn `autoload-dev`, `files`, vendor trees, or cwd inference into
  discovery roots.

Compile the artifact before a runtime `composer install --no-dev`. Use
`RegistryRefreshPolicy::WhenStale` only at development bootstrap with a
refreshable source. Use `PrecompiledOnly` in production with no source. Do not
add a dispatch-time scan, a worker file watcher, or a stale-artifact fallback;
restart long-running workers through the host's normal mechanism.

For durable PostgreSQL, additionally read
`docs/en/getting-started/durable-postgres.md` and the database/worker operations
references. Apply packaged immutable SQL through the host migration system.
Prefer `PostgresRuntime::create()` for package-owned backend/bus/worker/schema/
control composition. A child-runtime factory still creates a fresh host-owned
PDO and a fresh `PostgresRuntime` after fork. Keep credentials, connection
lifetime, deployment supervision, business transactions and durable business
history in the host application.

For a host that already owns one Symfony Console `Application`, register only
the needed command groups through `Console::registerRegistry()`,
`registerSchema()`, `registerDelivery()`, `registerIdempotency()`, and
`registerWorker()`. Do not create a second application or import internal
command classes.

## Preserve role and wire contracts

Every message implements exactly one role. Query and Request have one immediate
result handler. Command has one durable target and returns acceptance, not the
handler result. Prefer `#[Handler(bindingId: 'command.name')]` when its target
must remain stable across PHP class renames; omitting it retains the class-name
target for compatibility. Event identity remains `subscriptionId`; never put
`bindingId` on Query, Request, or Event. Event returns aggregate publication
evidence.

Durable Command/Event classes have stable `MessageName`, payload version and
registered codecs. Use `MessageCodecs::fromRegistry()` for standard JSON and
bounded PHP-native payloads; construct `MessageCodecs` explicitly for a custom
codec or historical upcasters. Inventory retained payload versions before
changing a codec or removing an upcaster. Use `php-native` only with the
generated allowlist graph and an explicit compatibility reason.

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

## Diagnose by stable code before changing the integration

Use the diagnostics reference installed with the exact package release:
`vendor/romanfedorskij/message-bus/docs/en/reference/diagnostics.md` or its
`docs/ru/` pair. Preserve the original throwable and its `previous` chain; the
safe descriptor is an index for correlation, not a replacement for the real
DI, handler, codec, classifier, or backend exception.

Route the investigation by prefix:

- `MBR` — read `symbol`, `source_path`, `expected`, `actual`, and `cause_class`,
  then inspect `InvalidMessageBusConfiguration::getPrevious()` when present;
  correct the message/handler declaration or Composer discovery and rebuild the
  registry artifact;
- `MBC` — correct the exact target route, policy, idempotency runtime, or
  resolver before starting the runtime;
- `MBD` — correlate `stage`, delivery/message/attempt IDs, `failure_class`,
  optional `related_failure_class`, protected logs, and the original throwable
  delivered to the caller or `RuntimeFailureSink`;
- `MBCLI` — use `stage`, `subject`, and `exception_type` to find the operation,
  then inspect protected application/backend logs because CLI output is
  intentionally redacted.

For `MBD104`, fix the application container registration or factory shown by
the original container exception; do not wrap it in a generic library error.
With `ComposerDirectoryClassSource`, use `MBR026` and its exact `source_path` and
expected PSR-4 file for a misplaced class; use `MBR025` for duplicate FQCNs and
`MBR027` for case collisions. Also run
`composer dump-autoload --optimize --strict-psr`, correct the mapping/location,
and rebuild the artifact. A class physically outside every explicitly selected
mapping/directory remains outside the bounded scan.

Do not retry `MBR` or `MBC` failures. Do not retry an `MBD` failure merely from
its code; use the configured failure category and delivery policy. Never add
payloads, arbitrary exception messages, credentials, or secret metadata to a
portable diagnostic to make troubleshooting easier.

Report the selected profile, Integration Contract, public capabilities used,
host/package ownership, files changed, checks and runtime evidence, unresolved
compatibility risks, observed diagnostic codes and preserved cause chain, and
every mutation that was or was not performed.
