<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Dumper;

use Wolfcharaa\MessageBus\Registry\MessageRegistryDefinition;

interface CompiledRegistryDumperInterface
{
    public function dump(MessageRegistryDefinition $definition): string;
}
