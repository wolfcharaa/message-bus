---
doc_id: reference.cli
contract_revision: 4
---

# CLI reference

Optional Symfony Console adapter предоставляет versioned text/JSON commands; binaries получают composition из `MESSAGE_BUS_BOOTSTRAP`.

- `registry:check`, `registry:compile`, `schema:validate` — build/deploy tooling.

- `delivery:status`, `delivery:cancel`, `delivery:replay`, `delivery:recover`, `delivery:prune` предоставляют bounded delivery operations. `delivery:prune` остаётся dry-run без явного `--execute`.

- `idempotency:prune` планирует или удаляет только completed records после `retainUntil`; эта команда также dry-run по умолчанию.

- `worker:run`, `worker:status`, `worker:pause`, `worker:resume`, `worker:prune` — runtime operations. Status принимает `--profile` либо exact `--instance`, показывает convergence/stale/drained; prune удаляет только terminal worker history и остаётся dry-run без `--execute`.

- JSON document содержит `schema` и `command`; invalid input, conflict/timeout и schema failure используют non-zero exit codes.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
