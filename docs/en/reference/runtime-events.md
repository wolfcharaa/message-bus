---
doc_id: reference.runtime-events
contract_revision: 2
---

# Runtime events

Stable event types cover dispatch started/completed/failed, delivery accepted/recovered, and attempt started/succeeded/failed.

- Every event has a schema version, recommended severity, timestamp, and applicable message/correlation/causation, delivery/attempt, target/route, and duration/outcome fields.

- There is no arbitrary attributes bag. A failure carries class, stable category, and a fingerprint without the raw message/trace; payload and secret metadata are excluded.

- A new required field or semantic change requires a catalog revision.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
