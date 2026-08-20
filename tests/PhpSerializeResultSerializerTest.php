<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Cache\PhpSerializeResultSerializer;

final class PhpSerializeResultSerializerTest extends TestCase
{
    public function testSerializeAndDeserializePhpObjectResult(): void
    {
        $serializer = new PhpSerializeResultSerializer();
        $result = new PhpSerializeResultDto('done', new DateTimeImmutable('2026-08-20T10:00:00+03:00'));

        $serialized = $serializer->serialize($result);

        self::assertSame(PhpSerializeResultSerializer::CONTENT_TYPE, $serialized->contentType);
        self::assertSame(PhpSerializeResultDto::class, $serialized->className);
        self::assertEquals($result, $serializer->deserialize($serialized));
    }

    public function testDeserializeSupportsFalseScalarResult(): void
    {
        $serializer = new PhpSerializeResultSerializer();

        self::assertFalse($serializer->deserialize($serializer->serialize(false)));
    }

    public function testDeserializeRejectsDisallowedClasses(): void
    {
        $serializer = new PhpSerializeResultSerializer(false);

        $this->expectException(InvalidArgumentException::class);

        $serializer->deserialize((new PhpSerializeResultSerializer())->serialize(
            new PhpSerializeResultDto('done', new DateTimeImmutable('2026-08-20T10:00:00+03:00')),
        ));
    }
}

final class PhpSerializeResultDto
{
    public function __construct(
        public readonly string $value,
        public readonly DateTimeImmutable $createdAt,
    ) {
    }
}
