<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Idempotency;

use BackedEnum;
use Wolfcharaa\MessageBus\Flow\Contract\FlowContract;
use Wolfcharaa\MessageBus\Registry\HandlerKind;

final class IdempotencyFlowContract
{
    private function __construct()
    {
    }

    public static function requiresIdempotencyKey(string|BackedEnum $flow): FlowContract
    {
        return FlowContract::forFlow($flow)
            ->requiresStableBindingId()
            ->requiresBindingOwner()
            ->requiresRole(IdempotencyMiddlewareRole::Guard)
            ->requiresPolicy(ResolvedIdempotencyPolicyRegistryInterface::class)
            ->allowsHandlerKinds(HandlerKind::Command, HandlerKind::Event);
    }
}
