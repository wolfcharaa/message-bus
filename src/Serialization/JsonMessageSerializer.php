<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Serialization;

use InvalidArgumentException;

final class JsonMessageSerializer implements MessageSerializerInterface
{
    public const CONTENT_TYPE = 'application/json';

    public function __construct(private readonly MessageNameResolverInterface $nameResolver)
    {
    }

    public function serialize(object $message): SerializedMessage
    {
        $payload = \get_object_vars($message);
        self::assertPortable($payload);

        return new SerializedMessage(
            $this->nameResolver->nameOf($message),
            self::CONTENT_TYPE,
            \json_encode($payload, JSON_THROW_ON_ERROR),
        );
    }

    public function deserialize(SerializedMessage $message): object
    {
        if ($message->contentType !== self::CONTENT_TYPE) {
            throw new InvalidArgumentException(\sprintf(
                'Content type `%s` is not supported by JSON message serializer.',
                $message->contentType,
            ));
        }

        $payload = \json_decode($message->payload, true, 512, JSON_THROW_ON_ERROR);

        if (!\is_array($payload)) {
            throw new InvalidArgumentException('JSON message payload must decode to array.');
        }

        self::assertPortable($payload);
        $class = $this->nameResolver->classOf($message->name);

        return new $class(...$payload);
    }

    private static function assertPortable(mixed $value): void
    {
        if ($value === null || \is_scalar($value)) {
            return;
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                self::assertPortable($item);
            }

            return;
        }

        throw new InvalidArgumentException('Message payload supports only scalar, array and null values.');
    }
}
