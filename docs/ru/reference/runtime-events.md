---
doc_id: reference.runtime-events
contract_revision: 2
---

# Runtime events

Стабильные event types: dispatch started/completed/failed, delivery accepted/recovered и attempt started/succeeded/failed.

- Каждое событие имеет schema version, recommended severity, timestamp и применимые message/correlation/causation, delivery/attempt, target/route, duration/outcome fields.

- Произвольного attributes bag нет. Failure содержит class, stable category и fingerprint без raw message/trace; payload и secret metadata не включаются.

- Новый обязательный field либо semantic change требует catalog revision.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
