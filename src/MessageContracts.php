<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Contract;

use Wolfcharaa\MessageBus\Receipt\CommandReceipt;
use Wolfcharaa\MessageBus\Receipt\PublicationReceipt;

/**
 * Root role contract for every dispatchable message.
 *
 * @template-covariant TResult
 * @api application
 */
interface Message {}

/**
 *  @template-covariant TResult
 * @extends Message<TResult>
 * @api application
 */
interface Query extends Message {}

/**
 *  @template-covariant TResult
 * @extends Message<TResult>
 * @api application
 */
interface Request extends Message {}

/**
 *  @extends Message<CommandReceipt>
 *
 * @api application
 */
interface Command extends Message {}

/**
 *  @extends Message<PublicationReceipt>
 *
 * @api application
 */
interface Event extends Message {}

/**
 * Single application dispatch boundary for all four message roles.
 *
 * @api application
 */
interface MessageBus
{
    /** @template TResult
     * @param Message<TResult> $message
     * @return TResult
     */
    public function dispatch(Message $message): mixed;
}

namespace Wolfcharaa\MessageBus\Attribute;

/**
 *  Marks one public handler method; Event subscriptions require a stable ID.
 *
 * @api application
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Handler
{
    public function __construct(public ?string $subscriptionId = null)
    {
        if ($this->subscriptionId !== null
            && preg_match('/^[a-z0-9][a-z0-9._:-]{2,127}$/D', $this->subscriptionId) !== 1) {
            throw new \InvalidArgumentException(
                'Handler subscriptionId must match [a-z0-9][a-z0-9._:-]{2,127}.',
            );
        }
    }
}

/**
 *  Declares the canonical durable wire identity of a Command or Event.
 *
 * @api application
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class MessageName
{
    public function __construct(public string $value)
    {
        if (preg_match('/^[a-z][a-z0-9._:-]{2,127}$/D', $this->value) !== 1) {
            throw new \InvalidArgumentException(
                'MessageName value must match [a-z][a-z0-9._:-]{2,127}.',
            );
        }
    }
}

/**
 *  Declares the current payload version and codec identity for durable messages.
 *
 * @api application
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Payload
{
    public function __construct(
        public int $version = 1,
        public string $codec = 'json',
    ) {
        if ($this->version < 1) {
            throw new \InvalidArgumentException('Payload version must be at least 1.');
        }
        if (preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $this->codec) !== 1) {
            throw new \InvalidArgumentException('Payload codec must match [a-z][a-z0-9._-]{1,31}.');
        }
    }
}

namespace Wolfcharaa\MessageBus\Exception;

use Wolfcharaa\MessageBus\Identity\AttemptId;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\MessageId;
use Wolfcharaa\MessageBus\Observability\FailureStage;
use Wolfcharaa\MessageBus\Receipt\PublicationEntry;
use Wolfcharaa\MessageBus\Receipt\PublicationOutcome;
use Wolfcharaa\MessageBus\Receipt\PublicationReceipt;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;

/**
 *  Raised when a Command could not reach durable acceptance.
 *
 * @api application
 */
final class CommandNotAccepted extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?MessageId $messageId = null,
        public readonly ?string $messageName = null,
        public readonly ?string $targetId = null,
        public readonly ?FailureStage $stage = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}

/**
 *  Raised after every Event subscription was attempted and at least one failed.
 *
 * @api application
 */
final class PublicationFailed extends \RuntimeException
{
    /**
     * @param list<\Throwable> $failures original failures in receipt order
     */
    public function __construct(
        public readonly PublicationReceipt $receipt,
        public readonly array $failures = [],
    ) {
        parent::__construct(
            'Event publication ' . $this->receipt->messageId->value . ' failed for targets ['
                . implode(', ', array_map(
                    static fn(PublicationEntry $entry): string => $entry->targetId,
                    array_values(array_filter(
                        $this->receipt->entries,
                        static fn(PublicationEntry $entry): bool => $entry->outcome === PublicationOutcome::Failed,
                    )),
                )) . ']; inspect receipt and failures.',
            0,
            $this->failures[0] ?? null,
        );
    }
}

/**
 *  Explicit terminal classification for a known handler failure.
 *
 * @api application
 */
final class TerminalDeliveryFailure extends \RuntimeException {}

/**
 * Terminal persisted payload/codec/target incompatibility.
 *
 * @api application
 */
final class IncompatibleDeliveryFailure extends \RuntimeException {}

/**
 * Raised when cleanup fails while another throwable is already primary.
 *
 * @api application
 */
final class CleanupFailed extends \RuntimeException
{
    /**
     * @param non-empty-list<\Throwable> $cleanupFailures
     */
    public function __construct(
        public readonly string $operation,
        public readonly \Throwable $primaryFailure,
        public readonly array $cleanupFailures,
    ) {
        if ($this->operation === '' || $this->cleanupFailures === []) {
            throw new \InvalidArgumentException('CleanupFailed requires an operation and at least one cleanup failure.');
        }
        parent::__construct(
            'Cleanup failed during ' . $this->operation . '; inspect primaryFailure and cleanupFailures.',
            0,
            $this->primaryFailure,
        );
    }
}

/**
 * Raised when a delivery failure and its fenced settlement both fail.
 *
 * @api application
 */
final class DeliverySettlementFailed extends \RuntimeException
{
    public function __construct(
        public readonly DeliveryId $deliveryId,
        public readonly AttemptId $attemptId,
        public readonly \Throwable $primaryFailure,
        public readonly \Throwable $settlementFailure,
    ) {
        parent::__construct(
            'Delivery ' . $this->deliveryId->value . ' attempt ' . $this->attemptId->value
                . ' failed and could not be settled; inspect primaryFailure and settlementFailure.',
            0,
            $this->primaryFailure,
        );
    }
}

/**
 *  Stable configuration/registry diagnostic promoted to an exception.
 *
 * @api application
 */
final class InvalidMessageBusConfiguration extends \RuntimeException
{
    /** @param list<RegistryDiagnostic> $diagnostics */
    public function __construct(
        string $message = 'MessageBus configuration is invalid.',
        public readonly array $diagnostics = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
