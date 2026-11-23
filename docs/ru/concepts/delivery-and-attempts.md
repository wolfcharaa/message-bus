---
doc_id: concepts.delivery-and-attempts
contract_revision: 1
---

# Delivery и attempts

Один logical Message создаёт независимую Delivery на каждый durable target; каждая попытка получает отдельные AttemptId и lease token.

- Acceptance сохраняет canonical message и target delivery атомарно.

- Claim выполняется через `FOR UPDATE SKIP LOCKED`, DB clock и fenced compare-and-set.

- Late worker не может завершить новую attempt старым lease token.

- `outcome_unknown` означает возможный внешний effect без доказанного settlement; это terminal outcome, а не обычный retry.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
