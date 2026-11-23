---
doc_id: operations.pause-resume
contract_revision: 2
---

# Pause и resume

Control state revisioned и действует на global либо exact profile scope; он останавливает claims, но не убивает уже начатые attempts.

- Pause/resume mutation имеет idempotency key и optional expected revision.

- `worker:pause --wait` ожидает effective paused state, applied global/profile revisions и ноль authoritative active attempts; timeout является non-zero outcome.

- `worker:status` вычисляет required/applied revisions, idle/busy, stale и drained без изменения строк. Доступны взаимоисключающие filters `--profile` и `--instance`.

- Start/stop процесса остаётся ответственностью external supervisor.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
