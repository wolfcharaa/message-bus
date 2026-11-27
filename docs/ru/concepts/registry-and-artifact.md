---
doc_id: concepts.registry-and-artifact
contract_revision: 2
---

# Registry и artifact

Compiler получает ограниченный class source, валидирует declarations и создаёт детерминированный self-hashed JSON artifact.

- Production startup загружает artifact; он не сканирует filesystem и не компилирует handlers заново.

- Fingerprint меняется при изменении binding, role, method, context или message identity.

- Diagnostic codes `MBR001…MBR020` и runtime-configuration codes `MBC001…MBC008` стабильны для automation. Structured fields rule, subject, expected/actual и cause class локализуют нарушение; текст предназначен человеку.

- Любая hash/schema mismatch блокирует bootstrap до обработки сообщений.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
