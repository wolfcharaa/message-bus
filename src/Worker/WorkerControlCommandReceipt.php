<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use DateTimeImmutable;

final class WorkerControlCommandReceipt
{
    public function __construct(
        public readonly string $commandId,
        public readonly WorkerControlCommandType $type,
        public readonly WorkerTarget $target,
        public readonly DateTimeImmutable $acceptedAt,
        public readonly ?string $idempotencyKey = null,
        public readonly bool $duplicate = false,
    ) {
    }
}
