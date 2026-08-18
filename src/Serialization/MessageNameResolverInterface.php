<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Serialization;

interface MessageNameResolverInterface
{
    public function nameOf(object|string $message): string;

    /** @return class-string */
    public function classOf(string $name): string;
}
