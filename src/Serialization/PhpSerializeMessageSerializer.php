<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Serialization;

use InvalidArgumentException;

final class PhpSerializeMessageSerializer implements MessageSerializerInterface
{
    public const CONTENT_TYPE = 'application/vnd.php.serialized';

    /**
     * @param bool|array<class-string> $allowedClasses
     */
    public function __construct(
        private readonly MessageNameResolverInterface $nameResolver,
        private readonly bool|array $allowedClasses = true,
    ) {
    }

    public function serialize(object $message): SerializedMessage
    {
        return new SerializedMessage(
            $this->nameResolver->nameOf($message),
            self::CONTENT_TYPE,
            \serialize($message),
            payloadEncoding: SerializedMessage::PAYLOAD_ENCODING_PLAIN,
        );
    }

    public function deserialize(SerializedMessage $message): object
    {
        if ($message->contentType !== self::CONTENT_TYPE) {
            throw new InvalidArgumentException(\sprintf(
                'Content type `%s` is not supported by PHP serialize message serializer.',
                $message->contentType,
            ));
        }

        $value = @\unserialize($message->payload, ['allowed_classes' => $this->allowedClasses]);
        if (!\is_object($value)) {
            throw new InvalidArgumentException('PHP serialized message payload must contain an object.');
        }

        $expectedClass = $this->nameResolver->classOf($message->name);
        if (!$value instanceof $expectedClass) {
            throw new InvalidArgumentException(\sprintf(
                'PHP serialized message `%s` must contain instance of `%s`, got `%s`.',
                $message->name,
                $expectedClass,
                $value::class,
            ));
        }

        return $value;
    }
}
