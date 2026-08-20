<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Serialization\MessageNameResolverInterface;
use Wolfcharaa\MessageBus\Serialization\PhpSerializeMessageSerializer;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class PhpSerializeMessageSerializerTest extends TestCase
{
    public function testSerializeAndDeserializePhpObjectPayload(): void
    {
        $serializer = new PhpSerializeMessageSerializer(new PhpSerializeMessageNameResolver());
        $message = new PhpSerializeFixtureMessage('42', new DateTimeImmutable('2026-08-20 10:00:00+03:00'));

        $serialized = $serializer->serialize($message);

        self::assertSame('php.serialize.fixture', $serialized->name);
        self::assertSame(PhpSerializeMessageSerializer::CONTENT_TYPE, $serialized->contentType);
        self::assertSame(SerializedMessage::PAYLOAD_ENCODING_PLAIN, $serialized->payloadEncoding);
        self::assertEquals($message, $serializer->deserialize($serialized));
    }

    public function testDeserializeRejectsDisallowedClasses(): void
    {
        $serializer = new PhpSerializeMessageSerializer(new PhpSerializeMessageNameResolver(), false);

        $this->expectException(InvalidArgumentException::class);

        $serializer->deserialize(new SerializedMessage(
            'php.serialize.fixture',
            PhpSerializeMessageSerializer::CONTENT_TYPE,
            \serialize(new PhpSerializeFixtureMessage('42', new DateTimeImmutable('2026-08-20 10:00:00+03:00'))),
        ));
    }
}

final class PhpSerializeFixtureMessage
{
    public function __construct(
        public readonly string $id,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}

final class PhpSerializeMessageNameResolver implements MessageNameResolverInterface
{
    public function nameOf(object|string $message): string
    {
        return 'php.serialize.fixture';
    }

    public function classOf(string $name): string
    {
        return PhpSerializeFixtureMessage::class;
    }
}
