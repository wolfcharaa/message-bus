---
doc_id: operations.recovery-and-pruning
contract_revision: 2
---

# Recovery и pruning

Recovery обрабатывает только expired exact leases через bounded CAS; prune удаляет только terminal operational history старше cutoff.

- Active delivery не prune-ится независимо от возраста.

- Dry-run является default; execute требует явного выбора и bounded batch size.

- Для recovery используйте `delivery:recover --limit=<n>`, для retention — `delivery:prune --terminal-before=<instant> --batch-size=<n> [--execute]`.

- После prune status/replay возвращают not-found; business history заранее сохраняется приложением.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
