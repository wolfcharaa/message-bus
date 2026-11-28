# Changelog

## Unreleased

## 7.0.0-beta.3 — 2026-11-28

- Restored the handler interceptor model as one deterministic chain: global
  middleware, persisted durable-policy middleware, handler-local attribute
  middleware, then the handler.
- Added `#[Handler(middleware: [...])]`, persisted middleware references in
  registry artifacts and PostgreSQL delivery-policy snapshots, and diagnostic
  `MBR030` for an invalid local middleware class.
- Resolved policy/local middleware through the handler application scope while
  preserving exact DI container failures; durable resolution failures are
  settled and reported at `handler_resolution`.
- Added focused inline, durable, Event-subscription, ordering, artifact,
  PostgreSQL, and DI-failure tests plus paired RU/EN integration documentation.
- Replaced the PSR-3 test double with a PHPUnit-generated interface stub so the
  PHP 8.3 prefer-lowest lane supports `psr/log` 1.x without weakening the
  `psr/log` 3.x contract.

## 7.0.0-beta.2 — 2026-11-28

- Added optional `composer/class-map-generator` directory discovery for root
  production PSR-4/classmap mappings, with exact source paths and diagnostics
  `MBR021`–`MBR028` for dependency, mapping, parser, duplicate, PSR-4, casing,
  and empty-scan failures.
- Preserved scanner, autoload/DI, and attribute failures as the `previous`
  exception of `InvalidMessageBusConfiguration` instead of replacing their
  cause with a library-only error.
- Added opt-in bootstrap refresh policies: locked atomic `WhenStale` for
  development and scan-free `PrecompiledOnly` for production, with no stale
  fallback after a failed rebuild.
- Added paired RU/EN setup and diagnostic documentation, an executable example,
  and scenario/concurrency tests for every new diagnostic and refresh state.
- Added optional stable Command `bindingId` values so applications can own
  durable target names without rewriting the compiled registry; invalid role
  placement is reported as `MBR029`.
- Added a PSR-11 handler resolver that preserves container/autowiring
  exceptions, plus registry-derived JSON/PHP-native codec construction.
- Added `PostgresRuntime::create()` as the cohesive process-local PostgreSQL
  composition and methods for registering MessageBus commands in an existing
  Symfony Console application.
- Updated the packaged durable example, paired integration/reference docs,
  public API manifest, and consumer integration skill for the shorter path.

## 7.0.0-beta.1 — 2026-11-27

- Made built-in registry, runtime-configuration, runtime-failure, and CLI error
  messages self-contained with stable codes and exact failure locations while
  continuing to redact arbitrary exception text from portable diagnostics.
- Added a complete paired RU/EN reference for all 44 diagnostic codes and
  compatibility checks that prevent either edition from omitting a code.
- Kept previously persisted schema-1 failure descriptors readable after the
  expanded canonical summaries.
- Added direct, catalog-locked scenarios for all 44 diagnostic codes, including
  exact subjects, expected and actual states, stage/cause preservation, and
  secret redaction assertions.
- Preserved durable acceptance stages in dispatch failures and reported
  variadic handler parameters without hiding that they are also optional.
- Added a paired diagnostic troubleshooting path, expanded the bundled
  integration skill with code-directed failure triage, and enabled `llm/skills`
  in the development toolchain.

## 7.0.0-alpha.1 — 2026-11-23

- Replaced the v6 multi-path API with one generic `dispatch()` and four exact
  message roles.
- Replaced runtime discovery with a deterministic compiled registry artifact.
- Added per-target durable delivery, fenced attempts, cancellation, recovery,
  replay, bounded pruning, worker control, and atomic idempotency boundaries.
- Added immutable PostgreSQL migrations and an offline v6 importer; no
  dual-write or automatic startup DDL is provided.
- Added a single PCNTL parent/child worker model, typed runtime observation, and
  optional Symfony Console and PSR-3 adapters.
- Established a source-derived public API manifest, paired RU/EN documentation,
  executable examples, controlled Composer archive, and opt-in integration skill.

This is a breaking major release. See
[RU migration](docs/ru/migration/v6-to-v7.md) or
[English migration](docs/en/migration/v6-to-v7.md). Historical v4–v6 notes
remain in the corresponding v6 tags.
