---
doc_id: operations.recovery-and-pruning
contract_revision: 2
---

# Recovery and pruning

Recovery processes only expired exact leases through bounded CAS; pruning deletes only terminal operational history older than a cutoff.

- An active delivery is never pruned, regardless of age.

- Dry-run is the default; execution is explicit and always bounded by batch size.

- Use `delivery:recover --limit=<n>` for recovery and `delivery:prune --terminal-before=<instant> --batch-size=<n> [--execute]` for retention.

- After pruning, status and replay return not-found; applications preserve required business history beforehand.

Exact signatures are listed in the [public API](../reference/public-api.md); if they conflict, the source manifest is normative.
