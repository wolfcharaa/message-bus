<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus;

final class PublishFailure
{
    public function __construct(
        public readonly string $messageId,
        public readonly string $correlationId,
        public readonly string $flow,
        public readonly string $bindingId,
        public readonly string $transport,
        public readonly string $queue,
        public readonly string $errorClass,
        public readonly string $errorMessage,
        public readonly int $errorCode = 0,
    ) {
    }

    public static function fromThrowable(
        \Throwable $error,
        string $messageId,
        string $correlationId,
        string $flow,
        string $bindingId,
        string $transport,
        string $queue,
    ): self {
        return new self(
            $messageId,
            $correlationId,
            $flow,
            $bindingId,
            $transport,
            $queue,
            $error::class,
            $error->getMessage(),
            $error->getCode(),
        );
    }
}
