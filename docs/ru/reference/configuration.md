---
doc_id: reference.configuration
contract_revision: 2
---

# Configuration reference

Library не читает environment variables. Host строит registry, service map, routes, context factories, middleware и optional durable runtime явным PHP composition.

- Orchestration-only требует registry, handlers и routes.

- Durable добавляет backend, codecs, policies, worker profile/control и observer по необходимости.

- Unknown handler, context factory, route, codec или policy блокирует constructor/bootstrap.

- Для быстрого старта `ComposerDirectoryClassSource::fromComposerJson()` читает
  только root production `psr-4`, `classmap` и `exclude-from-classmap`.
  Подключение и обязательный explicit path описаны в
  [Composer discovery](../getting-started/composer-directory-discovery.md).

- Host явно выбирает `RegistryRefreshPolicy`: `PrecompiledOnly` для production
  без source и `WhenStale` для dev с refreshable source. Библиотека не читает
  `APP_ENV` сама и не пересобирает реестр внутри dispatch.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
