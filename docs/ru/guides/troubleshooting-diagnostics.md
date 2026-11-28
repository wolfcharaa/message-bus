---
doc_id: guides.troubleshooting-diagnostics
contract_revision: 2
---

# Разбор и устранение диагностик

<a href="../../en/guides/troubleshooting-diagnostics.md">English version</a>

Это руководство задаёт порядок действий при ошибке. Точное условие и
исправление каждого кода находятся в [справочнике диагностик](../reference/diagnostics.md).

## Безопасная последовательность

1. Сохраните исходное исключение и всю цепочку `previous`. Не заменяйте ошибку
   DI-контейнера, handler, codec или backend общей ошибкой приложения.
2. Запишите структурированный контекст: code, stage, symbol/target, expected,
   actual, message/delivery/attempt ID, `failure_class` и
   `related_failure_class`, если они доступны.
3. Выберите владельца исправления по префиксу кода.
4. Найдите точный код в справочнике и исправьте причину, а не текст сообщения.
5. Повторите минимальную проверку, затем перестройте registry artifact или
   перезапустите только затронутую delivery согласно её policy.

| Префикс | Где возникает | Источник фактов | Действие |
| --- | --- | --- | --- |
| `MBR` | Discovery/компиляция реестра | `RegistryDiagnostic`: `symbol`, `source_path`, `expected`, `actual`, `cause_class`; исходная причина в `previous` | Исправить mapping/declaration/autoload, снова скомпилировать реестр. Не запускать runtime. |
| `MBC` | Проверка runtime-конфигурации | Target, route, policy, idempotency/resolver fields | Исправить composition root и повторить preflight. Не retry delivery. |
| `MBD` | Dispatch или worker attempt | Runtime event/status, IDs, классы ошибок и исходный throwable | Найти упавшую границу, проверить защищённые logs и применять retry только по `FailureCategory`/policy. |
| `MBCLI` | Operator CLI | `stage`, `subject`, `exception_type`, exit code | Исправить input/state либо перейти к защищённым backend/application logs. |

## Сначала используйте правильный канал

- Ошибка синхронного dispatch возвращается вызывающему коду без замены. Полный
  throwable является главным источником причины; `dispatch_failed` нужен для
  корреляции и автоматизации.
- Worker передаёт исходную ошибку в `RuntimeFailureSink`, а безопасный
  `FailureDiagnostic` сохраняет в runtime event и delivery status.
- CLI намеренно не выводит произвольный exception message, trace, payload или
  credentials. `MBCLI` указывает место, а подробности остаются в защищённых
  логах приложения/backend.

## Типовые случаи

### Handler не найден или объявлен неверно

Начните с самого раннего `MBR` для этого класса. `MBR002`–`MBR011`, `MBR018` и
`MBR019` объясняют точную ошибку метода или сигнатуры. Последующий `MBR014` часто
является следствием того, что invalid handler не попал в compiled bindings.
Исправьте раннюю диагностику и только затем проверяйте cardinality.

Если DI-контейнер падает во время dispatch, `MBD104` сохраняет стадию
`handler_resolution`, а библиотека повторно выбрасывает исходное container
exception. Исправляйте регистрацию/factory из этого исключения; не ловите его с
заменой на общий `Handler failed`.

### Ошибка `MessageName`

- `MBR012`: атрибут отсутствует либо объявлен больше одного раза;
- `MBR016`: wire name уже принадлежит другому durable message;
- `MBR020`: значение не прошло grammar `[a-z][a-z0-9._:-]{2,127}`.

После исправления durable identity перестройте registry artifact и проверьте
совместимость уже сохранённых payload до удаления старого name/codec.

### Класс размещён вне Composer mapping

Сначала проверьте autoload boundary:

```bash
composer dump-autoload --optimize --strict-psr
```

`ComposerDirectoryClassSource` проверяет весь выбранный production root:
неправильный namespace/путь даёт `MBR026` с фактическим `source_path` и ожидаемым
файлом; duplicate FQCN — `MBR025`; case collision — `MBR027`. Явно переданный
невидимый класс даёт `MBR001`; handler с непригодным message type — `MBR004`;
видимый Query/Request/Command без handler — `MBR014`.

Класс вне явно выбранных mappings/directories остаётся вне bounded scan. Для
нового подключения используйте [Composer directory discovery](../getting-started/composer-directory-discovery.md),
исправьте mapping/расположение и перестройте artifact.

## Проверка после исправления

В application-owned bootstrap путь является примером и должен быть заменён на
реальный файл приложения:

```bash
MESSAGE_BUS_BOOTSTRAP=/path/to/message-bus-bootstrap.php \
  vendor/bin/message-bus registry:check --format=json

MESSAGE_BUS_BOOTSTRAP=/path/to/message-bus-bootstrap.php \
  vendor/bin/message-bus delivery:status \
  --delivery-id='delivery:example' --format=json
```

Для `MBR`/`MBC` результат должен стать valid до запуска runtime. Для `MBD`
проверьте, что новая attempt использует исправленный build/artifact и что policy
вообще разрешает retry. Не копируйте secret metadata или raw payload в
переносимую диагностику ради удобства.
