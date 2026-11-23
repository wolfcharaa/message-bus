---
doc_id: guides.atomic-idempotency
contract_revision: 3
---

# Atomic idempotency

Для idempotent durable target resolver строит exact target identity, normalized key и versioned intent fingerprint. Persistence хранит digest key, а boundary атомарно фиксирует только `executed|replayed` для void effect.

- Boundary должен жить в той же transaction, что и защищаемый application effect.

- Повтор с тем же key и другим fingerprint — conflict, а не cached success.

- Retention в `DeliveryPolicy` покрывает max elapsed, replay window и safety margin; итоговый record содержит `retainUntil`.

- `IdempotencyIdentityResolvers` содержит ровно один resolver на stable target; duplicate/unknown/missing target отклоняются до runtime. Completed replay продлевает `retainUntil` до нового safety horizon.

- Operational replay `outcome_unknown` разрешён только внутри сохранённого replay window исходной delivery; новая policy не может задним числом расширить это окно.

- Обычный handler retry без atomic boundary не становится exactly-once.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
