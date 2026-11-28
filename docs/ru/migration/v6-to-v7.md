---
doc_id: migration.v6-to-v7
contract_revision: 3
---

# Миграция v6 → v7

V7 — intentional breaking redesign без shims для Envelope, Flow, execution strategies, queue rows и worker internals.

- Сначала классифицируйте каждый message как Query, Request, Command либо Event и перенесите handlers на method `#[Handler]`.

- Замените runtime discovery на compiled artifact, public dispatch/publish paths — на единый `dispatch()`.

- Замените v6 flow/binding interceptors на global `HandlerMiddlewares`,
  `DeliveryPolicy(middleware: [...])` и локальные
  `#[Handler(middleware: [...])]`. Сохраняется порядок global/policy, затем
  binding-local и handler; middleware services разрешаются из того же
  application scope, что и handlers.

- Для перехода без ручного списка классов подключите
  [Composer directory discovery](../getting-started/composer-directory-discovery.md),
  устраните `MBR021`–`MBR028` на build stage и используйте
  `PrecompiledOnly` в production.

- Durable cutover выполняется offline: остановить producers, drain workers, доказать zero running, dry-run importer, применить v7 schema, import/backfill/verify.

- После первой v7 acceptance rollback только forward; legacy tables остаются read-only до отдельной application migration.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
