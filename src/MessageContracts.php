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
            throw new \InvalidArgumentException('Event subscription ID is invalid.');
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
            throw new \InvalidArgumentException('Durable message name is invalid.');
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
        if ($this->version < 1 || preg_match('/^[a-z][a-z0-9._-]{1,31}$/D', $this->codec) !== 1) {
            throw new \InvalidArgumentException('Payload version or codec identity is invalid.');
        }
    }
}

namespace Wolfcharaa\MessageBus\Exception;

use Wolfcharaa\MessageBus\Receipt\PublicationReceipt;

/**
 *  Raised when a Command could not reach durable acceptance.
 *
 * @api application
 */
final class CommandNotAccepted extends \RuntimeException {}

/**
 *  Raised after every Event subscription was attempted and at least one failed.
 *
 * @api application
 */
final class PublicationFailed extends \RuntimeException
{
    public function __construct(public readonly PublicationReceipt $receipt)
    {
        parent::__construct('One or more event subscriptions failed.');
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
 *  Stable configuration/registry diagnostic promoted to an exception.
 *
 * @api application
 */
final class InvalidMessageBusConfiguration extends \RuntimeException {}
