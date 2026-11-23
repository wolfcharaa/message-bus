---
doc_id: reference.diagnostics
contract_revision: 1
---

# Diagnostics

The registry compiler emits a stable code, severity, message, and symbol. Automation compares code and severity, not localized text.

- `MBR001…MBR017` cover invalid roles, attributes, signatures, cardinality, context, and durable identities.

- Multiple diagnostics are sorted deterministically.

- `requireRegistry()` throws while any error diagnostic remains.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
