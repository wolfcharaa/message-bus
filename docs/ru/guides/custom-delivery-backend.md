---
doc_id: guides.custom-delivery-backend
contract_revision: 1
---

# Custom durable backend

`DurableDeliveryBackend` — полный port, а не enqueue/dequeue abstraction.

- Adapter обязан реализовать atomic acceptance, fenced claim/heartbeat/settlement, cancel, recovery, replay и bounded prune.

- State/attempt history и race outcomes должны совпадать с PostgreSQL adapter.

- Объявляйте production support только после package conformance и crash/race tests.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
