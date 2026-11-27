---
doc_id: reference.diagnostics
contract_revision: 3
---

# Справочник диагностик

<a href="../../en/reference/diagnostics.md">English version</a>

Это полный справочник собственных кодов библиотеки `MBR`, `MBC`, `MBD` и `MBCLI`. Код стабильно обозначает конкретное нарушенное правило. Английское поле `message` самодостаточно: в нём повторяются код, точный класс/метод/target, ожидаемое и фактическое состояние. Автоматизация всё равно должна сравнивать структурированные поля, а не разбирать текст.

## Как читать диагностику

Диагностика реестра содержит:

- `code` и `rule` — стабильный идентификатор правила;
- `severity` — `error` или `warning`;
- `message` — полное понятное человеку объяснение;
- `symbol` — класс, `Class::method`, имя сообщения, класс контекста или target с ошибкой;
- `subject_kind` — `symbol` для правил компилятора или `target` для runtime-конфигурации;
- `expected` и `actual` — нарушенный контракт и обнаруженная декларация/настройка;
- `cause_class` — класс исключения, если упал конструктор атрибута.

Например, приватный статический handler даёт сообщение такого вида:

```text
[MBR002] A method marked as a handler has an invalid attribute count, visibility, or static modifier. Subject: App\QueryHandler::handle. Expected: exactly 1 #[Handler] attribute on a public non-static method. Actual: 1 #[Handler] attribute(s), private, static.
```

`RegistryCompilationResult::requireRegistry()` включает сообщения всех ошибок в `InvalidMessageBusConfiguration::getMessage()`, а исходный типизированный список доступен через `InvalidMessageBusConfiguration::$diagnostics`.

## Компилятор реестра (`MBR`)

| Код | Точное условие и место | Исправление |
| --- | --- | --- |
| `MBR001` | Класс, возвращённый `RegistryClassSource`, не загружается; `symbol` содержит точное имя, `actual` — результат неуспешного `class_exists()`. | Исправьте Composer autoload или удалите устаревшую explicit-запись, затем перестройте autoloader. |
| `MBR002` | Метод помечен `#[Handler]`, но атрибут не один, метод не public или является static; `symbol` равен `Class::method`, `actual` перечисляет количество, visibility и modifier. | Оставьте ровно один `#[Handler]` на одном public non-static методе. |
| `MBR003` | Handler имеет ноль или больше двух параметров; в `actual` указано точное количество. | Объявите один параметр сообщения и, только если нужен, один параметр контекста. |
| `MBR004` | Тип первого параметра отсутствует, является built-in/union/intersection, не загружается или не реализует `Message`; в `actual` указан объявленный reflection type. | Используйте один именованный, загружаемый Composer класс ровно одной роли сообщения. |
| `MBR005` | Второй параметр не является одним именованным загружаемым наследником `MessageContext`; в `actual` указан тип. | Типизируйте его нужным подклассом `MessageContext`. |
| `MBR006` | Для корректного класса контекста нет exact factory в `MessageContextFactoryResolver`; `symbol` содержит класс. | Зарегистрируйте factory именно для этого класса; родитель или соседний тип не подходит. |
| `MBR007` | Handler для Command или Event не объявляет `void` явно; `actual` содержит тип или `not declared`. | Добавьте явный `: void`. |
| `MBR008` | У handler для Query или Request отсутствует return type. | Объявите тип результата, обещанный контрактом сообщения. |
| `MBR009` | Handler для Query явно возвращает `void`. | Объявите не-`void` тип, соответствующий `Query<TResult>`. |
| `MBR010` | У Event handler `subscriptionId` равен null или пустой строке; `symbol` содержит метод. | Задайте стабильный `#[Handler('subscription.id')]`. |
| `MBR011` | Не-Event handler объявляет Event-only `subscriptionId`; `actual` содержит этот ID. | Уберите аргумент: независимые ID есть только у Event subscriptions. |
| `MBR012` | У durable Command или Event количество `#[MessageName]` отлично от одного; `actual` содержит количество. | Поместите ровно один корректный `#[MessageName('wire.name')]` на класс сообщения. |
| `MBR013` | Сообщение не реализует ни одной либо реализует несколько ролей из `Query`, `Request`, `Command`, `Event`; `actual` перечисляет их. | Реализуйте ровно один role interface. |
| `MBR014` | У Query, Request или Command нет ровно одного compiled handler; `symbol` содержит класс, `actual` — количество. | Сделайте доступным ровно один корректный handler. Сначала исправьте более ранние diagnostics handler. |
| `MBR015` | У двух bindings одного сообщения совпал ID; `actual` перечисляет дубли. | Задайте Event subscriptions уникальные ID; у остальных сообщений должен остаться один handler. |
| `MBR016` | Два durable-класса используют один `MessageName`; `symbol` содержит wire name, `actual` — оба класса. | Назначьте каждому durable message уникальное стабильное wire name. |
| `MBR017` | Конструктор `#[Handler]` выбросил исключение, обычно из-за grammar `subscriptionId`; `cause_class` сохраняет тип. | Соблюдайте `[a-z0-9][a-z0-9._:-]{2,127}`. |
| `MBR018` | Параметр handler является optional или variadic; `actual` называет параметр и нарушение. | Сделайте все параметры обязательными и не variadic. |
| `MBR019` | Первый параметр реализует `Message`, но тип нельзя инстанцировать, например interface/abstract class; `actual` содержит тип. | Обрабатывайте конкретный класс сообщения. |
| `MBR020` | Конструктор `#[MessageName]` выбросил исключение; `symbol` содержит durable-класс, `cause_class` — тип. | Соблюдайте `[a-z][a-z0-9._:-]{2,127}`. |

## Runtime-конфигурация (`MBC`)

Эти коды создаёт `RuntimeConfigurationRule`. Поля `symbol` и `message` называют точный compiled target.

| Код | Точное условие | Исправление |
| --- | --- | --- |
| `MBC001` | Для Query или Request задан route, хотя роль исполняется inline; `actual` содержит mode, policy и lane. | Удалите неиспользуемый `DeliveryRoute`. |
| `MBC002` | Для target Command или Event отсутствует route. | Настройте один route для target ID из `symbol`. |
| `MBC003` | Route команды использует `inline` или `test_inline`; `actual` содержит mode. | Установите mode `durable`. |
| `MBC004` | Durable route ссылается на policy, которой нет в `DeliveryPolicies`; `actual` содержит имя. | Зарегистрируйте эту policy или исправьте route. |
| `MBC005` | Idempotent policy используется без atomic boundary `IdempotencyRuntime`. | Добавьте `IdempotencyRuntime` в композицию durable runtime. |
| `MBC006` | `retryLostAttempts=true`, но `idempotent=false`. | Включите idempotency с корректной atomic boundary либо отключите retry. |
| `MBC007` | Identity resolver ссылается на target, которого нет в compiled registry. | Удалите/исправьте ID либо обеспечьте discovery нужного target. |
| `MBC008` | У idempotent target нет resolver для его точного target ID. | Зарегистрируйте target-scoped resolver для `symbol`. |

## Runtime failure descriptors (`MBD`)

`FailureDiagnostic` — безопасный descriptor, который хранится в status и передаётся с runtime events. `summary` начинается со стабильного кода; отдельно доступны `stage`, `failure_class`, необязательный `related_failure_class` и `fingerprint`. Произвольные exception message, trace, payload и secrets исключены. Исходный throwable сохраняется в синхронной exception chain и передаётся в `RuntimeFailureSink`, когда эта граница доступна.

| Код / stage | Что сломалось | Что проверить |
| --- | --- | --- |
| `MBD001` / `dispatch` | Dispatch завершился ошибкой до возврата результата. | `failure_class`, класс/ID сообщения, correlation ID, target ID и thrown exception. |
| `MBD101` / `payload_decode` | Persisted payload не декодировался в класс сообщения. | Codec/name compatibility, payload version, delivery/message IDs, `failure_class`. |
| `MBD102` / `target_resolution` | Persisted target отсутствует в compiled registry или несовместим. | Target ID, registry/build fingerprint, deployment compatibility, `failure_class`. |
| `MBD103` / `context_creation` | Factory контекста handler завершилась ошибкой. | Регистрацию handler/context, delivery/attempt IDs, `failure_class`. |
| `MBD104` / `handler_resolution` | DI container не смог получить compiled handler service. | Target/handler class и исходное container exception; библиотека не заменяет его общей handler error. |
| `MBD105` / `handler_execution` | Полученный handler выбросил исключение при обработке. | Target, message/delivery/attempt IDs, `failure_class`, исходное handler exception. |
| `MBD106` / `idempotency` | Упал identity resolver или atomic idempotency boundary. | Target/attempt IDs, настройки resolver/boundary, `failure_class`. |
| `MBD107` / `failure_classification` | Application classifier упал при обработке другой ошибки. | `failure_class` classifier и `related_failure_class` исходной ошибки. |
| `MBD108` / `delivery_settlement` | Сохранение failed-delivery outcome тоже завершилось ошибкой. | Settlement `failure_class`, исходный `related_failure_class`, delivery/attempt IDs. |
| `MBD109` / `worker_fork` | Supervisor не смог создать дочерний worker process. | Метаданные worker/profile и `failure_class`. |
| `MBD110` / `lease_recovery` | Recovery обнаружил, что worker больше не владеет попыткой. | Delivery/attempt IDs, lease timing, worker identity, backend state. |
| `MBD111` / `delivery_acceptance` | Durable backend не принял delivery для Command или Event. | Message/target IDs, backend `failure_class`, поля `CommandNotAccepted`. |

Descriptors schema 1, записанные до расширения summary, остаются читаемыми: decoder проверяет стабильные code и fingerprint и заменяет старый display summary текущим каноническим текстом.

## Ошибки CLI (`MBCLI`)

Каждый error object содержит `code`, `type`, `stage`, `message`, `exception_type` и необязательный `subject`. В message повторяются code, stage, subject type/ID и exception class. Произвольный exception message туда не копируется.

| Код / type | Значение | Следующая проверка |
| --- | --- | --- |
| `MBCLI001` / `invalid_input` | Option команды или создаваемое value не прошло validation. | Используйте `stage`, `subject` и command help для поиска допустимой grammar. |
| `MBCLI002` / `state_conflict` | Mutation конфликтует с retained state или revision. | Снова прочитайте status/revision и повторяйте только допустимую операцию. |
| `MBCLI003` / `backend_failure` | PDO-backed операция завершилась ошибкой. | Найдите запись по `stage`/`subject`, затем проверьте защищённые backend logs. |
| `MBCLI004` / `internal_failure` | Вышла неожиданная ошибка не из input/state/PDO. | Используйте `stage`, `subject`, `exception_type` и защищённые logs. |

## Composer discovery и неправильно размещённые классы

Реестр может диагностировать только классы, которые вернул `RegistryClassSource`:

1. Явно переданный, но не autoloadable класс даёт `MBR001` с точным именем.
2. Discovered handler с первым параметром, который не является loadable message class, даёт `MBR004` с `Class::method` и declared type.
3. Discovered Query, Request или Command, handler которого отсутствует в classmap, даёт `MBR014` и `actual: 0 compiled handler(s)`.
4. Если и message, и handler находятся вне всех Composer mappings, источник не видит ни одного класса и реестр не может сообщить о них.

Четвёртый случай нужно ловить на границе autoload:

```bash
composer dump-autoload --optimize --strict-psr
```

Также проверьте, что `ComposerClassMapSource` получает созданный classmap и нужный namespace prefix. После исправления autoload metadata перестройте registry artifact.

Diagnostics выдаются в детерминированном порядке классов и методов. Исправляйте раннюю ошибку signature/attribute до последующей cardinality error вроде `MBR014`: отсутствие binding может быть следствием первого отказа.

Точные signatures перечислены в [публичном API](public-api.md); при конфликте исполняемые source и compatibility catalogs нормативны.
