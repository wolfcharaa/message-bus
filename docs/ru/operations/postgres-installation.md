---
doc_id: operations.postgres-installation
contract_revision: 1
---

# Установка PostgreSQL schema

Host применяет ordered SQL artifacts из `resources/migrations`; producer и worker никогда не выполняют DDL на startup.

- Используйте отдельный deploy role с CREATE/ALTER правами и runtime roles только с необходимым DML.

- Manifest хранит component, immutable migration ID, checksum и installation fingerprint.

- `schema:validate` обязан пройти до rollout; mismatch не исправляется автоматически.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
