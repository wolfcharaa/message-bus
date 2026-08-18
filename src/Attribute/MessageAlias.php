<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Attribute;

use Attribute;
use BackedEnum;

#[Attribute(Attribute::TARGET_CLASS)]
final class MessageAlias
{
    public readonly string $name;

    public function __construct(string|BackedEnum $name)
    {
        $this->name = $name instanceof BackedEnum ? (string) $name->value : $name;
    }
}
