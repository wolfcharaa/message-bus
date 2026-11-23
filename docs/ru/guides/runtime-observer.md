---
doc_id: guides.runtime-observer
contract_revision: 3
---

# Runtime observer

`RuntimeObserver` получает typed, redacted lifecycle events; PSR-3 adapter переводит их в structured logs.

- Core оборачивает observer в best-effort boundary: его failure не меняет result или delivery outcome. `ObserverFailureSink` служит process-local emergency hook; его собственная ошибка также подавляется.

- Event имеет versioned typed fields вместо произвольного attributes bag. Payload, secrets и raw exception message/trace не передаются; остаются class, category и non-secret fingerprint.

- Event type и обязательные fields являются compatibility catalog.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
