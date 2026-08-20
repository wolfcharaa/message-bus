<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cache;

use Wolfcharaa\MessageBus\Context\MessageContextInterface;

interface CacheResultPolicyInterface
{
    public function shouldCache(MessageContextInterface $context, CachePolicy $policy, mixed $result): bool;
}
