<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Serialization;

interface MessageSerializerInterface
{
    public function serialize(object $message): SerializedMessage;

    public function deserialize(SerializedMessage $message): object;
}
