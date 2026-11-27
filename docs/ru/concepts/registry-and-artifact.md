---
doc_id: concepts.registry-and-artifact
contract_revision: 3
---

# Registry и artifact

Compiler получает ограниченный class source, валидирует declarations и создаёт детерминированный self-hashed JSON artifact.

- Production startup загружает artifact; он не сканирует filesystem и не компилирует handlers заново.

- Fingerprint меняется при изменении binding, role, method, context или message identity.

- Diagnostic codes `MBR001…MBR020` и runtime-configuration codes `MBC001…MBC008` стабильны для automation. Каждое message теперь включает code, точный subject, expected state и observed state; те же значения остаются в structured fields. Значение и исправление каждого кода приведены в [справочнике диагностик](../reference/diagnostics.md).

- Любая hash/schema mismatch блокирует bootstrap до обработки сообщений.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
