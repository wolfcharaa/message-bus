<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Flow\Contract;

interface BindingPolicyRegistryInterface
{
    public function hasForBinding(string $bindingId): bool;
}
