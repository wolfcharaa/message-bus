---
doc_id: guides.delivery-policies
contract_revision: 2
---

# Delivery policies

Route выбирает lane и именованную immutable policy: attempts, delay, lease, deadline, priority и idempotency requirement.

- Policy snapshot сохраняется вместе с delivery и не меняется от редактирования config.

- Library сначала распознаёт cancellation, incompatible delivery и явный terminal wrapper; затем вызывается не более одного application `FailureClassifier`. Неизвестная handler-ошибка по умолчанию retryable в пределах сохранённого budget, а ошибка самого classifier становится terminal `classifier_error`.

- `retryLostAttempts` отделён от обычного caught-failure retry и разрешён только для idempotent target с compile-validated resolver/boundary. `RuntimeConfigurationRule` проверяет полный route/policy/target plan.

- Retry не обещает exactly-once external effect.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
