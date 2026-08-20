<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cache;

use InvalidArgumentException;

final class JsonResultSerializer implements ResultSerializerInterface
{
    public function serialize(mixed $result): SerializedResult
    {
        $class = \is_object($result) ? $result::class : null;
        $payload = \is_object($result) ? \get_object_vars($result) : $result;
        $this->assertPortable($payload);

        return new SerializedResult('application/json', \json_encode($payload, JSON_THROW_ON_ERROR), $class);
    }

    public function deserialize(SerializedResult $result): mixed
    {
        if ($result->contentType !== 'application/json') {
            throw new InvalidArgumentException('Only application/json cached results are supported.');
        }

        $payload = \json_decode($result->payload, true, 512, JSON_THROW_ON_ERROR);
        $this->assertPortable($payload);

        if ($result->className === null) {
            return $payload;
        }

        return new $result->className(...(\is_array($payload) ? $payload : [$payload]));
    }

    private function assertPortable(mixed $value): void
    {
        if ($value === null || \is_scalar($value)) {
            return;
        }

        if (\is_array($value)) {
            foreach ($value as $item) {
                $this->assertPortable($item);
            }

            return;
        }

        throw new InvalidArgumentException('Cached result supports only scalar, array, object DTO and null values.');
    }
}
