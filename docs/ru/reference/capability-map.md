---
doc_id: reference.capability-map
contract_revision: 2
---

# Capability map

Карта связывает задачу consumer-а с публичной границей и владельцем.

- Sync orchestration → `MessageBus`, compiler/artifact; host владеет DI и handler services.

- Durable PostgreSQL → backend/schema/worker/control; host владеет credentials, deploy и process supervisor.

- Extension → только `@api spi`; custom adapter не импортирует `Internal`.

- Testing → in-memory profile для semantics плюс PostgreSQL/process suites для persistence и fork guarantees.

- Диагностика → [руководство по разбору](../guides/troubleshooting-diagnostics.md)
  задаёт порядок действий, а [справочник](diagnostics.md) — точное значение и
  исправление каждого `MBR`, `MBC`, `MBD` и `MBCLI`.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
