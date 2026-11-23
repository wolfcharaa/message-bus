---
doc_id: guides.runtime-observer
contract_revision: 3
---

# Runtime observer

`RuntimeObserver` receives typed, redacted lifecycle events; the PSR-3 adapter maps them to structured logs.

- Core wraps the observer in a best-effort boundary: observer failure cannot change a result or delivery outcome. `ObserverFailureSink` is the process-local emergency reporting hook, and its own failure is also suppressed.

- Events use versioned typed fields instead of an arbitrary attributes bag. Payloads, secrets, and raw exception messages/traces are excluded; class, category, and a non-secret fingerprint remain.

- Event type and required fields form a compatibility catalog.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
