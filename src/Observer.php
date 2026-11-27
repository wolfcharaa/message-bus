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
 * Stable logical stage at which a runtime failure became observable.
 *
 * @api application
 */
enum FailureStage: string
{
    case Dispatch = 'dispatch';
    case PayloadDecode = 'payload_decode';
    case TargetResolution = 'target_resolution';
    case ContextCreation = 'context_creation';
    case HandlerResolution = 'handler_resolution';
    case HandlerExecution = 'handler_execution';
    case Idempotency = 'idempotency';
    case FailureClassification = 'failure_classification';
    case DeliverySettlement = 'delivery_settlement';
    case WorkerFork = 'worker_fork';
    case LeaseRecovery = 'lease_recovery';
    case DeliveryAcceptance = 'delivery_acceptance';

    public function code(): string
    {
        return match ($this) {
            self::Dispatch => 'MBD001',
            self::PayloadDecode => 'MBD101',
            self::TargetResolution => 'MBD102',
            self::ContextCreation => 'MBD103',
            self::HandlerResolution => 'MBD104',
            self::HandlerExecution => 'MBD105',
            self::Idempotency => 'MBD106',
            self::FailureClassification => 'MBD107',
            self::DeliverySettlement => 'MBD108',
            self::WorkerFork => 'MBD109',
            self::LeaseRecovery => 'MBD110',
            self::DeliveryAcceptance => 'MBD111',
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::Dispatch => '[MBD001] Message dispatch failed before a result was returned.',
            self::PayloadDecode => '[MBD101] A persisted message payload could not be decoded into its message class.',
            self::TargetResolution => '[MBD102] A persisted delivery target could not be resolved in the compiled registry.',
            self::ContextCreation => '[MBD103] The MessageContext required by a compiled handler could not be created.',
            self::HandlerResolution => '[MBD104] The compiled handler class could not be resolved by the DI container.',
            self::HandlerExecution => '[MBD105] The resolved handler threw while processing the message.',
            self::Idempotency => '[MBD106] The idempotency boundary or identity resolution failed.',
            self::FailureClassification => '[MBD107] The application failure classifier threw while classifying another failure.',
            self::DeliverySettlement => '[MBD108] The backend could not persist the delivery failure outcome.',
            self::WorkerFork => '[MBD109] The worker supervisor could not create a child process.',
            self::LeaseRecovery => '[MBD110] Lease recovery found that the worker no longer owns the delivery attempt.',
            self::DeliveryAcceptance => '[MBD111] The durable backend did not accept the Command or Event delivery.',
        };
    }
}

/**
 * Safe machine-readable failure details shared by events and retained status.
 *
 * The canonical summary starts with the stable MBD code. The arbitrary
 * exception message and trace are deliberately excluded. Previously encoded
 * schema-1 values remain readable when their code and fingerprint are valid;
 * their display summary is refreshed to the current canonical text.
 * Use the MBD code to locate the failed boundary, then correlate IDs and the
 * original throwable from the caller or RuntimeFailureSink.
 *
 * @api application
 */
final readonly class FailureDiagnostic
{
    public string $code;

    public string $summary;

    public string $fingerprint;

    public function __construct(
        public FailureStage $stage,
        public string $failureClass,
        public ?string $relatedFailureClass = null,
    ) {
        foreach (['failureClass' => $this->failureClass, 'relatedFailureClass' => $this->relatedFailureClass] as $field => $value) {
            if ($value !== null && ($value === '' || \strlen($value) > 255)) {
                throw new \InvalidArgumentException('Failure diagnostic field ' . $field . ' must contain 1..255 bytes.');
            }
        }
        $this->code = $this->stage->code();
        $this->summary = $this->stage->summary();
        $this->fingerprint = hash(
            'sha256',
            $this->code . "\0" . $this->stage->value . "\0" . $this->failureClass . "\0" . ($this->relatedFailureClass ?? ''),
        );
    }

    public static function fromThrowable(
        FailureStage $stage,
        \Throwable $failure,
        ?\Throwable $relatedFailure = null,
    ): self {
        return new self(
            $stage,
            $failure::class,
            $relatedFailure instanceof \Throwable ? $relatedFailure::class : null,
        );
    }

    /** @return array{code: string, stage: string, summary: string, failure_class: string, related_failure_class: string|null, fingerprint: string} */
    public function normalized(): array
    {
        return [
            'code' => $this->code,
            'stage' => $this->stage->value,
            'summary' => $this->summary,
            'failure_class' => $this->failureClass,
            'related_failure_class' => $this->relatedFailureClass,
            'fingerprint' => $this->fingerprint,
        ];
    }

    public function encode(): string
    {
        return json_encode(
            ['schema' => 1] + $this->normalized(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    public static function tryFromEncoded(string $encoded): ?self
    {
        try {
            $value = json_decode($encoded, true, 32, JSON_THROW_ON_ERROR);
            if (!\is_array($value) || array_is_list($value) || ($value['schema'] ?? null) !== 1
                || !\is_string($value['stage'] ?? null)
                || !\is_string($value['failure_class'] ?? null)
                || (!\is_string($value['related_failure_class'] ?? null)
                    && ($value['related_failure_class'] ?? null) !== null)) {
                return null;
            }
            $diagnostic = new self(
                FailureStage::from($value['stage']),
                $value['failure_class'],
                $value['related_failure_class'] ?? null,
            );
            if (($value['code'] ?? null) !== $diagnostic->code
                || ($value['fingerprint'] ?? null) !== $diagnostic->fingerprint) {
                return null;
            }

            return $diagnostic;
        } catch (\Throwable) {
            return null;
        }
    }
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

    /** @return list<string> */
    public function requiredFields(): array
    {
        return match ($this) {
            self::DispatchStarted => ['message_id', 'correlation_id', 'message_name'],
            self::DispatchCompleted => [
                'message_id', 'correlation_id', 'message_name', 'duration_us', 'outcome',
            ],
            self::DispatchFailed => [
                'message_id', 'correlation_id', 'message_name', 'duration_us', 'outcome',
                'failure_class', 'error_fingerprint', 'failure_code', 'failure_stage', 'failure_summary',
            ],
            self::DeliveryAccepted => [
                'message_id', 'delivery_id', 'correlation_id', 'message_name', 'target_id', 'route', 'outcome',
            ],
            self::AttemptStarted => [
                'message_id', 'delivery_id', 'correlation_id', 'attempt_id', 'message_name', 'target_id',
                'worker_id', 'route', 'profile', 'attempt_number',
            ],
            self::AttemptSucceeded => [
                'message_id', 'delivery_id', 'correlation_id', 'attempt_id', 'message_name', 'target_id',
                'worker_id', 'route', 'profile', 'attempt_number', 'duration_us', 'outcome',
            ],
            self::AttemptFailed => [
                'message_id', 'delivery_id', 'correlation_id', 'attempt_id', 'message_name', 'target_id',
                'worker_id', 'route', 'profile', 'attempt_number', 'duration_us', 'outcome',
                'failure_category', 'failure_class', 'error_fingerprint', 'failure_code', 'failure_stage',
                'failure_summary',
            ],
            self::DeliveryRecovered => ['delivery_id', 'outcome'],
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
        public ?string $failureCode = null,
        public ?string $failureStage = null,
        public ?string $failureSummary = null,
        public ?string $relatedFailureClass = null,
    ) {
        foreach ([
            'messageName' => $this->messageName,
            'targetId' => $this->targetId,
            'workerId' => $this->workerId,
            'route' => $this->route,
            'profile' => $this->profile,
            'outcome' => $this->outcome,
            'failureCategory' => $this->failureCategory,
            'failureClass' => $this->failureClass,
            'failureCode' => $this->failureCode,
            'failureStage' => $this->failureStage,
            'failureSummary' => $this->failureSummary,
            'relatedFailureClass' => $this->relatedFailureClass,
        ] as $field => $value) {
            if ($value !== null && ($value === '' || \strlen($value) > 255)) {
                throw new \InvalidArgumentException('Runtime event field ' . $field . ' must contain 1..255 bytes.');
            }
        }
        if ($this->schemaVersion !== 1) {
            throw new \InvalidArgumentException('Runtime event schemaVersion must be 1.');
        }
        if ($this->attemptNumber !== null && $this->attemptNumber < 1) {
            throw new \InvalidArgumentException('Runtime event attemptNumber must be at least 1.');
        }
        if ($this->durationMicroseconds !== null && $this->durationMicroseconds < 0) {
            throw new \InvalidArgumentException('Runtime event durationMicroseconds must not be negative.');
        }
        if ($this->errorFingerprint !== null
            && preg_match('/^[a-f0-9]{64}$/D', $this->errorFingerprint) !== 1) {
            throw new \InvalidArgumentException('Runtime event errorFingerprint must be a lowercase SHA-256 digest.');
        }
        if (($this->failureCode === null) !== ($this->failureStage === null)
            || ($this->failureCode === null) !== ($this->failureSummary === null)) {
            throw new \InvalidArgumentException(
                'Runtime event failureCode, failureStage and failureSummary must be provided together.',
            );
        }
        $this->assertRequiredFields();
    }

    private function assertRequiredFields(): void
    {
        $fields = [
            'message_id' => $this->messageId,
            'delivery_id' => $this->deliveryId,
            'correlation_id' => $this->correlationId,
            'attempt_id' => $this->attemptId,
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
            'failure_code' => $this->failureCode,
            'failure_stage' => $this->failureStage,
            'failure_summary' => $this->failureSummary,
        ];
        foreach ($this->type->requiredFields() as $field) {
            if ($fields[$field] === null) {
                throw new \InvalidArgumentException(
                    'Runtime event ' . $this->type->value . ' requires field ' . $field . '.',
                );
            }
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
            'failure_code' => $this->failureCode,
            'failure_stage' => $this->failureStage,
            'failure_summary' => $this->failureSummary,
            'related_failure_class' => $this->relatedFailureClass,
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
 * Process-local reporting channel that receives the original runtime throwable.
 *
 * Implementations must not throw and must keep exception data out of portable events.
 *
 * @api spi
 */
interface RuntimeFailureSink
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
 * Explicit opt-out from process-local runtime failure reporting.
 *
 * @api application
 */
final readonly class NullRuntimeFailureSink implements RuntimeFailureSink
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

/**
 * PSR-3 process-local failure sink retaining the original exception and trace.
 *
 * @api adapter
 */
final readonly class PsrLogRuntimeFailureSink implements RuntimeFailureSink
{
    public function __construct(private LoggerInterface $logger) {}

    public function report(\Throwable $failure, RuntimeEvent $event): void
    {
        $this->logger->error(
            'message_bus.runtime_failure',
            array_filter(
                $event->normalized() + ['exception' => $failure],
                static fn(mixed $value): bool => $value !== null,
            ),
        );
    }
}
