---
doc_id: guides.atomic-idempotency
contract_revision: 3
---

# Atomic idempotency

For an idempotent durable target, the resolver creates an exact target identity, normalized key, and versioned intent fingerprint. Persistence stores the key digest, and the boundary atomically records only `executed|replayed` for a void effect.

- The boundary must share the transaction that owns the protected application effect.

- The same key with a different fingerprint is a conflict, not cached success.

- `DeliveryPolicy` retention must cover max elapsed time, the replay window, and a safety margin; the record carries the resulting `retainUntil`.

- `IdempotencyIdentityResolvers` contains exactly one resolver per stable target; duplicate, unknown, and missing targets fail before runtime. A completed replay extends `retainUntil` to the new safety horizon.

- Operational replay of `outcome_unknown` is allowed only inside the original delivery’s persisted replay window; a new policy cannot widen that window retroactively.

- Ordinary handler retry without an atomic boundary is not exactly-once.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
