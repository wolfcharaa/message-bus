<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cache;

interface ResultSerializerInterface
{
    public function serialize(mixed $result): SerializedResult;

    public function deserialize(SerializedResult $result): mixed;
}
