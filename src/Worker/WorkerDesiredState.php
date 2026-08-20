<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use DateTimeImmutable;

final class WorkerDesiredState
{
    public function __construct(
        public readonly string $desiredStateId,
        public readonly WorkerDesiredStateType $type,
        public readonly WorkerTarget $target,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $createdBy = null,
        public readonly string $source = 'unknown',
        public readonly ?string $reason = null,
        public readonly ?string $requestId = null,
        public readonly ?string $correlationId = null,
        public readonly bool $override = false,
        public readonly bool $active = true,
    ) {
    }

    public function matches(WorkerIdentity $identity): bool
    {
        return $this->active && $this->target->matches($identity);
    }

    public function specificityScore(): int
    {
        return $this->target->specificityScore();
    }
}
