<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Serialization\CompositeMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\ContentTypeAwareMessageSerializerInterface;
use Wolfcharaa\MessageBus\Serialization\JsonMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\MessageNameResolverInterface;
use Wolfcharaa\MessageBus\Serialization\PhpSerializeMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class CompositeMessageSerializerTest extends TestCase
{
    public function testSerializesConfiguredContentTypeAndBase64Encoding(): void
    {
        $serializer = $this->serializer(
            writeContentType: PhpSerializeMessageSerializer::CONTENT_TYPE,
            writePayloadEncoding: SerializedMessage::PAYLOAD_ENCODING_BASE64,
        );
        $message = new CompositePhpFixtureMessage('42', new DateTimeImmutable('2026-09-11 10:00:00+03:00'));

        $serialized = $serializer->serialize($message);

        self::assertSame('composite.php.fixture', $serialized->name);
        self::assertSame(PhpSerializeMessageSerializer::CONTENT_TYPE, $serialized->contentType);
        self::assertSame(SerializedMessage::PAYLOAD_ENCODING_BASE64, $serialized->payloadEncoding);
        self::assertSame(\serialize($message), \base64_decode($serialized->payload, true));
        self::assertEquals($message, $serializer->deserialize($serialized));
    }

    public function testDeserializesLegacyJsonPayload(): void
    {
        $resolver = new CompositeMessageNameResolver();
        $legacy = (new JsonMessageSerializer($resolver))->serialize(new CompositePortableFixtureMessage('55', ['source' => 'json']));

        $message = $this->serializer()->deserialize($legacy);

        self::assertEquals(new CompositePortableFixtureMessage('55', ['source' => 'json']), $message);
    }

    public function testDeserializesPlainPhpPayload(): void
    {
        $resolver = new CompositeMessageNameResolver();
        $plain = (new PhpSerializeMessageSerializer($resolver))->serialize(
            new CompositePhpFixtureMessage('77', new DateTimeImmutable('2026-09-11 11:00:00+03:00')),
        );

        $message = $this->serializer()->deserialize($plain);

        self::assertEquals(new CompositePhpFixtureMessage('77', new DateTimeImmutable('2026-09-11 11:00:00+03:00')), $message);
    }

    public function testCanWriteJsonForRollback(): void
    {
        $serializer = $this->serializer(
            writeContentType: JsonMessageSerializer::CONTENT_TYPE,
            writePayloadEncoding: SerializedMessage::PAYLOAD_ENCODING_PLAIN,
        );

        $serialized = $serializer->serialize(new CompositePortableFixtureMessage('88', ['mode' => 'rollback']));

        self::assertSame(JsonMessageSerializer::CONTENT_TYPE, $serialized->contentType);
        self::assertSame(SerializedMessage::PAYLOAD_ENCODING_PLAIN, $serialized->payloadEncoding);
        self::assertSame('{"id":"88","payload":{"mode":"rollback"}}', $serialized->payload);
        self::assertEquals(new CompositePortableFixtureMessage('88', ['mode' => 'rollback']), $serializer->deserialize($serialized));
    }

    public function testRejectsUnsupportedContentType(): void
    {
        $serializer = $this->serializer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content type `application/xml` is not supported by composite message serializer.');

        $serializer->deserialize(new SerializedMessage('composite.portable.fixture', 'application/xml', '<xml />'));
    }

    public function testRejectsInvalidBase64Payload(): void
    {
        $serializer = $this->serializer();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message `composite.php.fixture` has invalid base64 payload encoding.');

        $serializer->deserialize(new SerializedMessage(
            'composite.php.fixture',
            PhpSerializeMessageSerializer::CONTENT_TYPE,
            '%%%',
            payloadEncoding: SerializedMessage::PAYLOAD_ENCODING_BASE64,
        ));
    }

    public function testRejectsDuplicateContentType(): void
    {
        $json = new JsonMessageSerializer(new CompositeMessageNameResolver());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message serializer content type `application/json` is registered more than once.');

        new CompositeMessageSerializer([$json, $json]);
    }

    public function testRejectsEmptySerializerList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Composite message serializer requires at least one serializer.');

        new CompositeMessageSerializer([]);
    }

    public function testRejectsWriteContentTypeMissingFromComposite(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message serializer write content type `application/xml` is not supported.');

        $this->serializer(writeContentType: 'application/xml');
    }

    public function testRejectsSerializerThatReturnsDifferentWriteContentType(): void
    {
        $serializer = new CompositeMessageSerializer(
            [new CompositeWrongWriteContentTypeSerializer()],
            writeContentType: 'application/expected',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Message serializer for `application/expected` returned payload with content type `application/actual`.');

        $serializer->serialize(new CompositePortableFixtureMessage('99', []));
    }

    private function serializer(
        ?string $writeContentType = null,
        string $writePayloadEncoding = SerializedMessage::PAYLOAD_ENCODING_PLAIN,
    ): CompositeMessageSerializer {
        $resolver = new CompositeMessageNameResolver();

        return new CompositeMessageSerializer(
            [
                new JsonMessageSerializer($resolver),
                new PhpSerializeMessageSerializer($resolver),
            ],
            $writeContentType,
            $writePayloadEncoding,
        );
    }
}

final class CompositePortableFixtureMessage
{
    /**
     * @param array<string, string> $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly array $payload,
    ) {
    }
}

final class CompositePhpFixtureMessage
{
    public function __construct(
        public readonly string $id,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}

final class CompositeMessageNameResolver implements MessageNameResolverInterface
{
    public function nameOf(object|string $message): string
    {
        $class = \is_object($message) ? $message::class : $message;

        return match ($class) {
            CompositePortableFixtureMessage::class => 'composite.portable.fixture',
            CompositePhpFixtureMessage::class => 'composite.php.fixture',
            default => throw new InvalidArgumentException(\sprintf('Message class `%s` is not supported.', $class)),
        };
    }

    public function classOf(string $name): string
    {
        return match ($name) {
            'composite.portable.fixture' => CompositePortableFixtureMessage::class,
            'composite.php.fixture' => CompositePhpFixtureMessage::class,
            default => throw new InvalidArgumentException(\sprintf('Message name `%s` is not supported.', $name)),
        };
    }
}

final class CompositeWrongWriteContentTypeSerializer implements ContentTypeAwareMessageSerializerInterface
{
    public function serialize(object $message): SerializedMessage
    {
        return new SerializedMessage('composite.portable.fixture', 'application/actual', '{}');
    }

    public function deserialize(SerializedMessage $message): object
    {
        return new CompositePortableFixtureMessage('unused', []);
    }

    public function supportedContentTypes(): array
    {
        return ['application/expected'];
    }

    public function supportsContentType(string $contentType): bool
    {
        return $contentType === 'application/expected';
    }
}
