---
doc_id: concepts.registry-and-artifact
contract_revision: 4
---

# Registry and artifact

The compiler consumes a bounded class source, validates declarations, and emits a deterministic self-hashed JSON artifact.

- Production startup loads the artifact; it does not scan the filesystem or recompile handlers.

- The fingerprint changes when a binding, role, method, context, or message identity changes.

- Diagnostic codes `MBR001…MBR028` and runtime-configuration codes `MBC001…MBC008` are stable for automation. Every message includes its code, exact subject, expected state, and observed state; discovery diagnostics also preserve `source_path`. The complete meaning and correction for every code is in the [diagnostics reference](../reference/diagnostics.md).

- The optional `ComposerDirectoryClassSource` validates production Composer mappings. The complete setup, artifact build, and development refresh are in the [quick start](../getting-started/composer-directory-discovery.md).

- `RegistryRefreshPolicy::WhenStale` runs only at bootstrap, under a lock, and without a stale fallback. `PrecompiledOnly` rejects a discovery source and does not access source files.

- The first scanner/autoload/attribute throwable is preserved in `RegistryCompilationResult::$primaryCause` and becomes the `previous` exception of `InvalidMessageBusConfiguration`.

- Any hash or schema mismatch blocks bootstrap before message handling.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
