---
doc_id: reference.delivery-states
contract_revision: 1
---

# Delivery states

Основная цепочка: `staged|accepted → running → succeeded|failed|cancelled|outcome_unknown`; retry использует `retry_wait → running`.

- `staged` видим только внутри внешней application transaction до commit.

- `running` всегда связан с current AttemptId и lease token.

- Terminal states immutable; replay создаёт новую delivery.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
