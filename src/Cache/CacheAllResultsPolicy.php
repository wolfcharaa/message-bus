<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cache;

use Wolfcharaa\MessageBus\Context\MessageContextInterface;

final class CacheAllResultsPolicy implements CacheResultPolicyInterface
{
    public function shouldCache(MessageContextInterface $context, CachePolicy $policy, mixed $result): bool
    {
        return true;
    }
}
