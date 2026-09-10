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

## Composite serializer для переходного периода

Если проект меняет wire-format очереди, но в storage уже есть старые jobs, используйте `CompositeMessageSerializer`.

Composite принимает несколько serializer-ов, выбирает reader по `SerializedMessage::contentType`, а writer задаётся явно:

```php
use Wolfcharaa\MessageBus\Envelope\DefaultEnvelopeSerializer;
use Wolfcharaa\MessageBus\Serialization\CompositeMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\JsonMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\PhpSerializeMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

$messageSerializer = new CompositeMessageSerializer(
    serializers: [
        new JsonMessageSerializer($registry),
        new PhpSerializeMessageSerializer($registry, allowedClasses: true),
    ],
    writeContentType: PhpSerializeMessageSerializer::CONTENT_TYPE,
    writePayloadEncoding: SerializedMessage::PAYLOAD_ENCODING_BASE64,
);

$envelopeSerializer = new DefaultEnvelopeSerializer($messageSerializer);
```

Такой serializer будет:

- читать старые `application/json` payload-ы;
- читать новые `application/vnd.php.serialized` payload-ы;
- писать новые jobs в PHP serialize;
- класть payload в envelope как `base64`, если это нужно для переносимости storage/JSON оболочки.

Для rollback можно временно переключить writer обратно на JSON:

```php
$messageSerializer = new CompositeMessageSerializer(
    serializers: [
        new JsonMessageSerializer($registry),
        new PhpSerializeMessageSerializer($registry, allowedClasses: true),
    ],
    writeContentType: JsonMessageSerializer::CONTENT_TYPE,
);
```

Важно: `contentType` выбирает логический serializer, а `payloadEncoding` только описывает физическую упаковку строки payload. Composite перед отдачей во внутренний serializer нормализует `base64` обратно в plain payload.

## Protobuf и binary payload

Библиотека не добавляет built-in protobuf serializer, потому что protobuf schema, generated classes и mapping в каждом проекте свои.

Но библиотека не блокирует protobuf. Нужно реализовать свой `ContentTypeAwareMessageSerializerInterface`, если serializer будет использоваться внутри `CompositeMessageSerializer`, или обычный `MessageSerializerInterface`, если формат один и composite не нужен.

Идея serializer-а:

```php
use Wolfcharaa\MessageBus\Serialization\ContentTypeAwareMessageSerializerInterface;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class ProtobufMessageSerializer implements ContentTypeAwareMessageSerializerInterface
{
    public const CONTENT_TYPE = 'application/x-protobuf';

    public function serialize(object $message): SerializedMessage
    {
        $binary = $message->serializeToString();

        return new SerializedMessage(
            name: $this->names->nameOf($message),
            contentType: self::CONTENT_TYPE,
            payload: base64_encode($binary),
            payloadEncoding: SerializedMessage::PAYLOAD_ENCODING_BASE64,
        );
    }

    public function deserialize(SerializedMessage $message): object
    {
        $binary = match ($message->payloadEncoding) {
            SerializedMessage::PAYLOAD_ENCODING_PLAIN => $message->payload,
            SerializedMessage::PAYLOAD_ENCODING_BASE64 => base64_decode($message->payload, true),
            default => false,
        };

        if ($binary === false) {
            throw new InvalidArgumentException('Invalid protobuf payload encoding.');
        }

        $class = $this->names->classOf($message->name);
        $object = new $class();
        $object->mergeFromString($binary);

        return $object;
    }

    public function supportedContentTypes(): array
    {
        return [self::CONTENT_TYPE];
    }

    public function supportsContentType(string $contentType): bool
    {
        return $contentType === self::CONTENT_TYPE;
    }
}
```

Почему нужен `base64`: serialized envelope хранится как JSON/document-like структура, а raw binary небезопасно класть прямо в JSON string.

Если serializer используется через `CompositeMessageSerializer`, composite перед вызовом reader-а нормализует `payloadEncoding: base64` в plain payload. Поэтому custom serializer, который может работать и напрямую, и через composite, должен принимать оба варианта `payloadEncoding`.

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

Используйте composite serializer, если:

- нужно читать несколько content-type из одной queue;
- проект переходит с JSON на PHP serialize или обратно;
- rollout/rollback должен менять только writer, не ломая worker-ы со старыми jobs;
- нужно подключить custom binary serializer рядом с существующим форматом.

## Result serialization отдельно

Message payload serialization и cache result serialization - разные вещи.

Для queued messages используются:

- `JsonMessageSerializer`;
- `PhpSerializeMessageSerializer`;
- `CompositeMessageSerializer`;
- custom `MessageSerializerInterface`.

Для `MessageCacheMiddleware` используются:

- `JsonResultSerializer`;
- `PhpSerializeResultSerializer`;
- custom `ResultSerializerInterface`.

Это разделение нужно, потому что message и handler result имеют разные lifecycle и разные требования к совместимости.
