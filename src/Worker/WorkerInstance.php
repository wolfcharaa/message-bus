<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use DateTimeImmutable;

final class WorkerInstance
{
    public function __construct(
        public readonly WorkerIdentity $identity,
        public readonly WorkerLifecycleState $state,
        public readonly WorkerActivityState $activity,
        public readonly DateTimeImmutable $heartbeatAt,
        public readonly int $childrenCount = 0,
        public readonly ?string $lastCommandId = null,
        public readonly ?DateTimeImmutable $lastCommandAt = null,
        public readonly ?DateTimeImmutable $stoppedAt = null,
        public readonly ?string $failureMessage = null,
    ) {
    }
}
