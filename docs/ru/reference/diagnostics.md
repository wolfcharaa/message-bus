---
doc_id: reference.diagnostics
contract_revision: 1
---

# Diagnostics

Registry compiler выдаёт stable code, severity, message и symbol. Automation сравнивает code/severity, а не локализованный текст.

- `MBR001…MBR017` покрывают invalid roles, attributes, signatures, cardinality, context и durable identities.

- Несколько diagnostics сортируются детерминированно.

- `requireRegistry()` бросает исключение, если хотя бы одна error diagnostic остаётся.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
