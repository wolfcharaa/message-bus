---
doc_id: guides.delivery-policies
contract_revision: 2
---

# Delivery policies

A route selects a lane and named immutable policy: attempts, delay, lease, deadline, priority, and idempotency requirement.

- The policy snapshot is persisted with the delivery and does not change when configuration is edited.

- The library first recognizes cancellation, incompatible delivery, and an explicit terminal wrapper; at most one application `FailureClassifier` runs afterward. An unknown handler failure is retryable within the persisted budget, while a classifier failure becomes terminal `classifier_error`.

- `retryLostAttempts` is separate from ordinary caught-failure retry and is valid only for an idempotent target with a compile-validated resolver/boundary. `RuntimeConfigurationRule` validates the complete route/policy/target plan.

- Retry does not promise exactly-once external effects.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
