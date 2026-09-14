<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Flow\Contract;

use Wolfcharaa\MessageBus\Registry\Metadata\RegistryOwner;

interface OwnedBindingPolicyRegistryInterface extends BindingPolicyRegistryInterface
{
    public function ownerForBinding(string $bindingId): ?RegistryOwner;
}
