---
doc_id: concepts.registry-and-artifact
contract_revision: 3
---

# Registry and artifact

The compiler consumes a bounded class source, validates declarations, and emits a deterministic self-hashed JSON artifact.

- Production startup loads the artifact; it does not scan the filesystem or recompile handlers.

- The fingerprint changes when a binding, role, method, context, or message identity changes.

- Diagnostic codes `MBR001…MBR020` and runtime-configuration codes `MBC001…MBC008` are stable for automation. Each message now includes its code, exact subject, expected state, and observed state; the same values remain structured fields. The complete meaning and correction for every code is in the [diagnostics reference](../reference/diagnostics.md).

- Any hash or schema mismatch blocks bootstrap before message handling.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
