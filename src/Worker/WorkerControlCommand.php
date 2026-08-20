<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use DateTimeImmutable;
use InvalidArgumentException;

final class WorkerControlCommand
{
    public function __construct(
        public readonly string $commandId,
        public readonly WorkerControlCommandType $type,
        public readonly WorkerTarget $target,
        public readonly DateTimeImmutable $createdAt,
        public readonly ?string $createdBy = null,
        public readonly string $source = 'unknown',
        public readonly ?string $reason = null,
        public readonly ?string $requestId = null,
        public readonly ?string $correlationId = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?string $idempotencyKey = null,
        public readonly bool $override = false,
    ) {
        if ($this->type->isOneShot() && $this->expiresAt === null) {
            throw new InvalidArgumentException(\sprintf('Worker control command `%s` requires expiresAt.', $this->type->value));
        }
    }

    public static function fromRequest(
        string $commandId,
        WorkerControlCommandType $type,
        WorkerTarget $target,
        DateTimeImmutable $createdAt,
        ?WorkerControlRequest $request = null,
    ): self {
        $request ??= new WorkerControlRequest();

        return new self(
            $commandId,
            $type,
            $target,
            $createdAt,
            $request->createdBy,
            $request->source,
            $request->reason,
            $request->requestId,
            $request->correlationId,
            $request->expiresAt,
            $request->idempotencyKey,
            $request->override,
        );
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
