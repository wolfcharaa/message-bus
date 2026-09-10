<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Serialization\JsonMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\MessageNameResolverInterface;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class JsonMessageSerializerTest extends TestCase
{
    public function testSerializeAndDeserializePortablePayload(): void
    {
        $serializer = new JsonMessageSerializer(new JsonSerializerNameResolver());
        $message = new JsonSerializerFixtureMessage('42', ['district' => 'north'], null);

        $serialized = $serializer->serialize($message);

        self::assertSame('json.fixture', $serialized->name);
        self::assertSame(JsonMessageSerializer::CONTENT_TYPE, $serialized->contentType);
        self::assertSame('{"id":"42","payload":{"district":"north"},"optional":null}', $serialized->payload);
        self::assertEquals($message, $serializer->deserialize($serialized));
        self::assertSame([JsonMessageSerializer::CONTENT_TYPE], $serializer->supportedContentTypes());
        self::assertTrue($serializer->supportsContentType(JsonMessageSerializer::CONTENT_TYPE));
        self::assertFalse($serializer->supportsContentType('application/vnd.php.serialized'));
    }

    public function testSerializeRejectsNestedObjects(): void
    {
        $serializer = new JsonMessageSerializer(new JsonSerializerNameResolver());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message payload supports only scalar, array and null values.');

        $serializer->serialize(new JsonSerializerNonPortableMessage(new \stdClass()));
    }

    public function testDeserializeRejectsUnsupportedContentType(): void
    {
        $serializer = new JsonMessageSerializer(new JsonSerializerNameResolver());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content type `application/xml` is not supported by JSON message serializer.');

        $serializer->deserialize(new SerializedMessage('json.fixture', 'application/xml', '{}'));
    }

    public function testDeserializeRejectsScalarPayload(): void
    {
        $serializer = new JsonMessageSerializer(new JsonSerializerNameResolver());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JSON message payload must decode to array.');

        $serializer->deserialize(new SerializedMessage('json.fixture', JsonMessageSerializer::CONTENT_TYPE, '"scalar"'));
    }

    public function testSerializedMessageRejectsUnsupportedPayloadEncoding(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Payload encoding `gzip` is not supported.');

        new SerializedMessage('json.fixture', JsonMessageSerializer::CONTENT_TYPE, '{}', payloadEncoding: 'gzip');
    }
}

final class JsonSerializerFixtureMessage
{
    /**
     * @param array<string, string> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly array $payload,
        public readonly ?string $optional,
    ) {
    }
}

final class JsonSerializerNonPortableMessage
{
    public function __construct(public readonly object $payload)
    {
    }
}

final class JsonSerializerNameResolver implements MessageNameResolverInterface
{
    public function nameOf(object|string $message): string
    {
        return 'json.fixture';
    }

    public function classOf(string $name): string
    {
        return JsonSerializerFixtureMessage::class;
    }
}
