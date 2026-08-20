<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Context;

interface CancellableMessageContextInterface extends MessageContextInterface
{
    public function isCancellationRequested(): bool;

    public function throwIfCancellationRequested(): void;
}
