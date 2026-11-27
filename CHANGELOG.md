# Changelog

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
