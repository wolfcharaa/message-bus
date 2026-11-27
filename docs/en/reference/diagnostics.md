---
doc_id: reference.diagnostics
contract_revision: 2
---

# Diagnostics

The registry compiler emits a stable code, severity, message, symbol, rule, subject kind, expected/actual values, and optional cause class. Automation compares structured fields, not localized text.

- `MBR001…MBR020` cover invalid roles, attributes, signatures, cardinality, context, durable identities, optional/variadic parameters, non-instantiable message types, and attribute-constructor failures.

- `MBC001…MBC008` cover route, policy, idempotency runtime, lost-attempt, and target-scoped resolver configuration.

- Runtime failures use `MBD001` for dispatch and `MBD101…MBD111` for payload decoding, target/context/handler resolution, handler execution, idempotency, classification, settlement, worker fork, lease recovery, and delivery acceptance.

- CLI failures use `MBCLI001…MBCLI004`; the separate `stage` field pinpoints the failed command operation.

- Multiple diagnostics are sorted deterministically.

- `requireRegistry()` throws while any error diagnostic remains and exposes the complete diagnostic list through `InvalidMessageBusConfiguration::$diagnostics`.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
