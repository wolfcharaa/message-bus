<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Observability;

use Psr\Log\LoggerInterface;
use Wolfcharaa\MessageBus\Identity\AttemptId;
use Wolfcharaa\MessageBus\Identity\CorrelationId;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\MessageId;

/**
 * Recommended adapter-independent event severity.
 *
 * @api application
 */
enum RuntimeEventSeverity: string
{
    case Debug = 'debug';
    case Warning = 'warning';
    case Error = 'error';
}

/**
 *  Stable runtime fact category emitted by the package.
 *
 * @api application
 */
enum RuntimeEventType: string
{
    case DispatchStarted = 'dispatch_started';
    case DispatchCompleted = 'dispatch_completed';
    case DispatchFailed = 'dispatch_failed';
    case DeliveryAccepted = 'delivery_accepted';
    case AttemptStarted = 'attempt_started';
    case AttemptSucceeded = 'attempt_succeeded';
    case AttemptFailed = 'attempt_failed';
    case DeliveryRecovered = 'delivery_recovered';

    public function severity(): RuntimeEventSeverity
    {
        return match ($this) {
            self::DispatchFailed, self::AttemptFailed => RuntimeEventSeverity::Error,
            self::DeliveryRecovered => RuntimeEventSeverity::Warning,
            default => RuntimeEventSeverity::Debug,
        };
    }
}

/**
 *  Safe typed library fact; business payload and credentials are never included.
 *
 * @api application
 */
final readonly class RuntimeEvent
{
    public function __construct(
        public RuntimeEventType $type,
        public \DateTimeImmutable $occurredAt,
        public ?MessageId $messageId = null,
        public ?DeliveryId $deliveryId = null,
        public ?CorrelationId $correlationId = null,
        public ?MessageId $causationId = null,
        public ?AttemptId $attemptId = null,
        public ?string $messageName = null,
        public ?string $targetId = null,
        public ?string $workerId = null,
        public ?string $route = null,
        public ?string $profile = null,
        public ?int $attemptNumber = null,
        public ?int $durationMicroseconds = null,
        public ?string $outcome = null,
        public ?string $failureCategory = null,
        public ?string $failureClass = null,
        public ?string $errorFingerprint = null,
        public int $schemaVersion = 1,
    ) {
        foreach ([
            $this->messageName,
            $this->targetId,
            $this->workerId,
            $this->route,
            $this->profile,
            $this->outcome,
            $this->failureCategory,
            $this->failureClass,
        ] as $value) {
            if ($value !== null && ($value === '' || \strlen($value) > 255)) {
                throw new \InvalidArgumentException('Runtime event string field is invalid.');
            }
        }
        if ($this->schemaVersion !== 1 || ($this->attemptNumber !== null && $this->attemptNumber < 1)
            || ($this->durationMicroseconds !== null && $this->durationMicroseconds < 0)
            || ($this->errorFingerprint !== null
                && preg_match('/^[a-f0-9]{64}$/D', $this->errorFingerprint) !== 1)) {
            throw new \InvalidArgumentException('Runtime event version or numeric field is invalid.');
        }
    }

    public function severity(): RuntimeEventSeverity
    {
        return $this->type->severity();
    }

    /** @return array<string, int|string|null> */
    public function normalized(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'event' => $this->type->value,
            'severity' => $this->severity()->value,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'message_id' => $this->messageId?->value,
            'correlation_id' => $this->correlationId?->value,
            'causation_id' => $this->causationId?->value,
            'delivery_id' => $this->deliveryId?->value,
            'attempt_id' => $this->attemptId?->value,
            'message_name' => $this->messageName,
            'target_id' => $this->targetId,
            'worker_id' => $this->workerId,
            'route' => $this->route,
            'profile' => $this->profile,
            'attempt_number' => $this->attemptNumber,
            'duration_us' => $this->durationMicroseconds,
            'outcome' => $this->outcome,
            'failure_category' => $this->failureCategory,
            'failure_class' => $this->failureClass,
            'error_fingerprint' => $this->errorFingerprint,
        ];
    }
}

/**
 *  One observer extension for metrics, traces and structured logs.
 *
 * @api spi
 */
interface RuntimeObserver
{
    public function observe(RuntimeEvent $event): void;
}

/**
 * Process-local last-resort reporting for observer adapter failures.
 *
 * Implementations must not throw; the runtime suppresses a failure if they do.
 *
 * @api spi
 */
interface ObserverFailureSink
{
    public function report(\Throwable $failure, RuntimeEvent $event): void;
}

/**
 *  Default zero-cost observer.
 *
 * @api application
 */
final readonly class NullRuntimeObserver implements RuntimeObserver
{
    public function observe(RuntimeEvent $event): void {}
}

/**
 * Default no-op emergency sink.
 *
 * @api application
 */
final readonly class NullObserverFailureSink implements ObserverFailureSink
{
    public function report(\Throwable $failure, RuntimeEvent $event): void {}
}

/**
 *  Optional PSR-3 adapter; psr/log is only a suggested package dependency.
 *
 * @api adapter
 */
final readonly class PsrLogRuntimeObserver implements RuntimeObserver
{
    public function __construct(private LoggerInterface $logger) {}

    public function observe(RuntimeEvent $event): void
    {
        $this->logger->log(
            $event->severity()->value,
            'message_bus.' . $event->type->value,
            array_filter($event->normalized(), static fn(null|int|string $value): bool => $value !== null),
        );
    }
}
