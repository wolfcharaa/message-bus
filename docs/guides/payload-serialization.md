# Payload serialization

Serialization нужна не для обычного sync `dispatch()`. Sync handler получает PHP object напрямую.

Serialization нужна там, где message пересекает границу процесса или времени:

- async queue;
- worker после другого deploy-а;
- retry отложенной задачи;
- внешние producers/consumers;
- сохранение envelope в PostgreSQL;
- диагностика serialized jobs.

MessageBus разделяет три уровня:

- `MessageSerializerInterface` превращает PHP message object в `SerializedMessage`.
- `EnvelopeSerializerInterface` превращает `Envelope` в `SerializedEnvelope`.
- Queue storage сохраняет `SerializedEnvelope` в backend, например PostgreSQL.

## SerializedMessage

`SerializedMessage` хранит не PHP object, а переносимое представление message:

```php
new SerializedMessage(
    name: 'user.created',
    contentType: 'application/json',
    payload: '{"userId":10}',
    headers: [],
    payloadEncoding: SerializedMessage::PAYLOAD_ENCODING_PLAIN,
);
```

Поля:

- `name` - стабильное имя message, обычно из `MessageAlias`.
- `contentType` - формат payload.
- `payload` - строка с данными.
- `headers` - metadata serializer-а.
- `payloadEncoding` - как payload строка положена в envelope.

Важно: `contentType` и `payloadEncoding` отвечают за разные вещи.

`contentType` говорит, как интерпретировать payload:

- `application/json`;
- `application/vnd.php.serialized`;
- `application/x-protobuf`;
- любой custom media type.

`payloadEncoding` говорит, как payload физически записан в envelope:

- `plain` - payload уже безопасная строка.
- `base64` - payload был binary и перед сохранением закодирован в base64.

## JSON serializer по умолчанию

`JsonMessageSerializer` используется по умолчанию.

Он подходит, когда message payload должен быть переносимым:

- между PHP process-ами;
- между разными версиями приложения;
- между backend и внешними consumers;
- между разными языками программирования.

JSON serializer хранит payload как `application/json`.

```json
{
  "message": {
    "name": "user.created",
    "contentType": "application/json",
    "payload": "{\"userId\":10}",
    "payloadEncoding": "plain"
  }
}
```

Ограничение JSON serializer-а: message должен раскладываться в простые данные.

Подходят:

- `string`;
- `int`;
- `float`;
- `bool`;
- `array`;
- `null`;
- простые DTO, которые можно восстановить через constructor.

Не подходят без custom serializer-а:

- `DateTimeImmutable` как object property;
- enum object property;
- value objects без ручного преобразования;
- resources;
- closures;
- binary data.

Практическое правило: если message может уйти за пределы PHP-приложения, начинайте с JSON.

## PHP serialize serializer

Если проект PHP-only и нужно сохранить richer PHP object graph, используйте `PhpSerializeMessageSerializer`.

```php
use Wolfcharaa\MessageBus\Envelope\DefaultEnvelopeSerializer;
use Wolfcharaa\MessageBus\Serialization\PhpSerializeMessageSerializer;

$messageSerializer = new PhpSerializeMessageSerializer(
    $registry,
    allowedClasses: true,
);

$envelopeSerializer = new DefaultEnvelopeSerializer($messageSerializer);
```

После этого serializer можно передать в runtime:

```php
$runtime = MessageBusRuntime::postgres(
    pdo: $pdo,
    registry: $registry,
    container: $container,
    flows: $flows,
    envelopeSerializer: $envelopeSerializer,
);
```

`PhpSerializeMessageSerializer` хранит payload как `application/vnd.php.serialized`.

Плюсы:

- сохраняет PHP value objects;
- сохраняет `DateTimeImmutable`;
- сохраняет enum properties;
- удобен для PHP-only monolith/service;
- не требует писать mapping для каждого DTO.

Минусы:

- payload понятен только PHP;
- class names становятся частью serialized payload;
- refactoring class structure требует аккуратности;
- нельзя безопасно принимать такой payload от недоверенных внешних producers.

Для безопасности можно ограничить allowed classes:

```php
$messageSerializer = new PhpSerializeMessageSerializer(
    $registry,
    allowedClasses: [
        App\Message\CreateOrder::class,
        App\Message\OrderPaidEvent::class,
        App\ValueObject\OrderId::class,
    ],
);
```

Практическое правило: `allowedClasses: true` допустим внутри доверенного приложения. Для публичных boundaries лучше использовать allow-list или JSON/custom serializer.

## Protobuf и binary payload

Библиотека не добавляет built-in protobuf serializer, потому что protobuf schema, generated classes и mapping в каждом проекте свои.

Но библиотека не блокирует protobuf. Нужно реализовать свой `MessageSerializerInterface`.

Идея serializer-а:

```php
use Wolfcharaa\MessageBus\Serialization\MessageSerializerInterface;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class ProtobufMessageSerializer implements MessageSerializerInterface
{
    public function serialize(object $message): SerializedMessage
    {
        $binary = $message->serializeToString();

        return new SerializedMessage(
            name: $this->names->nameOf($message),
            contentType: 'application/x-protobuf',
            payload: base64_encode($binary),
            payloadEncoding: SerializedMessage::PAYLOAD_ENCODING_BASE64,
        );
    }

    public function deserialize(SerializedMessage $message): object
    {
        $binary = base64_decode($message->payload, true);

        if ($binary === false) {
            throw new InvalidArgumentException('Invalid protobuf payload encoding.');
        }

        $class = $this->names->classOf($message->name);
        $object = new $class();
        $object->mergeFromString($binary);

        return $object;
    }
}
```

Почему нужен `base64`: serialized envelope хранится как JSON/document-like структура, а raw binary небезопасно класть прямо в JSON string.

## Как выбрать serializer

Используйте JSON, если:

- payload должен быть читаемым;
- возможны внешние consumers;
- важна переносимость между языками;
- message DTO простые;
- вы хотите меньше рисков при refactoring-е PHP classes.

Используйте PHP serialize, если:

- приложение полностью PHP-only;
- очередь не читается внешними consumers;
- payload содержит PHP value objects;
- вы контролируете producers и consumers;
- скорость разработки важнее cross-language переносимости.

Используйте custom serializer, если:

- нужен protobuf;
- нужен Avro/MessagePack/другой формат;
- есть legacy payload format;
- нужно сохранить строгую backward-compatible wire schema;
- message class не совпадает один-в-один с wire payload.

## Result serialization отдельно

Message payload serialization и cache result serialization - разные вещи.

Для queued messages используются:

- `JsonMessageSerializer`;
- `PhpSerializeMessageSerializer`;
- custom `MessageSerializerInterface`.

Для `MessageCacheMiddleware` используются:

- `JsonResultSerializer`;
- `PhpSerializeResultSerializer`;
- custom `ResultSerializerInterface`.

Это разделение нужно, потому что message и handler result имеют разные lifecycle и разные требования к совместимости.
