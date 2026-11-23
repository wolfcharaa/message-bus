---
doc_id: reference.configuration
contract_revision: 1
---

# Configuration reference

Library не читает environment variables. Host строит registry, service map, routes, context factories, middleware и optional durable runtime явным PHP composition.

- Orchestration-only требует registry, handlers и routes.

- Durable добавляет backend, codecs, policies, worker profile/control и observer по необходимости.

- Unknown handler, context factory, route, codec или policy блокирует constructor/bootstrap.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
