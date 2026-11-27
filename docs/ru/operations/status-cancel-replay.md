---
doc_id: operations.status-cancel-replay
contract_revision: 3
---

# Status, cancel и replay

Application использует opaque DeliveryId и публичные operations, не читает internal tables напрямую.

- Cancel возвращает race outcome: before-start, requested-running, already-requested, already-terminal или not-found.

- Replay всегда создаёт новый DeliveryId и сохраняет ссылку на исходную history.

- Status показывает exact attempt последней ошибки и её redacted failure descriptor, если backend сохранил canonical diagnostic. Legacy free-form summary остаётся читаемой в истории, но не повышается до structured diagnostic.

- Replay `outcome_unknown` требует idempotent policy и выполняется только внутри сохранённого replay window исходной delivery; новая policy не расширяет старый safety horizon.

- Любая mutation требует авторизованные actor/reason; API библиотеки не выполняет authorization.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
