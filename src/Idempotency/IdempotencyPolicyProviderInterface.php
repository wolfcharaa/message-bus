<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use Wolfcharaa\MessageBus\Registry\Metadata\RegistryOwner;
use Wolfcharaa\MessageBus\Registry\Metadata\RegistrySource;

interface IdempotencyPolicyProviderInterface
{
    public function owner(): RegistryOwner;

    public function source(): RegistrySource;

    /** @return iterable<IdempotencyPolicyDescriptor> */
    public function policies(): iterable;
}
