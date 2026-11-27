---
doc_id: reference.cli
contract_revision: 6
---

# CLI reference

Optional Symfony Console adapter предоставляет versioned text/JSON commands; binaries получают composition из `MESSAGE_BUS_BOOTSTRAP`.

- `registry:check`, `registry:compile`, `schema:validate` — build/deploy tooling.

- `delivery:status`, `delivery:cancel`, `delivery:replay`, `delivery:recover`, `delivery:prune` предоставляют bounded delivery operations. `delivery:prune` остаётся dry-run без явного `--execute`.

- `idempotency:prune` планирует или удаляет только completed records после `retainUntil`; эта команда также dry-run по умолчанию.

- `worker:run`, `worker:status`, `worker:pause`, `worker:resume`, `worker:prune` — runtime operations. Status принимает `--profile` либо exact `--instance`, показывает convergence/stale/drained; prune удаляет только terminal worker history и остаётся dry-run без `--execute`.

- Каждый JSON document использует output `schema: 2` и содержит `command`. Перехваченная ошибка команды возвращается единым объектом `error` с полями `code`, `type`, `stage`, безопасным `message`, `exception_type` и необязательным subject `{type,id}`. Raw exception message не выводится.

- Коды ошибок: `MBCLI001` — invalid input, `MBCLI002` — конфликт retained state, `MBCLI003` — backend failure, `MBCLI004` — неожиданная internal failure. Безопасное message повторяет code, `stage`, subject и exception type, не копируя произвольный exception text. Условия и действия описаны в [справочнике диагностик](diagnostics.md); non-zero exit codes остаются специфичными для команды.

- `delivery:status` выводит `last_failure_category`, `last_failure_attempt_id` и redacted descriptor `last_failure`, если была сохранена canonical diagnostic.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
