---
doc_id: operations.worker-profiles
contract_revision: 1
---

# Worker profiles

Один immutable profile владеет одной lane, concurrency и time bounds; произвольные handler filters не поддерживаются.

- Heartbeat interval должен быть заметно меньше lease и укладываться в storage outage budget.

- Child runtime factory создаёт fresh process-local connections после fork.

- External Docker/systemd grace должен превышать internal drain/kill window.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
