---
doc_id: reference.delivery-states
contract_revision: 2
---

# Delivery states

Основная цепочка: `staged|accepted → running → succeeded|failed|cancelled|outcome_unknown`; retry использует `retry_wait → running`.

- `staged` видим только внутри внешней application transaction до commit.

- `running` всегда связан с current AttemptId и lease token.

- Terminal states immutable; replay создаёт новую delivery.

- Status сохраняет последнюю failure category, её AttemptId и безопасный descriptor `FailureDiagnostic`. Descriptor содержит code, stage, exception class, related class и fingerprint, но не сохраняет произвольный exception message или trace.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
