---
doc_id: guides.runtime-observer
contract_revision: 4
---

# Runtime observer

`RuntimeObserver` receives typed, redacted lifecycle events; the PSR-3 adapter maps them to structured logs.

- Core wraps the observer in a best-effort boundary: observer failure cannot change a result or delivery outcome. `ObserverFailureSink` is the process-local emergency reporting hook, and its own failure is also suppressed.

- Portable events use versioned typed fields instead of an arbitrary attributes bag. Payloads, secrets, and raw exception messages/traces are excluded; stage, stable code, class, safe summary, category, and a non-secret fingerprint remain.

- Configure `RuntimeFailureSink` when the process-local logger must receive the original runtime `Throwable`. `PsrLogRuntimeFailureSink` places that exact object in the PSR-3 `exception` context while retaining the redacted event fields. `NullRuntimeFailureSink` is an explicit opt-out.

- Runtime failure sinks are also best effort. A sink failure is sent to `ObserverFailureSink`; failure of that last-resort sink is the documented final drop boundary and never replaces the handler, DI container, classifier, or settlement failure.

- Synchronous DI/handler failures are rethrown as the original object where the public contract permits it. When both primary work and cleanup or settlement fail, `CleanupFailed` or `DeliverySettlementFailed` retains both throwables in named properties instead of overwriting either cause.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
