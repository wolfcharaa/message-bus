<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Delivery;

use Wolfcharaa\MessageBus\Codec\EncodedPayload;
use Wolfcharaa\MessageBus\Identity\AttemptId;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Identity\LeaseToken;
use Wolfcharaa\MessageBus\Identity\MessageId;
use Wolfcharaa\MessageBus\Metadata\MessageMetadata;
use Wolfcharaa\MessageBus\Observability\FailureDiagnostic;
use Wolfcharaa\MessageBus\Registry\MessageRole;

/**
 *  Current backend-neutral lifecycle state of one target delivery.
 *
 * @api application
 */
enum DeliveryState: string
{
    case Staged = 'staged';
    case Accepted = 'accepted';
    case Running = 'running';
    case RetryWait = 'retry_wait';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case OutcomeUnknown = 'outcome_unknown';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::Succeeded, self::Failed, self::Cancelled, self::OutcomeUnknown => true,
            default => false,
        };
    }
}

/**
 *  Snapshot of the current state; it is not an immutable history record.
 *
 * @api application
 */
final readonly class DeliveryStatus
{
    public function __construct(
        public DeliveryId $deliveryId,
        public MessageId $messageId,
        public string $targetId,
        public DeliveryState $state,
        public int $attempts,
        public \DateTimeImmutable $updatedAt,
        public ?string $lastFailureCategory = null,
        public ?AttemptId $lastFailureAttemptId = null,
        public ?FailureDiagnostic $lastFailure = null,
    ) {
        if ($this->lastFailure instanceof FailureDiagnostic
            && !($this->lastFailureAttemptId instanceof AttemptId)) {
            throw new \InvalidArgumentException('DeliveryStatus lastFailure requires lastFailureAttemptId.');
        }
    }
}

/**
 *  One immutable execution attempt in delivery history.
 *
 * @api spi
 */
final readonly class DeliveryAttempt
{
    public function __construct(
        public AttemptId $attemptId,
        public DeliveryId $deliveryId,
        public int $number,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt = null,
        public ?string $outcome = null,
    ) {}
}

/**
 *  Fully resolved and persisted retry/deadline/priority policy.
 *
 * @api application
 */
final readonly class DeliveryPolicy
{
    public function __construct(
        public string $name,
        public int $maxAttempts = 1,
        public int $retryDelaySeconds = 0,
        public int $leaseSeconds = 60,
        public int $maxElapsedSeconds = 300,
        public int $priority = 0,
        public bool $idempotent = false,
        public int $idempotencyRetentionSeconds = 86_400,
        public int $replayWindowSeconds = 0,
        public int $idempotencySafetyMarginSeconds = 60,
        public bool $retryLostAttempts = false,
    ) {
        if ($this->name === '') {
            throw new \InvalidArgumentException('DeliveryPolicy name must not be empty.');
        }
        if ($this->maxAttempts < 1) {
            throw new \InvalidArgumentException('DeliveryPolicy maxAttempts must be at least 1.');
        }
        if ($this->retryDelaySeconds < 0) {
            throw new \InvalidArgumentException('DeliveryPolicy retryDelaySeconds must not be negative.');
        }
        if ($this->leaseSeconds < 1) {
            throw new \InvalidArgumentException('DeliveryPolicy leaseSeconds must be at least 1.');
        }
        if ($this->maxElapsedSeconds < 1) {
            throw new \InvalidArgumentException('DeliveryPolicy maxElapsedSeconds must be at least 1.');
        }
        if ($this->idempotencyRetentionSeconds < 0) {
            throw new \InvalidArgumentException('DeliveryPolicy idempotencyRetentionSeconds must not be negative.');
        }
        if ($this->replayWindowSeconds < 0) {
            throw new \InvalidArgumentException('DeliveryPolicy replayWindowSeconds must not be negative.');
        }
        if ($this->idempotencySafetyMarginSeconds < 0) {
            throw new \InvalidArgumentException('DeliveryPolicy idempotencySafetyMarginSeconds must not be negative.');
        }
        $requiredRetention = $this->maxElapsedSeconds
            + $this->replayWindowSeconds
            + $this->idempotencySafetyMarginSeconds;
        if ($this->idempotent && $this->idempotencyRetentionSeconds < $requiredRetention) {
            throw new \InvalidArgumentException('Idempotency retention is shorter than the delivery safety horizon.');
        }
        if ($this->retryLostAttempts && !$this->idempotent) {
            throw new \InvalidArgumentException('Lost-attempt retry requires an idempotent delivery policy.');
        }
    }
}

/**
 *  Complete named policy map resolved during composition.
 *
 * @api application
 */
final readonly class DeliveryPolicies
{
    /** @param array<string, DeliveryPolicy> $policies */
    public function __construct(private array $policies) {}

    public function get(string $name): DeliveryPolicy
    {
        return $this->policies[$name]
            ?? throw new \LogicException('Unknown delivery policy: ' . $name);
    }

    public function has(string $name): bool
    {
        return isset($this->policies[$name]);
    }
}

/**
 *  Failure category controls retry without coupling handlers to a transport.
 *
 * @api application
 */
enum FailureCategory: string
{
    case Retryable = 'retryable';
    case Terminal = 'terminal';
    case Incompatible = 'incompatible';
    case ClassifierError = 'classifier_error';
    case Cancelled = 'cancelled';
    case OutcomeUnknown = 'outcome_unknown';
}

/**
 *  Classifies a thrown handler/runtime failure in an ordered classifier chain.
 *
 * @api spi
 */
interface FailureClassifier
{
    public function classify(\Throwable $failure): ?FailureCategory;
}

/**
 * Classification outcome retaining a failure raised by the classifier itself.
 *
 * @api application
 */
final readonly class FailureClassification
{
    public function __construct(
        public FailureCategory $category,
        public ?\Throwable $classifierFailure = null,
    ) {
        if ($this->classifierFailure instanceof \Throwable
            && $this->category !== FailureCategory::ClassifierError) {
            throw new \InvalidArgumentException(
                'FailureClassification classifierFailure requires category classifier_error.',
            );
        }
    }
}

/**
 *  Ordered classifier chain with a conservative terminal fallback.
 *
 * @api application
 */
final readonly class FailureClassifiers
{
    public function __construct(private ?FailureClassifier $application = null) {}

    public function classify(\Throwable $failure): FailureCategory
    {
        return $this->classifyDetailed($failure)->category;
    }

    public function classifyDetailed(\Throwable $failure): FailureClassification
    {
        if (!$this->application instanceof FailureClassifier) {
            return new FailureClassification(FailureCategory::Retryable);
        }

        try {
            return new FailureClassification(
                $this->application->classify($failure) ?? FailureCategory::Retryable,
            );
        } catch (\Throwable $classifierFailure) {
            return new FailureClassification(FailureCategory::ClassifierError, $classifierFailure);
        }
    }
}

/**
 *  Durable acceptance input for one target.
 *
 * @api spi
 */
final readonly class DeliveryDraft
{
    public function __construct(
        public DeliveryId $deliveryId,
        public MessageMetadata $metadata,
        public EncodedPayload $payload,
        public string $targetId,
        public string $handlerClass,
        public string $handlerMethod,
        public string $lane,
        public DeliveryPolicy $policy,
        public ?DeliveryId $replayOf = null,
        public MessageRole $role = MessageRole::Command,
    ) {}
}

/**
 *  Result of a successful durable staging/acceptance operation.
 *
 * @api spi
 */
final readonly class DeliveryAcceptance
{
    public function __construct(
        public DeliveryId $deliveryId,
        public DeliveryState $state,
    ) {
        if ($state !== DeliveryState::Staged && $state !== DeliveryState::Accepted) {
            throw new \InvalidArgumentException('Acceptance state must be staged or accepted.');
        }
    }
}

/**
 *  Claimed work plus its fenced attempt credential.
 *
 * @api spi
 */
final readonly class ClaimedDelivery
{
    public function __construct(
        public DeliveryDraft $delivery,
        public DeliveryAttempt $attempt,
        public LeaseToken $leaseToken,
        public \DateTimeImmutable $leaseExpiresAt,
    ) {}
}

/**
 *  Trusted audit metadata supplied after application authorization.
 *
 * @api application
 */
final readonly class ControlActor
{
    public function __construct(
        public string $actor,
        public string $reason,
        public ?string $reference = null,
        public ?string $operationId = null,
        public ?string $idempotencyKey = null,
        /** @var array<string, bool|float|int|string|null> */
        public array $metadata = [],
    ) {
        if ($this->actor === '') {
            throw new \InvalidArgumentException('ControlActor actor must not be empty.');
        }
        if ($this->reason === '') {
            throw new \InvalidArgumentException('ControlActor reason must not be empty.');
        }
        foreach (['operationId' => $this->operationId, 'idempotencyKey' => $this->idempotencyKey] as $field => $identity) {
            if ($identity !== null && preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}$/D', $identity) !== 1) {
                throw new \InvalidArgumentException(
                    'ControlActor ' . $field . ' must match [a-zA-Z0-9][a-zA-Z0-9._:-]{7,127}.',
                );
            }
        }
        if (\strlen(json_encode($this->metadata, JSON_THROW_ON_ERROR)) > 4_096) {
            throw new \InvalidArgumentException('Control actor metadata exceeds 4 KiB.');
        }
    }
}

/**
 *  Observable cancellation race outcome.
 *
 * @api application
 */
enum CancellationOutcome: string
{
    case CancelledBeforeStart = 'cancelled_before_start';
    case RequestedRunning = 'requested_running';
    case AlreadyRequested = 'already_requested';
    case AlreadyTerminal = 'already_terminal';
    case NotFound = 'not_found';
}

/**
 *  Immutable result of one idempotent cancellation request.
 *
 * @api application
 */
final readonly class CancellationReceipt
{
    public function __construct(
        public DeliveryId $deliveryId,
        public CancellationOutcome $outcome,
        public \DateTimeImmutable $recordedAt,
    ) {}
}

/**
 *  Replay acceptance links old and new immutable delivery histories.
 *
 * @api application
 */
final readonly class ReplayReceipt
{
    public function __construct(
        public DeliveryId $originalDeliveryId,
        public DeliveryId $newDeliveryId,
        public DeliveryPolicy $policy,
    ) {}
}

/**
 *  Bounded maintenance operation counters.
 *
 * @api application
 */
final readonly class MaintenanceReport
{
    public function __construct(
        public int $examined,
        public int $changed,
        public bool $hasMore,
    ) {}
}

/**
 * Complete durable runtime port. Implementations must pass the package
 * conformance suite; a simple framework queue is not a compatible backend.
 *
 * @api spi
 */
interface DurableDeliveryBackend
{
    public function accept(DeliveryDraft $delivery): DeliveryAcceptance;

    public function claim(string $lane, string $workerId): ?ClaimedDelivery;

    public function heartbeat(ClaimedDelivery $claim): bool;

    public function cancellationRequested(ClaimedDelivery $claim): bool;

    /** Fenced marker written immediately before the handler execution boundary. */
    public function markHandlerStarted(ClaimedDelivery $claim, ?int $childPid = null): bool;

    public function succeed(ClaimedDelivery $claim): bool;

    public function fail(ClaimedDelivery $claim, FailureCategory $category, string $summary): bool;

    public function status(DeliveryId $deliveryId): ?DeliveryStatus;

    public function cancel(DeliveryId $deliveryId, ControlActor $actor): CancellationReceipt;

    public function recoverExpired(int $limit): MaintenanceReport;

    public function replay(DeliveryId $deliveryId, DeliveryPolicy $policy, ControlActor $actor): ReplayReceipt;

    public function prune(\DateTimeImmutable $terminalBefore, int $limit, bool $execute): MaintenanceReport;
}

/**
 *  Application-facing status and recovery operations.
 *
 * @api application
 */
final readonly class DeliveryOperations
{
    public function __construct(private DurableDeliveryBackend $backend) {}

    public function status(DeliveryId $deliveryId): ?DeliveryStatus
    {
        return $this->backend->status($deliveryId);
    }

    public function cancel(DeliveryId $deliveryId, ControlActor $actor): CancellationReceipt
    {
        return $this->backend->cancel($deliveryId, $actor);
    }

    public function replay(DeliveryId $deliveryId, DeliveryPolicy $policy, ControlActor $actor): ReplayReceipt
    {
        return $this->backend->replay($deliveryId, $policy, $actor);
    }
}

/**
 *  Explicit bounded recovery and retention operations for schedulers/operators.
 *
 * @api application
 */
final readonly class DeliveryMaintenance
{
    public function __construct(private DurableDeliveryBackend $backend) {}

    public function recoverExpired(int $limit): MaintenanceReport
    {
        return $this->backend->recoverExpired($limit);
    }

    public function prune(\DateTimeImmutable $terminalBefore, int $limit, bool $execute): MaintenanceReport
    {
        return $this->backend->prune($terminalBefore, $limit, $execute);
    }
}
