<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use DateTimeImmutable;
use DateTimeZone;
use Wolfcharaa\MessageBus\Queue\QueueJobControlInterface;

final class QueueJobWorkerRuntimeControl implements WorkerRuntimeControlInterface
{
    public function __construct(
        private readonly string $queueMessageId,
        private readonly ?QueueJobControlInterface $queueControl = null,
        private readonly ?WorkerRegistryInterface $workerRegistry = null,
        private readonly ?string $childInstanceId = null,
    ) {
    }

    public function heartbeat(): void
    {
        $this->queueControl?->heartbeat($this->queueMessageId);

        if ($this->workerRegistry !== null && $this->childInstanceId !== null) {
            $this->workerRegistry->heartbeatChild(
                $this->childInstanceId,
                WorkerChildState::Running,
                $this->now(),
            );
        }
    }

    public function isCancellationRequested(): bool
    {
        return $this->queueControl?->isCancellationRequested($this->queueMessageId) ?? false;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
