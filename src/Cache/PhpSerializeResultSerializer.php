<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cache;

use InvalidArgumentException;

final class PhpSerializeResultSerializer implements ResultSerializerInterface
{
    public const CONTENT_TYPE = 'application/vnd.php.serialized';

    /**
     * @param bool|array<class-string> $allowedClasses
     */
    public function __construct(private readonly bool|array $allowedClasses = true)
    {
    }

    public function serialize(mixed $result): SerializedResult
    {
        return new SerializedResult(
            self::CONTENT_TYPE,
            \serialize($result),
            \is_object($result) ? $result::class : null,
        );
    }

    public function deserialize(SerializedResult $result): mixed
    {
        if ($result->contentType !== self::CONTENT_TYPE) {
            throw new InvalidArgumentException('Only application/vnd.php.serialized cached results are supported.');
        }

        $value = @\unserialize($result->payload, ['allowed_classes' => $this->allowedClasses]);
        if ($value === false && $result->payload !== 'b:0;') {
            throw new InvalidArgumentException('PHP serialized cached result payload is invalid.');
        }

        if ($result->className !== null && (!$value instanceof $result->className)) {
            throw new InvalidArgumentException(\sprintf(
                'PHP serialized cached result must contain instance of `%s`, got `%s`.',
                $result->className,
                \is_object($value) ? $value::class : \get_debug_type($value),
            ));
        }

        return $value;
    }
}
