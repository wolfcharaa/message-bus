<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use DateTimeImmutable;

final class WorkerControlAcknowledgement
{
    public function __construct(
        public readonly string $commandId,
        public readonly string $workerInstanceId,
        public readonly WorkerControlAcknowledgementState $state,
        public readonly DateTimeImmutable $acknowledgedAt,
        public readonly ?string $message = null,
        public readonly ?string $errorClass = null,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
