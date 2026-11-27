---
doc_id: guides.runtime-observer
contract_revision: 4
---

# Runtime observer

`RuntimeObserver` получает typed, redacted lifecycle events; PSR-3 adapter переводит их в structured logs.

- Core оборачивает observer в best-effort boundary: его failure не меняет result или delivery outcome. `ObserverFailureSink` служит process-local emergency hook; его собственная ошибка также подавляется.

- Portable event использует versioned typed fields вместо произвольного attributes bag. Payload, secrets и raw exception message/trace не передаются; остаются stage, stable code, class, safe summary, category и non-secret fingerprint.

- Подключите `RuntimeFailureSink`, если process-local logger должен получить исходный runtime `Throwable`. `PsrLogRuntimeFailureSink` кладёт этот же объект в PSR-3 context `exception`, сохраняя redacted event fields. `NullRuntimeFailureSink` явно отключает такой вывод.

- Runtime failure sink также работает best effort. Его ошибка передаётся в `ObserverFailureSink`; ошибка последнего emergency sink является документированной final drop boundary и не заменяет ошибку handler, DI container, classifier или settlement.

- Синхронная ошибка DI/handler повторно бросается тем же объектом, когда это допускает публичный контракт. Если одновременно падают основная операция и cleanup либо settlement, `CleanupFailed` или `DeliverySettlementFailed` сохраняет оба throwable в именованных свойствах, не перезаписывая причину.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
