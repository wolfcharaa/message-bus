---
doc_id: reference.runtime-events
contract_revision: 3
---

# Runtime events

Stable event types cover dispatch started/completed/failed, delivery accepted/recovered, and attempt started/succeeded/failed.

- `RuntimeEventType::requiredFields()` defines the fields required by each event type. Construction fails with the exact event type and missing field, so an incomplete event cannot reach an observer.

- A failure event carries `failure_code`, `failure_stage`, `failure_summary`, `failure_class`, and `error_fingerprint`. `attempt_failed` also carries the retry category; a secondary classifier failure is exposed as `related_failure_class`.

- `FailureDiagnostic` is the same safe descriptor retained by delivery status. It deliberately excludes arbitrary exception messages, traces, payloads, and secret metadata.

- A new required field or semantic change requires a catalog revision.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
