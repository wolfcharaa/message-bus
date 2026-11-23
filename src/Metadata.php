<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Metadata;

use Wolfcharaa\MessageBus\Identity\CorrelationId;
use Wolfcharaa\MessageBus\Identity\MessageId;

/**
 *  Immutable portable application metadata carried across process boundaries.
 *
 * @api application
 */
final readonly class MessageMetadata
{
    /** @param array<string, bool|float|int|string|null> $application */
    public function __construct(
        public MessageId $messageId,
        public CorrelationId $correlationId,
        public ?MessageId $causationId,
        public \DateTimeImmutable $createdAt,
        public array $application = [],
    ) {
        if (\strlen(json_encode($this->application, JSON_THROW_ON_ERROR)) > 16_384) {
            throw new \InvalidArgumentException('Application message metadata exceeds 16 KiB.');
        }
    }
}

/**
 *  Pure application hook that contributes schema-bounded portable metadata.
 *
 * @api spi
 */
interface MetadataEnricher
{
    /** @return array<string, bool|float|int|string|null> */
    public function enrich(object $message): array;
}

/**
 *  Validates keys allowed in application metadata and rejects system spoofing.
 *
 * @api application
 */
final readonly class MetadataSchema
{
    /** @param list<string> $allowedKeys */
    public function __construct(public array $allowedKeys = []) {}

    /** @param array<string, bool|float|int|string|null> $values */
    public function validate(array $values): void
    {
        foreach ($values as $key => $_) {
            if (!\in_array($key, $this->allowedKeys, true)) {
                throw new \InvalidArgumentException('Application metadata key is not declared: ' . $key);
            }
        }
    }
}
