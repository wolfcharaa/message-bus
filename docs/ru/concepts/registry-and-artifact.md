---
doc_id: concepts.registry-and-artifact
contract_revision: 1
---

# Registry и artifact

Compiler получает ограниченный class source, валидирует declarations и создаёт детерминированный self-hashed JSON artifact.

- Production startup загружает artifact; он не сканирует filesystem и не компилирует handlers заново.

- Fingerprint меняется при изменении binding, role, method, context или message identity.

- Diagnostic codes `MBR001…MBR017` стабильны для automation; текст предназначен человеку.

- Любая hash/schema mismatch блокирует bootstrap до обработки сообщений.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
