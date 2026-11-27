<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Receipt;

use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\MessageId;

/**
 *  Initial observable outcome of one subscription or Command target.
 *
 * @api application
 */
enum PublicationOutcome: string
{
    case Completed = 'completed';
    case Staged = 'staged';
    case Accepted = 'accepted';
    case TestInlineCompleted = 'test_inline_completed';
    case Failed = 'failed';
}

/**
 *  Immutable initial outcome of one target at dispatch time.
 *
 * @api application
 */
final readonly class PublicationEntry
{
    public function __construct(
        public string $targetId,
        public PublicationOutcome $outcome,
        public ?DeliveryId $deliveryId = null,
        public ?string $failureCategory = null,
        public ?string $failureSummary = null,
        public ?string $failureClass = null,
    ) {
        if ($this->targetId === '') {
            throw new \InvalidArgumentException('Publication target ID must not be empty.');
        }
        $durable = $this->outcome === PublicationOutcome::Staged
            || $this->outcome === PublicationOutcome::Accepted;
        if ($durable !== ($this->deliveryId instanceof DeliveryId)) {
            throw new \InvalidArgumentException('Only durable publication outcomes carry DeliveryId.');
        }
        if ($this->outcome !== PublicationOutcome::Failed
            && ($this->failureCategory !== null || $this->failureSummary !== null || $this->failureClass !== null)) {
            throw new \InvalidArgumentException('Only failed publication outcomes carry failure details.');
        }
        if ($this->outcome === PublicationOutcome::Failed
            && ($this->failureCategory === null || $this->failureSummary === null || $this->failureClass === null)) {
            throw new \InvalidArgumentException(
                'Failed publication outcomes require failureCategory, failureSummary and failureClass.',
            );
        }
    }
}

/**
 *  Historical receipt proving initial Command staging or acceptance only.
 *
 * @api application
 */
final readonly class CommandReceipt
{
    public function __construct(
        public MessageId $messageId,
        public PublicationEntry $entry,
    ) {}
}

/**
 *  Aggregate Event publication receipt including every independent target.
 *
 * @api application
 */
final readonly class PublicationReceipt
{
    /** @param list<PublicationEntry> $entries */
    public function __construct(
        public MessageId $messageId,
        public array $entries,
    ) {
        $targets = array_map(
            static fn(PublicationEntry $entry): string => $entry->targetId,
            $this->entries,
        );
        if (\count($targets) !== \count(array_unique($targets))) {
            throw new \InvalidArgumentException('Publication receipt target IDs must be unique.');
        }
    }

    public function isComplete(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry->outcome === PublicationOutcome::Failed) {
                return false;
            }
        }

        return true;
    }
}
