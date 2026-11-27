---
doc_id: reference.runtime-events
contract_revision: 4
---

# Runtime events

Стабильные event types: dispatch started/completed/failed, delivery accepted/recovered и attempt started/succeeded/failed.

- `RuntimeEventType::requiredFields()` определяет обязательные поля для каждого event type. При создании неполного события исключение точно указывает event type и отсутствующее поле, поэтому такое событие не попадёт в observer.

- Failure event содержит `failure_code`, `failure_stage`, `failure_summary`, `failure_class` и `error_fingerprint`. `attempt_failed` также содержит retry category; отдельная ошибка classifier доступна как `related_failure_class`.

- Тот же безопасный дескриптор `FailureDiagnostic` сохраняется в delivery status. Его summary начинается со стабильного `MBD` code и точно называет упавшую операцию. Произвольные exception message, trace, payload и secret metadata в него намеренно не входят; все stages описаны в [справочнике диагностик](diagnostics.md).

- Новый обязательный field либо semantic change требует catalog revision.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
