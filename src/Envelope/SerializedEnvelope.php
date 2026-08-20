<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Envelope;

use DateTimeImmutable;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class SerializedEnvelope
{
    public const SCHEMA_VERSION = 1;

    public function __construct(
        public readonly SerializedMessage $message,
        /** @var array<string, mixed> */
        public readonly array $headers,
        public readonly string $messageId,
        public readonly ?string $causationId,
        public readonly string $correlationId,
        public readonly string $flow,
        public readonly ?string $bindingId,
        public readonly DateTimeImmutable $createdAt,
        public readonly int $schemaVersion = self::SCHEMA_VERSION,
    ) {
    }
}
