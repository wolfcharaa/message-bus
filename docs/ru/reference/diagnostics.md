---
doc_id: reference.diagnostics
contract_revision: 2
---

# Diagnostics

Registry compiler выдаёт stable code, severity, message, symbol, rule, subject kind, expected/actual values и необязательный cause class. Automation сравнивает structured fields, а не локализованный текст.

- `MBR001…MBR020` покрывают invalid roles, attributes, signatures, cardinality, context, durable identities, optional/variadic parameters, non-instantiable message types и ошибки attribute constructor.

- `MBC001…MBC008` покрывают конфигурацию route, policy, idempotency runtime, lost-attempt и target-scoped resolver.

- Runtime failure использует `MBD001` для dispatch и `MBD101…MBD111` для payload decoding, target/context/handler resolution, handler execution, idempotency, classification, settlement, worker fork, lease recovery и delivery acceptance.

- CLI failure использует `MBCLI001…MBCLI004`; отдельное поле `stage` точно указывает упавшую операцию команды.

- Несколько diagnostics сортируются детерминированно.

- `requireRegistry()` бросает исключение, если хотя бы одна error diagnostic остаётся, и предоставляет полный список через `InvalidMessageBusConfiguration::$diagnostics`.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
