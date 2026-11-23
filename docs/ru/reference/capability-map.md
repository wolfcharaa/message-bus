---
doc_id: reference.capability-map
contract_revision: 1
---

# Capability map

Карта связывает задачу consumer-а с публичной границей и владельцем.

- Sync orchestration → `MessageBus`, compiler/artifact; host владеет DI и handler services.

- Durable PostgreSQL → backend/schema/worker/control; host владеет credentials, deploy и process supervisor.

- Extension → только `@api spi`; custom adapter не импортирует `Internal`.

- Testing → in-memory profile для semantics плюс PostgreSQL/process suites для persistence и fork guarantees.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
