<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Context;

interface HeartbeatAwareMessageContextInterface extends MessageContextInterface
{
    public function heartbeat(): void;
}
