---
doc_id: concepts.registry-and-artifact
contract_revision: 4
---

# Registry и artifact

Compiler получает ограниченный class source, валидирует declarations и создаёт детерминированный self-hashed JSON artifact.

- Production startup загружает artifact; он не сканирует filesystem и не компилирует handlers заново.

- Fingerprint меняется при изменении binding, role, method, context или message identity.

- Diagnostic codes `MBR001…MBR028` и runtime-configuration codes `MBC001…MBC008` стабильны для automation. Каждое message включает code, точный subject, expected state и observed state; discovery diagnostics также сохраняют `source_path`. Значение и исправление каждого кода приведены в [справочнике диагностик](../reference/diagnostics.md).

- Опциональный `ComposerDirectoryClassSource` проверяет production Composer mappings. Полный setup, build artifact и dev refresh описаны в [quick start](../getting-started/composer-directory-discovery.md).

- `RegistryRefreshPolicy::WhenStale` работает только на bootstrap, под lock и без stale fallback. `PrecompiledOnly` не принимает discovery source и не обращается к исходникам.

- Первый scanner/autoload/attribute throwable сохраняется в `RegistryCompilationResult::$primaryCause` и становится `previous` у `InvalidMessageBusConfiguration`.

- Любая hash/schema mismatch блокирует bootstrap до обработки сообщений.

Точные signatures перечислены в [публичном API](../reference/public-api.md); при конфликте source manifest является нормативным.
