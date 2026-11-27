---
doc_id: reference.public-api
contract_revision: 1
---

# Публичный PHP API

Сгенерировано из PHPDoc `@api`; не редактируйте вручную. Роли: application, spi, adapter, tooling.

## Application

### `Wolfcharaa\MessageBus\Attribute\Handler`

Marks one public handler method; Event subscriptions require a stable ID.

- Вид: `class`
- `__construct(?string $subscriptionId = NULL): mixed`

### `Wolfcharaa\MessageBus\Attribute\MessageName`

Declares the canonical durable wire identity of a Command or Event.

- Вид: `class`
- `__construct(string $value): mixed`

### `Wolfcharaa\MessageBus\Attribute\Payload`

Declares the current payload version and codec identity for durable messages.

- Вид: `class`
- `__construct(int $version = 1, string $codec = 'json'): mixed`

### `Wolfcharaa\MessageBus\Codec\EncodedPayload`

Canonical versioned payload at the durable boundary.

- Вид: `class`
- `__construct(string $messageName, int $version, string $codec, string $bytes, ?string $objectGraphFingerprint = NULL): mixed`
- `contentType(): string`

### `Wolfcharaa\MessageBus\Codec\MessageCodecs`

Exact per-message/per-version codec registry.

- Вид: `class`
- `__construct(array $encoders, array $decoders): mixed`
- `canEncode(string $messageClass): bool`
- `decode(Wolfcharaa\MessageBus\Codec\EncodedPayload $payload): Wolfcharaa\MessageBus\Contract\Message`
- `encode(Wolfcharaa\MessageBus\Contract\Message $message): Wolfcharaa\MessageBus\Codec\EncodedPayload`

### `Wolfcharaa\MessageBus\Codec\PhpNativeTypeGraph`

Normalized per-message native serialization graph and compatibility hash.

- Вид: `class`
- `__construct(array $classes, array $properties, string $fingerprint): mixed`
- `toJson(): string`

### `Wolfcharaa\MessageBus\Codec\PhpNativeTypeGraphCompiler`

Compiles a minimal native-PHP allowed_classes graph from declared property types.

- Вид: `class`
- `compile(string $messageClass, array $concretePaths = array (
)): Wolfcharaa\MessageBus\Codec\PhpNativeTypeGraph`

### `Wolfcharaa\MessageBus\Context\CancellationRequested`

Raised only after a handler explicitly observes a cancellation request.

- Вид: `class`

### `Wolfcharaa\MessageBus\Context\CancellationSignal`

Read-only cooperative cancellation capability exposed to long handlers.

- Вид: `interface`
- `isRequested(): bool`
- `throwIfRequested(): void`

### `Wolfcharaa\MessageBus\Context\DefaultMessageContext`

Default immutable context.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Context\MessageContextSeed $seed): mixed`
- `cancellation(): Wolfcharaa\MessageBus\Context\CancellationSignal`
- `dispatch(Wolfcharaa\MessageBus\Contract\Message $message): mixed`
- `metadata(): Wolfcharaa\MessageBus\Metadata\MessageMetadata`

### `Wolfcharaa\MessageBus\Context\MessageContext`

Handler-side view of nested dispatch, portable metadata and cancellation.

- Вид: `interface`
- `cancellation(): Wolfcharaa\MessageBus\Context\CancellationSignal`
- `dispatch(Wolfcharaa\MessageBus\Contract\Message $message): mixed`
- `metadata(): Wolfcharaa\MessageBus\Metadata\MessageMetadata`

### `Wolfcharaa\MessageBus\Context\MessageContextFactories`

Exact fixed factory map for applications that do not need scoped resolution.

- Вид: `class`
- `__construct(array $factories = array (
)): mixed`
- `factory(string $context): Wolfcharaa\MessageBus\Context\MessageContextFactory`
- `has(string $context): bool`

### `Wolfcharaa\MessageBus\Context\MessageContextSeed`

Restricted input from which a context factory creates one invocation context.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Contract\MessageBus $dispatcher, Wolfcharaa\MessageBus\Metadata\MessageMetadata $metadata, Wolfcharaa\MessageBus\Context\CancellationSignal $cancellation): mixed`

### `Wolfcharaa\MessageBus\Context\NullCancellationSignal`

Cancellation signal for inline execution and non-cancellable work.

- Вид: `class`
- `isRequested(): bool`
- `throwIfRequested(): void`

### `Wolfcharaa\MessageBus\Contract\Command`



- Вид: `interface`

### `Wolfcharaa\MessageBus\Contract\Event`



- Вид: `interface`

### `Wolfcharaa\MessageBus\Contract\Message`

Root role contract for every dispatchable message.

- Вид: `interface`

### `Wolfcharaa\MessageBus\Contract\MessageBus`

Single application dispatch boundary for all four message roles.

- Вид: `interface`
- `dispatch(Wolfcharaa\MessageBus\Contract\Message $message): mixed`

### `Wolfcharaa\MessageBus\Contract\Query`



- Вид: `interface`

### `Wolfcharaa\MessageBus\Contract\Request`



- Вид: `interface`

### `Wolfcharaa\MessageBus\DefaultMessageBus`

Main v7 runtime implementing the single generic dispatch boundary.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Registry\CompiledRegistry $registry, Wolfcharaa\MessageBus\HandlerServiceResolver $handlers, Wolfcharaa\MessageBus\Routing\DeliveryRoutes $routes, Wolfcharaa\MessageBus\Context\MessageContextFactoryResolver $contextFactories = new Wolfcharaa\MessageBus\Context\MessageContextFactories, Wolfcharaa\MessageBus\Middleware\HandlerMiddlewareResolver $middleware = new Wolfcharaa\MessageBus\Middleware\HandlerMiddlewares, ?Wolfcharaa\MessageBus\DurableRuntime $durable = NULL, Psr\Clock\ClockInterface $clock = new Wolfcharaa\MessageBus\SystemClock, Wolfcharaa\MessageBus\Identity\IdentityGenerator $identities = new Wolfcharaa\MessageBus\Identity\RandomIdentityGenerator, Wolfcharaa\MessageBus\Metadata\MetadataSchema $metadataSchema = new Wolfcharaa\MessageBus\Metadata\MetadataSchema, ?Wolfcharaa\MessageBus\Metadata\MetadataEnricher $metadataEnricher = NULL, Wolfcharaa\MessageBus\Observability\RuntimeObserver $observer = new Wolfcharaa\MessageBus\Observability\NullRuntimeObserver, Wolfcharaa\MessageBus\Observability\ObserverFailureSink $observerFailureSink = new Wolfcharaa\MessageBus\Observability\NullObserverFailureSink): mixed`
- `dispatch(Wolfcharaa\MessageBus\Contract\Message $message): mixed`

### `Wolfcharaa\MessageBus\Delivery\CancellationOutcome`

Observable cancellation race outcome.

- Вид: `enum`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case CancelledBeforeStart`
- `case RequestedRunning`
- `case AlreadyRequested`
- `case AlreadyTerminal`
- `case NotFound`

### `Wolfcharaa\MessageBus\Delivery\CancellationReceipt`

Immutable result of one idempotent cancellation request.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Delivery\CancellationOutcome $outcome, DateTimeImmutable $recordedAt): mixed`

### `Wolfcharaa\MessageBus\Delivery\ControlActor`

Trusted audit metadata supplied after application authorization.

- Вид: `class`
- `__construct(string $actor, string $reason, ?string $reference = NULL, ?string $operationId = NULL, ?string $idempotencyKey = NULL, array $metadata = array (
)): mixed`

### `Wolfcharaa\MessageBus\Delivery\DeliveryMaintenance`

Explicit bounded recovery and retention operations for schedulers/operators.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend $backend): mixed`
- `prune(DateTimeImmutable $terminalBefore, int $limit, bool $execute): Wolfcharaa\MessageBus\Delivery\MaintenanceReport`
- `recoverExpired(int $limit): Wolfcharaa\MessageBus\Delivery\MaintenanceReport`

### `Wolfcharaa\MessageBus\Delivery\DeliveryOperations`

Application-facing status and recovery operations.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend $backend): mixed`
- `cancel(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Delivery\ControlActor $actor): Wolfcharaa\MessageBus\Delivery\CancellationReceipt`
- `replay(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Delivery\DeliveryPolicy $policy, Wolfcharaa\MessageBus\Delivery\ControlActor $actor): Wolfcharaa\MessageBus\Delivery\ReplayReceipt`
- `status(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId): ?Wolfcharaa\MessageBus\Delivery\DeliveryStatus`

### `Wolfcharaa\MessageBus\Delivery\DeliveryPolicies`

Complete named policy map resolved during composition.

- Вид: `class`
- `__construct(array $policies): mixed`
- `get(string $name): Wolfcharaa\MessageBus\Delivery\DeliveryPolicy`
- `has(string $name): bool`

### `Wolfcharaa\MessageBus\Delivery\DeliveryPolicy`

Fully resolved and persisted retry/deadline/priority policy.

- Вид: `class`
- `__construct(string $name, int $maxAttempts = 1, int $retryDelaySeconds = 0, int $leaseSeconds = 60, int $maxElapsedSeconds = 300, int $priority = 0, bool $idempotent = false, int $idempotencyRetentionSeconds = 86400, int $replayWindowSeconds = 0, int $idempotencySafetyMarginSeconds = 60, bool $retryLostAttempts = false): mixed`

### `Wolfcharaa\MessageBus\Delivery\DeliveryState`

Current backend-neutral lifecycle state of one target delivery.

- Вид: `enum`
- `isTerminal(): bool`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case Staged`
- `case Accepted`
- `case Running`
- `case RetryWait`
- `case Succeeded`
- `case Failed`
- `case Cancelled`
- `case OutcomeUnknown`

### `Wolfcharaa\MessageBus\Delivery\DeliveryStatus`

Snapshot of the current state; it is not an immutable history record.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Identity\MessageId $messageId, string $targetId, Wolfcharaa\MessageBus\Delivery\DeliveryState $state, int $attempts, DateTimeImmutable $updatedAt, ?string $lastFailureCategory = NULL, ?Wolfcharaa\MessageBus\Identity\AttemptId $lastFailureAttemptId = NULL, ?Wolfcharaa\MessageBus\Observability\FailureDiagnostic $lastFailure = NULL): mixed`

### `Wolfcharaa\MessageBus\Delivery\FailureCategory`

Failure category controls retry without coupling handlers to a transport.

- Вид: `enum`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case Retryable`
- `case Terminal`
- `case Incompatible`
- `case ClassifierError`
- `case Cancelled`
- `case OutcomeUnknown`

### `Wolfcharaa\MessageBus\Delivery\FailureClassification`

Classification outcome retaining a failure raised by the classifier itself.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Delivery\FailureCategory $category, ?Throwable $classifierFailure = NULL): mixed`

### `Wolfcharaa\MessageBus\Delivery\FailureClassifiers`

Ordered classifier chain with a conservative terminal fallback.

- Вид: `class`
- `__construct(?Wolfcharaa\MessageBus\Delivery\FailureClassifier $application = NULL): mixed`
- `classify(Throwable $failure): Wolfcharaa\MessageBus\Delivery\FailureCategory`
- `classifyDetailed(Throwable $failure): Wolfcharaa\MessageBus\Delivery\FailureClassification`

### `Wolfcharaa\MessageBus\Delivery\MaintenanceReport`

Bounded maintenance operation counters.

- Вид: `class`
- `__construct(int $examined, int $changed, bool $hasMore): mixed`

### `Wolfcharaa\MessageBus\Delivery\ReplayReceipt`

Replay acceptance links old and new immutable delivery histories.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Identity\DeliveryId $originalDeliveryId, Wolfcharaa\MessageBus\Identity\DeliveryId $newDeliveryId, Wolfcharaa\MessageBus\Delivery\DeliveryPolicy $policy): mixed`

### `Wolfcharaa\MessageBus\DurableRuntime`

Cohesive durable capability required by Command and durable Event routes.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend $backend, Wolfcharaa\MessageBus\Codec\MessageCodecs $codecs, Wolfcharaa\MessageBus\Delivery\DeliveryPolicies $policies): mixed`

### `Wolfcharaa\MessageBus\Exception\CleanupFailed`

Raised when cleanup fails while another throwable is already primary.

- Вид: `class`
- `__construct(string $operation, Throwable $primaryFailure, array $cleanupFailures): mixed`

### `Wolfcharaa\MessageBus\Exception\CommandNotAccepted`

Raised when a Command could not reach durable acceptance.

- Вид: `class`
- `__construct(string $message, int $code = 0, ?Throwable $previous = NULL, ?Wolfcharaa\MessageBus\Identity\MessageId $messageId = NULL, ?string $messageName = NULL, ?string $targetId = NULL, ?Wolfcharaa\MessageBus\Observability\FailureStage $stage = NULL): mixed`

### `Wolfcharaa\MessageBus\Exception\DeliverySettlementFailed`

Raised when a delivery failure and its fenced settlement both fail.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Identity\AttemptId $attemptId, Throwable $primaryFailure, Throwable $settlementFailure): mixed`

### `Wolfcharaa\MessageBus\Exception\IncompatibleDeliveryFailure`

Terminal persisted payload/codec/target incompatibility.

- Вид: `class`

### `Wolfcharaa\MessageBus\Exception\InvalidMessageBusConfiguration`

Stable configuration/registry diagnostic promoted to an exception.

- Вид: `class`
- `__construct(string $message = 'MessageBus configuration is invalid.', array $diagnostics = array (
), ?Throwable $previous = NULL): mixed`

### `Wolfcharaa\MessageBus\Exception\PublicationFailed`

Raised after every Event subscription was attempted and at least one failed.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Receipt\PublicationReceipt $receipt, array $failures = array (
)): mixed`

### `Wolfcharaa\MessageBus\Exception\TerminalDeliveryFailure`

Explicit terminal classification for a known handler failure.

- Вид: `class`

### `Wolfcharaa\MessageBus\HandlerServices`

Exact fixed object map for applications that do not need scoped resolution.

- Вид: `class`
- `__construct(array $services): mixed`
- `get(string $class): object`
- `has(string $class): bool`

### `Wolfcharaa\MessageBus\Idempotency\IdempotencyAttempt`

One retained target execution protected by the atomic boundary.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentity $identity, Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, DateTimeImmutable $retainUntil): mixed`

### `Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentity`

Stable target-scoped idempotency identity and intent fingerprint.

- Вид: `class`
- `__construct(string $targetId, Wolfcharaa\MessageBus\Idempotency\IdempotencyKey $key, Wolfcharaa\MessageBus\Idempotency\IntentFingerprint $fingerprint): mixed`

### `Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentityResolvers`

Complete target-scoped resolver map; duplicate declarations fail closed.

- Вид: `class`
- `__construct(array $resolvers): mixed`
- `has(string $targetId): bool`
- `resolve(Wolfcharaa\MessageBus\Contract\Message $message, string $targetId): Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentity`
- `targetIds(): array`

### `Wolfcharaa\MessageBus\Idempotency\IdempotencyKey`

Normalized application idempotency key; persistence stores only its digest.

- Вид: `class`
- `__construct(string $value): mixed`
- `digest(): string`

### `Wolfcharaa\MessageBus\Idempotency\IdempotencyMaintenance`

Bounded retention for completed records whose safety horizon has expired.

- Вид: `interface`
- `pruneExpired(DateTimeImmutable $at, int $limit, bool $execute): Wolfcharaa\MessageBus\Delivery\MaintenanceReport`

### `Wolfcharaa\MessageBus\Idempotency\IdempotencyOutcome`

Atomic idempotency outcome.

- Вид: `enum`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case Executed`
- `case Replayed`

### `Wolfcharaa\MessageBus\Idempotency\IdempotencyResult`

Result of an atomic target effect.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Idempotency\IdempotencyOutcome $outcome): mixed`

### `Wolfcharaa\MessageBus\Idempotency\IdempotencyRuntime`

Complete runtime capability enabled only for targets declared idempotent.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentityResolvers $identities, Wolfcharaa\MessageBus\Idempotency\AtomicIdempotencyBoundary $boundary): mixed`

### `Wolfcharaa\MessageBus\Idempotency\IntentFingerprint`

Versioned digest of canonical business intent.

- Вид: `class`
- `__construct(string $algorithm, int $version, string $digest): mixed`

### `Wolfcharaa\MessageBus\Idempotency\TargetIdempotencyResolver`

One exact target-to-resolver declaration used by registry validation.

- Вид: `class`
- `__construct(string $targetId, Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentityResolver $resolver): mixed`

### `Wolfcharaa\MessageBus\Identity\AttemptId`

Stable identity of one claimed execution attempt.

- Вид: `class`
- `__construct(string $value): mixed`
- `__toString(): string`

### `Wolfcharaa\MessageBus\Identity\CorrelationId`

Correlates a top-level dispatch and all causally nested messages.

- Вид: `class`
- `__construct(string $value): mixed`
- `__toString(): string`

### `Wolfcharaa\MessageBus\Identity\DeliveryId`

Stable identity of one independently tracked durable target delivery.

- Вид: `class`
- `__construct(string $value): mixed`
- `__toString(): string`

### `Wolfcharaa\MessageBus\Identity\LeaseToken`

Opaque fenced credential for compare-and-set attempt settlement.

- Вид: `class`
- `__construct(string $value): mixed`
- `__toString(): string`

### `Wolfcharaa\MessageBus\Identity\MessageId`

Stable identity shared by all deliveries produced by one dispatch.

- Вид: `class`
- `__construct(string $value): mixed`
- `__toString(): string`

### `Wolfcharaa\MessageBus\Metadata\MessageMetadata`

Immutable portable application metadata carried across process boundaries.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Identity\MessageId $messageId, Wolfcharaa\MessageBus\Identity\CorrelationId $correlationId, ?Wolfcharaa\MessageBus\Identity\MessageId $causationId, DateTimeImmutable $createdAt, array $application = array (
)): mixed`

### `Wolfcharaa\MessageBus\Metadata\MetadataSchema`

Validates keys allowed in application metadata and rejects system spoofing.

- Вид: `class`
- `__construct(array $allowedKeys = array (
)): mixed`
- `validate(array $values): void`

### `Wolfcharaa\MessageBus\Middleware\HandlerMiddlewares`

Exact fixed middleware list for applications that do not need scoped resolution.

- Вид: `class`
- `__construct(array $middleware = array (
)): mixed`
- `all(): array`

### `Wolfcharaa\MessageBus\Observability\FailureDiagnostic`

Safe machine-readable failure details shared by events and retained status.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Observability\FailureStage $stage, string $failureClass, ?string $relatedFailureClass = NULL): mixed`
- `encode(): string`
- `normalized(): array`
- `static fromThrowable(Wolfcharaa\MessageBus\Observability\FailureStage $stage, Throwable $failure, ?Throwable $relatedFailure = NULL): Wolfcharaa\MessageBus\Observability\FailureDiagnostic`
- `static tryFromEncoded(string $encoded): ?Wolfcharaa\MessageBus\Observability\FailureDiagnostic`

### `Wolfcharaa\MessageBus\Observability\FailureStage`

Stable logical stage at which a runtime failure became observable.

- Вид: `enum`
- `code(): string`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `summary(): string`
- `case Dispatch`
- `case PayloadDecode`
- `case TargetResolution`
- `case ContextCreation`
- `case HandlerResolution`
- `case HandlerExecution`
- `case Idempotency`
- `case FailureClassification`
- `case DeliverySettlement`
- `case WorkerFork`
- `case LeaseRecovery`
- `case DeliveryAcceptance`

### `Wolfcharaa\MessageBus\Observability\NullObserverFailureSink`

Default no-op emergency sink.

- Вид: `class`
- `report(Throwable $failure, Wolfcharaa\MessageBus\Observability\RuntimeEvent $event): void`

### `Wolfcharaa\MessageBus\Observability\NullRuntimeFailureSink`

Explicit opt-out from process-local runtime failure reporting.

- Вид: `class`
- `report(Throwable $failure, Wolfcharaa\MessageBus\Observability\RuntimeEvent $event): void`

### `Wolfcharaa\MessageBus\Observability\NullRuntimeObserver`

Default zero-cost observer.

- Вид: `class`
- `observe(Wolfcharaa\MessageBus\Observability\RuntimeEvent $event): void`

### `Wolfcharaa\MessageBus\Observability\RuntimeEvent`

Safe typed library fact; business payload and credentials are never included.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Observability\RuntimeEventType $type, DateTimeImmutable $occurredAt, ?Wolfcharaa\MessageBus\Identity\MessageId $messageId = NULL, ?Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId = NULL, ?Wolfcharaa\MessageBus\Identity\CorrelationId $correlationId = NULL, ?Wolfcharaa\MessageBus\Identity\MessageId $causationId = NULL, ?Wolfcharaa\MessageBus\Identity\AttemptId $attemptId = NULL, ?string $messageName = NULL, ?string $targetId = NULL, ?string $workerId = NULL, ?string $route = NULL, ?string $profile = NULL, ?int $attemptNumber = NULL, ?int $durationMicroseconds = NULL, ?string $outcome = NULL, ?string $failureCategory = NULL, ?string $failureClass = NULL, ?string $errorFingerprint = NULL, int $schemaVersion = 1, ?string $failureCode = NULL, ?string $failureStage = NULL, ?string $failureSummary = NULL, ?string $relatedFailureClass = NULL): mixed`
- `normalized(): array`
- `severity(): Wolfcharaa\MessageBus\Observability\RuntimeEventSeverity`

### `Wolfcharaa\MessageBus\Observability\RuntimeEventSeverity`

Recommended adapter-independent event severity.

- Вид: `enum`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case Debug`
- `case Warning`
- `case Error`

### `Wolfcharaa\MessageBus\Observability\RuntimeEventType`

Stable runtime fact category emitted by the package.

- Вид: `enum`
- `requiredFields(): array`
- `severity(): Wolfcharaa\MessageBus\Observability\RuntimeEventSeverity`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case DispatchStarted`
- `case DispatchCompleted`
- `case DispatchFailed`
- `case DeliveryAccepted`
- `case AttemptStarted`
- `case AttemptSucceeded`
- `case AttemptFailed`
- `case DeliveryRecovered`

### `Wolfcharaa\MessageBus\Receipt\CommandReceipt`

Historical receipt proving initial Command staging or acceptance only.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Identity\MessageId $messageId, Wolfcharaa\MessageBus\Receipt\PublicationEntry $entry): mixed`

### `Wolfcharaa\MessageBus\Receipt\PublicationEntry`

Immutable initial outcome of one target at dispatch time.

- Вид: `class`
- `__construct(string $targetId, Wolfcharaa\MessageBus\Receipt\PublicationOutcome $outcome, ?Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId = NULL, ?string $failureCategory = NULL, ?string $failureSummary = NULL, ?string $failureClass = NULL): mixed`

### `Wolfcharaa\MessageBus\Receipt\PublicationOutcome`

Initial observable outcome of one subscription or Command target.

- Вид: `enum`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case Completed`
- `case Staged`
- `case Accepted`
- `case TestInlineCompleted`
- `case Failed`

### `Wolfcharaa\MessageBus\Receipt\PublicationReceipt`

Aggregate Event publication receipt including every independent target.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Identity\MessageId $messageId, array $entries): mixed`
- `isComplete(): bool`

### `Wolfcharaa\MessageBus\Routing\DeliveryMode`

Execution boundary selected for one Command owner or Event subscription.

- Вид: `enum`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case Inline`
- `case Durable`
- `case TestInline`

### `Wolfcharaa\MessageBus\Routing\DeliveryRoute`

Immutable route for one independently tracked target.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Routing\DeliveryMode $mode, string $policy = 'default', string $lane = 'default'): mixed`

### `Wolfcharaa\MessageBus\Routing\DeliveryRoutes`

Complete deterministic route map; no environment fallback occurs at runtime.

- Вид: `class`
- `__construct(array $routes): mixed`
- `has(Wolfcharaa\MessageBus\Registry\HandlerBinding $binding): bool`
- `route(Wolfcharaa\MessageBus\Registry\HandlerBinding $binding): Wolfcharaa\MessageBus\Routing\DeliveryRoute`

### `Wolfcharaa\MessageBus\Worker\DeliveryWorkerRuntime`

Claims and executes one fenced durable attempt.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend $backend, Wolfcharaa\MessageBus\Codec\MessageCodecs $codecs, Wolfcharaa\MessageBus\Registry\CompiledRegistry $registry, Wolfcharaa\MessageBus\HandlerServiceResolver $handlers, Wolfcharaa\MessageBus\Contract\MessageBus $dispatcher, Wolfcharaa\MessageBus\Context\MessageContextFactoryResolver $contextFactories = new Wolfcharaa\MessageBus\Context\MessageContextFactories, Wolfcharaa\MessageBus\Middleware\HandlerMiddlewareResolver $middleware = new Wolfcharaa\MessageBus\Middleware\HandlerMiddlewares, Wolfcharaa\MessageBus\Delivery\FailureClassifiers $classifiers = new Wolfcharaa\MessageBus\Delivery\FailureClassifiers, Wolfcharaa\MessageBus\Observability\RuntimeObserver $observer = new Wolfcharaa\MessageBus\Observability\NullRuntimeObserver, Wolfcharaa\MessageBus\Observability\ObserverFailureSink $observerFailureSink = new Wolfcharaa\MessageBus\Observability\NullObserverFailureSink, Psr\Clock\ClockInterface $clock = new Wolfcharaa\MessageBus\SystemClock, ?Wolfcharaa\MessageBus\Idempotency\IdempotencyRuntime $idempotency = NULL, Wolfcharaa\MessageBus\Observability\RuntimeFailureSink $runtimeFailureSink = new Wolfcharaa\MessageBus\Observability\NullRuntimeFailureSink): mixed`
- `cancellationRequested(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): bool`
- `claim(Wolfcharaa\MessageBus\Worker\WorkerProfile $profile, string $workerId): ?Wolfcharaa\MessageBus\Delivery\ClaimedDelivery`
- `execute(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim, ?int $childPid = NULL, string $workerId = 'unknown', string $profile = 'unknown'): Wolfcharaa\MessageBus\Worker\WorkerIteration`
- `forkFailed(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): Wolfcharaa\MessageBus\Worker\WorkerIteration`
- `heartbeat(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): bool`
- `runOnce(Wolfcharaa\MessageBus\Worker\WorkerProfile $profile, string $workerId): Wolfcharaa\MessageBus\Worker\WorkerIteration`

### `Wolfcharaa\MessageBus\Worker\WorkerControlOperations`

Public pause/resume/status operations; process start/stop stays external.

- Вид: `interface`
- `pause(Wolfcharaa\MessageBus\Worker\WorkerControlScope $scope, string $actor, string $reason, string $idempotencyKey, ?int $expectedRevision = NULL): Wolfcharaa\MessageBus\Worker\WorkerControlReceipt`
- `prune(DateTimeImmutable $olderThan, int $limit, bool $execute): Wolfcharaa\MessageBus\Worker\WorkerPruneReport`
- `report(Wolfcharaa\MessageBus\Worker\WorkerStatus $status): void`
- `resume(Wolfcharaa\MessageBus\Worker\WorkerControlScope $scope, string $actor, string $reason, string $idempotencyKey, ?int $expectedRevision = NULL): Wolfcharaa\MessageBus\Worker\WorkerControlReceipt`
- `state(Wolfcharaa\MessageBus\Worker\WorkerControlScope $scope): Wolfcharaa\MessageBus\Worker\WorkerControlState`
- `statuses(Wolfcharaa\MessageBus\Worker\WorkerStatusFilter $filter = new Wolfcharaa\MessageBus\Worker\WorkerStatusFilter): array`

### `Wolfcharaa\MessageBus\Worker\WorkerControlOutcome`

Observable result of one idempotent pause/resume operation.

- Вид: `enum`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case Applied`
- `case AlreadyDesired`
- `case RevisionConflict`

### `Wolfcharaa\MessageBus\Worker\WorkerControlReceipt`

Receipt for a revisioned worker control change.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Worker\WorkerControlState $state, Wolfcharaa\MessageBus\Worker\WorkerControlOutcome $outcome, ?string $operationId = NULL, ?DateTimeImmutable $acceptedAt = NULL, ?Wolfcharaa\MessageBus\Worker\WorkerDesiredState $previousDesiredState = NULL, bool $duplicate = false): mixed`

### `Wolfcharaa\MessageBus\Worker\WorkerControlScope`

Stable global or profile worker-control scope.

- Вид: `class`
- `static global(): Wolfcharaa\MessageBus\Worker\WorkerControlScope`
- `static profile(string $profile): Wolfcharaa\MessageBus\Worker\WorkerControlScope`

### `Wolfcharaa\MessageBus\Worker\WorkerControlState`

Revisioned desired-state snapshot.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Worker\WorkerControlScope $scope, Wolfcharaa\MessageBus\Worker\WorkerDesiredState $desiredState, int $revision, DateTimeImmutable $updatedAt): mixed`

### `Wolfcharaa\MessageBus\Worker\WorkerDesiredState`

Desired claim state for the global runtime or one named profile.

- Вид: `enum`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case Running`
- `case Paused`

### `Wolfcharaa\MessageBus\Worker\WorkerIteration`

Result of one bounded worker iteration.

- Вид: `enum`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case Idle`
- `case Succeeded`
- `case Failed`
- `case LostLease`

### `Wolfcharaa\MessageBus\Worker\WorkerProfile`

Complete worker lane and process policy.

- Вид: `class`
- `__construct(string $name, string $lane, int $parallelism = 1, int $pollMicroseconds = 100000, int $heartbeatMicroseconds = 1000000, int $shutdownGraceSeconds = 10, int $forceKillGraceSeconds = 5, int $maxHandledAttempts = 0, int $maxRuntimeSeconds = 0, int $maxMemoryBytes = 0, bool $stopWhenEmpty = false): mixed`

### `Wolfcharaa\MessageBus\Worker\WorkerPruneReport`

Bounded dry-run/execute counters for stale worker facts.

- Вид: `class`
- `__construct(int $statuses, int $operations, bool $executed, bool $hasMore): mixed`

### `Wolfcharaa\MessageBus\Worker\WorkerStatus`

Current liveness snapshot reported by one parent supervisor.

- Вид: `class`
- `__construct(string $workerId, string $profile, string $lane, int $pid, string $state, int $activeAttempts, DateTimeImmutable $startedAt, DateTimeImmutable $heartbeatAt, string $host = 'unknown', string $buildId = 'unknown', string $registryHash = '0000000000000000000000000000000000000000000000000000000000000000', int $capacity = 1, int $appliedGlobalRevision = 0, int $appliedProfileRevision = 0, ?DateTimeImmutable $stoppedAt = NULL, int $requiredGlobalRevision = 0, int $requiredProfileRevision = 0, Wolfcharaa\MessageBus\Worker\WorkerDesiredState $effectiveDesiredState = Wolfcharaa\MessageBus\Worker\WorkerDesiredState::Running, bool $stale = false, bool $drained = false): mixed`
- `activity(): string`

### `Wolfcharaa\MessageBus\Worker\WorkerStatusFilter`

Read-only worker status filter and liveness horizon.

- Вид: `class`
- `__construct(?string $profile = NULL, ?string $instance = NULL, int $staleAfterSeconds = 30): mixed`

## Spi

### `Wolfcharaa\MessageBus\Codec\MessageCodec`

Explicit codec for one message type and payload version.

- Вид: `interface`
- `decode(string $payload): Wolfcharaa\MessageBus\Contract\Message`
- `encode(Wolfcharaa\MessageBus\Contract\Message $message): string`

### `Wolfcharaa\MessageBus\Codec\MessageUpcaster`

Transforms one historical decoded JSON shape into the next version.

- Вид: `interface`
- `upcast(array $payload): array`

### `Wolfcharaa\MessageBus\Context\MessageContextFactory`

Creates the exact context type requested by a handler's second parameter.

- Вид: `interface`
- `create(Wolfcharaa\MessageBus\Context\MessageContextSeed $seed): Wolfcharaa\MessageBus\Context\MessageContext`

### `Wolfcharaa\MessageBus\Context\MessageContextFactoryResolver`

Resolves the exact context factory from the current application scope.

- Вид: `interface`
- `factory(string $context): Wolfcharaa\MessageBus\Context\MessageContextFactory`
- `has(string $context): bool`

### `Wolfcharaa\MessageBus\Delivery\ClaimedDelivery`

Claimed work plus its fenced attempt credential.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Delivery\DeliveryDraft $delivery, Wolfcharaa\MessageBus\Delivery\DeliveryAttempt $attempt, Wolfcharaa\MessageBus\Identity\LeaseToken $leaseToken, DateTimeImmutable $leaseExpiresAt): mixed`

### `Wolfcharaa\MessageBus\Delivery\DeliveryAcceptance`

Result of a successful durable staging/acceptance operation.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Delivery\DeliveryState $state): mixed`

### `Wolfcharaa\MessageBus\Delivery\DeliveryAttempt`

One immutable execution attempt in delivery history.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Identity\AttemptId $attemptId, Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, int $number, DateTimeImmutable $startedAt, ?DateTimeImmutable $finishedAt = NULL, ?string $outcome = NULL): mixed`

### `Wolfcharaa\MessageBus\Delivery\DeliveryDraft`

Durable acceptance input for one target.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Metadata\MessageMetadata $metadata, Wolfcharaa\MessageBus\Codec\EncodedPayload $payload, string $targetId, string $handlerClass, string $handlerMethod, string $lane, Wolfcharaa\MessageBus\Delivery\DeliveryPolicy $policy, ?Wolfcharaa\MessageBus\Identity\DeliveryId $replayOf = NULL, Wolfcharaa\MessageBus\Registry\MessageRole $role = Wolfcharaa\MessageBus\Registry\MessageRole::Command): mixed`

### `Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend`

Complete durable runtime port. Implementations must pass the package

- Вид: `interface`
- `accept(Wolfcharaa\MessageBus\Delivery\DeliveryDraft $delivery): Wolfcharaa\MessageBus\Delivery\DeliveryAcceptance`
- `cancel(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Delivery\ControlActor $actor): Wolfcharaa\MessageBus\Delivery\CancellationReceipt`
- `cancellationRequested(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): bool`
- `claim(string $lane, string $workerId): ?Wolfcharaa\MessageBus\Delivery\ClaimedDelivery`
- `fail(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim, Wolfcharaa\MessageBus\Delivery\FailureCategory $category, string $summary): bool`
- `heartbeat(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): bool`
- `markHandlerStarted(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim, ?int $childPid = NULL): bool`
- `prune(DateTimeImmutable $terminalBefore, int $limit, bool $execute): Wolfcharaa\MessageBus\Delivery\MaintenanceReport`
- `recoverExpired(int $limit): Wolfcharaa\MessageBus\Delivery\MaintenanceReport`
- `replay(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Delivery\DeliveryPolicy $policy, Wolfcharaa\MessageBus\Delivery\ControlActor $actor): Wolfcharaa\MessageBus\Delivery\ReplayReceipt`
- `status(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId): ?Wolfcharaa\MessageBus\Delivery\DeliveryStatus`
- `succeed(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): bool`

### `Wolfcharaa\MessageBus\Delivery\FailureClassifier`

Classifies a thrown handler/runtime failure in an ordered classifier chain.

- Вид: `interface`
- `classify(Throwable $failure): ?Wolfcharaa\MessageBus\Delivery\FailureCategory`

### `Wolfcharaa\MessageBus\HandlerServiceResolver`

Resolves one compiler-validated handler from the current application scope.

- Вид: `interface`
- `get(string $class): object`
- `has(string $class): bool`

### `Wolfcharaa\MessageBus\Idempotency\AtomicIdempotencyBoundary`

Transaction boundary coupling idempotency claim, domain effect and completion.

- Вид: `interface`
- `execute(Wolfcharaa\MessageBus\Idempotency\IdempotencyAttempt $attempt, callable $effect): Wolfcharaa\MessageBus\Idempotency\IdempotencyResult`

### `Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentityResolver`

Resolves application identity only for explicitly idempotent Command/Event targets.

- Вид: `interface`
- `resolve(Wolfcharaa\MessageBus\Contract\Message $message, string $targetId): Wolfcharaa\MessageBus\Idempotency\IdempotencyIdentity`

### `Wolfcharaa\MessageBus\Identity\IdentityGenerator`

Generates unpredictable technical identities without application input.

- Вид: `interface`
- `attemptId(): Wolfcharaa\MessageBus\Identity\AttemptId`
- `correlationId(): Wolfcharaa\MessageBus\Identity\CorrelationId`
- `deliveryId(): Wolfcharaa\MessageBus\Identity\DeliveryId`
- `leaseToken(): Wolfcharaa\MessageBus\Identity\LeaseToken`
- `messageId(): Wolfcharaa\MessageBus\Identity\MessageId`

### `Wolfcharaa\MessageBus\Metadata\MetadataEnricher`

Pure application hook that contributes schema-bounded portable metadata.

- Вид: `interface`
- `enrich(object $message): array`

### `Wolfcharaa\MessageBus\Middleware\HandlerMiddleware`

One handler-attempt middleware extension point.

- Вид: `interface`
- `process(object $message, Wolfcharaa\MessageBus\Context\MessageContext $context, Wolfcharaa\MessageBus\Middleware\NextHandler $next): mixed`

### `Wolfcharaa\MessageBus\Middleware\HandlerMiddlewareResolver`

Resolves the complete middleware chain for each handler invocation.

- Вид: `interface`
- `all(): array`

### `Wolfcharaa\MessageBus\Middleware\NextHandler`

One-shot continuation. A middleware may call it once or short-circuit.

- Вид: `interface`
- `handle(): mixed`

### `Wolfcharaa\MessageBus\Observability\ObserverFailureSink`

Process-local last-resort reporting for observer adapter failures.

- Вид: `interface`
- `report(Throwable $failure, Wolfcharaa\MessageBus\Observability\RuntimeEvent $event): void`

### `Wolfcharaa\MessageBus\Observability\RuntimeFailureSink`

Process-local reporting channel that receives the original runtime throwable.

- Вид: `interface`
- `report(Throwable $failure, Wolfcharaa\MessageBus\Observability\RuntimeEvent $event): void`

### `Wolfcharaa\MessageBus\Observability\RuntimeObserver`

One observer extension for metrics, traces and structured logs.

- Вид: `interface`
- `observe(Wolfcharaa\MessageBus\Observability\RuntimeEvent $event): void`

### `Wolfcharaa\MessageBus\Registry\RegistryClassSource`

Bounded source of candidate handler classes for registry compilation.

- Вид: `interface`
- `classes(): iterable`

### `Wolfcharaa\MessageBus\Registry\RegistryValidationRule`

Additive validation rule run against the complete deterministic registry.

- Вид: `interface`
- `validate(Wolfcharaa\MessageBus\Registry\CompiledRegistry $registry): array`

### `Wolfcharaa\MessageBus\Worker\ChildExecutionFactory`

Creates a fresh child execution runtime after every inherited resource reset.

- Вид: `interface`
- `createAfterFork(): Wolfcharaa\MessageBus\Worker\DeliveryWorkerRuntime`

### `Wolfcharaa\MessageBus\Worker\ChildRuntimeBuilder`

Builds process-local handler services and connections in an already reset child.

- Вид: `interface`
- `create(): Wolfcharaa\MessageBus\Worker\DeliveryWorkerRuntime`

### `Wolfcharaa\MessageBus\Worker\ForkResourceReset`

Fork-sensitive application resource reset before child runtime creation.

- Вид: `interface`
- `resetAfterFork(): void`

### `Wolfcharaa\MessageBus\Worker\WorkerProcessSystem`

Isolates native PCNTL/POSIX operations for deterministic supervisor tests.

- Вид: `interface`
- `available(): bool`
- `currentPid(): int`
- `fork(): int`
- `host(): string`
- `installStopSignals(callable $stop): void`
- `monotonicTime(): float`
- `signal(int $pid, int $signal): void`
- `sleep(int $microseconds): void`
- `waitNoHang(int $pid): bool`

## Adapter

### `Wolfcharaa\MessageBus\Adapter\InMemoryDeliveryBackend`

Deterministic process-local conformance/test backend.

- Вид: `class`
- `__construct(Psr\Clock\ClockInterface $clock = new Wolfcharaa\MessageBus\SystemClock, Wolfcharaa\MessageBus\Identity\IdentityGenerator $identities = new Wolfcharaa\MessageBus\Identity\RandomIdentityGenerator): mixed`
- `accept(Wolfcharaa\MessageBus\Delivery\DeliveryDraft $delivery): Wolfcharaa\MessageBus\Delivery\DeliveryAcceptance`
- `cancel(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Delivery\ControlActor $actor): Wolfcharaa\MessageBus\Delivery\CancellationReceipt`
- `cancellationRequested(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): bool`
- `claim(string $lane, string $workerId): ?Wolfcharaa\MessageBus\Delivery\ClaimedDelivery`
- `fail(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim, Wolfcharaa\MessageBus\Delivery\FailureCategory $category, string $summary): bool`
- `heartbeat(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): bool`
- `markHandlerStarted(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim, ?int $childPid = NULL): bool`
- `prune(DateTimeImmutable $terminalBefore, int $limit, bool $execute): Wolfcharaa\MessageBus\Delivery\MaintenanceReport`
- `recoverExpired(int $limit): Wolfcharaa\MessageBus\Delivery\MaintenanceReport`
- `replay(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Delivery\DeliveryPolicy $policy, Wolfcharaa\MessageBus\Delivery\ControlActor $actor): Wolfcharaa\MessageBus\Delivery\ReplayReceipt`
- `status(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId): ?Wolfcharaa\MessageBus\Delivery\DeliveryStatus`
- `succeed(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): bool`

### `Wolfcharaa\MessageBus\Adapter\Postgres\PostgresAtomicIdempotencyBoundary`

PostgreSQL atomic idempotency boundary sharing the application's PDO transaction.

- Вид: `class`
- `__construct(PDO $pdo, string $schema = 'public'): mixed`
- `execute(Wolfcharaa\MessageBus\Idempotency\IdempotencyAttempt $attempt, callable $effect): Wolfcharaa\MessageBus\Idempotency\IdempotencyResult`

### `Wolfcharaa\MessageBus\Adapter\Postgres\PostgresDeliveryBackend`

PostgreSQL durable backend using database time, SKIP LOCKED and fenced CAS.

- Вид: `class`
- `__construct(PDO $pdo, Wolfcharaa\MessageBus\Identity\IdentityGenerator $identities = new Wolfcharaa\MessageBus\Identity\RandomIdentityGenerator, string $schema = 'public'): mixed`
- `accept(Wolfcharaa\MessageBus\Delivery\DeliveryDraft $delivery): Wolfcharaa\MessageBus\Delivery\DeliveryAcceptance`
- `cancel(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Delivery\ControlActor $actor): Wolfcharaa\MessageBus\Delivery\CancellationReceipt`
- `cancellationRequested(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): bool`
- `claim(string $lane, string $workerId): ?Wolfcharaa\MessageBus\Delivery\ClaimedDelivery`
- `fail(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim, Wolfcharaa\MessageBus\Delivery\FailureCategory $category, string $summary): bool`
- `heartbeat(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): bool`
- `markHandlerStarted(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim, ?int $childPid = NULL): bool`
- `prune(DateTimeImmutable $terminalBefore, int $limit, bool $execute): Wolfcharaa\MessageBus\Delivery\MaintenanceReport`
- `recoverExpired(int $limit): Wolfcharaa\MessageBus\Delivery\MaintenanceReport`
- `replay(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId, Wolfcharaa\MessageBus\Delivery\DeliveryPolicy $policy, Wolfcharaa\MessageBus\Delivery\ControlActor $actor): Wolfcharaa\MessageBus\Delivery\ReplayReceipt`
- `status(Wolfcharaa\MessageBus\Identity\DeliveryId $deliveryId): ?Wolfcharaa\MessageBus\Delivery\DeliveryStatus`
- `succeed(Wolfcharaa\MessageBus\Delivery\ClaimedDelivery $claim): bool`

### `Wolfcharaa\MessageBus\Adapter\Postgres\PostgresIdempotencyMaintenance`

PostgreSQL bounded retention adapter; dry-run is selected by the caller.

- Вид: `class`
- `__construct(PDO $pdo, string $schema = 'public'): mixed`
- `pruneExpired(DateTimeImmutable $at, int $limit, bool $execute): Wolfcharaa\MessageBus\Delivery\MaintenanceReport`

### `Wolfcharaa\MessageBus\Adapter\Postgres\PostgresSchemaManager`

Applies and validates immutable package migrations using host-owned tooling.

- Вид: `class`
- `__construct(PDO $pdo, string $schema = 'public', Wolfcharaa\MessageBus\Adapter\Postgres\SchemaManifest $manifest = new Wolfcharaa\MessageBus\Adapter\Postgres\SchemaManifest): mixed`
- `installationFingerprint(): string`
- `migrate(): void`
- `validate(): void`
- `validateCatalog(): void`

### `Wolfcharaa\MessageBus\Adapter\Postgres\PostgresWorkerControl`

PostgreSQL revisioned worker control and status adapter.

- Вид: `class`
- `__construct(PDO $pdo, string $schema = 'public'): mixed`
- `pause(Wolfcharaa\MessageBus\Worker\WorkerControlScope $scope, string $actor, string $reason, string $idempotencyKey, ?int $expectedRevision = NULL): Wolfcharaa\MessageBus\Worker\WorkerControlReceipt`
- `prune(DateTimeImmutable $olderThan, int $limit, bool $execute): Wolfcharaa\MessageBus\Worker\WorkerPruneReport`
- `report(Wolfcharaa\MessageBus\Worker\WorkerStatus $status): void`
- `resume(Wolfcharaa\MessageBus\Worker\WorkerControlScope $scope, string $actor, string $reason, string $idempotencyKey, ?int $expectedRevision = NULL): Wolfcharaa\MessageBus\Worker\WorkerControlReceipt`
- `state(Wolfcharaa\MessageBus\Worker\WorkerControlScope $scope): Wolfcharaa\MessageBus\Worker\WorkerControlState`
- `statuses(Wolfcharaa\MessageBus\Worker\WorkerStatusFilter $filter = new Wolfcharaa\MessageBus\Worker\WorkerStatusFilter): array`

### `Wolfcharaa\MessageBus\Adapter\Postgres\SchemaManifest`

Loads the ordered append-only packaged migration manifest fail-closed.

- Вид: `class`
- `__construct(?string $resources = NULL): mixed`
- `catalog(): array`
- `migrations(): array`

### `Wolfcharaa\MessageBus\Adapter\Postgres\SchemaMigration`

One immutable package migration plus its verified artifact hash.

- Вид: `class`
- `__construct(string $component, string $migrationId, string $sql, string $checksum): mixed`
- `key(): string`

### `Wolfcharaa\MessageBus\Adapter\Symfony\Console`

Optional Symfony Console ready composition; core does not require Symfony.

- Вид: `class`
- `static delivery(Wolfcharaa\MessageBus\Delivery\DeliveryOperations $operations, Wolfcharaa\MessageBus\Delivery\DeliveryMaintenance $maintenance, Wolfcharaa\MessageBus\Delivery\DeliveryPolicies $policies): Symfony\Component\Console\Application`
- `static idempotency(Wolfcharaa\MessageBus\Idempotency\IdempotencyMaintenance $maintenance): Symfony\Component\Console\Application`
- `static registry(Wolfcharaa\MessageBus\Registry\RegistryCompiler $compiler, Wolfcharaa\MessageBus\Registry\RegistryClassSource $source, string $artifactPath, ?string $applicationBuildId = NULL): Symfony\Component\Console\Application`
- `static schema(Wolfcharaa\MessageBus\Adapter\Postgres\PostgresSchemaManager $schema): Symfony\Component\Console\Application`
- `static worker(Wolfcharaa\MessageBus\Worker\PcntlWorkerSupervisor $supervisor, Wolfcharaa\MessageBus\Worker\WorkerProfile $profile, Wolfcharaa\MessageBus\Worker\WorkerControlOperations $control): Symfony\Component\Console\Application`

### `Wolfcharaa\MessageBus\Codec\JsonMessageCodec`

JSON codec for immutable DTO messages with public constructor properties.

- Вид: `class`
- `__construct(string $messageClass): mixed`
- `decode(string $payload): Wolfcharaa\MessageBus\Contract\Message`
- `encode(Wolfcharaa\MessageBus\Contract\Message $message): string`

### `Wolfcharaa\MessageBus\Codec\PhpNativeMessageCodec`

Explicit bounded native-PHP codec for legacy object graphs.

- Вид: `class`
- `__construct(string $messageClass, Wolfcharaa\MessageBus\Codec\PhpNativeTypeGraph $typeGraph): mixed`
- `compatibilityFingerprint(): string`
- `decode(string $payload): Wolfcharaa\MessageBus\Contract\Message`
- `encode(Wolfcharaa\MessageBus\Contract\Message $message): string`

### `Wolfcharaa\MessageBus\Codec\UpcastingJsonMessageCodec`

JSON decoder that applies every explicit shape transition before construction.

- Вид: `class`
- `__construct(string $messageClass, array $upcasters): mixed`
- `decode(string $payload): Wolfcharaa\MessageBus\Contract\Message`
- `encode(Wolfcharaa\MessageBus\Contract\Message $message): string`

### `Wolfcharaa\MessageBus\Context\DefaultMessageContextFactory`



- Вид: `class`
- `create(Wolfcharaa\MessageBus\Context\MessageContextSeed $seed): Wolfcharaa\MessageBus\Context\MessageContext`

### `Wolfcharaa\MessageBus\Identity\RandomIdentityGenerator`

Cryptographic default identity generator.

- Вид: `class`
- `attemptId(): Wolfcharaa\MessageBus\Identity\AttemptId`
- `correlationId(): Wolfcharaa\MessageBus\Identity\CorrelationId`
- `deliveryId(): Wolfcharaa\MessageBus\Identity\DeliveryId`
- `leaseToken(): Wolfcharaa\MessageBus\Identity\LeaseToken`
- `messageId(): Wolfcharaa\MessageBus\Identity\MessageId`

### `Wolfcharaa\MessageBus\Observability\PsrLogRuntimeFailureSink`

PSR-3 process-local failure sink retaining the original exception and trace.

- Вид: `class`
- `__construct(Psr\Log\LoggerInterface $logger): mixed`
- `report(Throwable $failure, Wolfcharaa\MessageBus\Observability\RuntimeEvent $event): void`

### `Wolfcharaa\MessageBus\Observability\PsrLogRuntimeObserver`

Optional PSR-3 adapter; psr/log is only a suggested package dependency.

- Вид: `class`
- `__construct(Psr\Log\LoggerInterface $logger): mixed`
- `observe(Wolfcharaa\MessageBus\Observability\RuntimeEvent $event): void`

### `Wolfcharaa\MessageBus\SystemClock`

Immutable package clock backed by the PHP wall clock.

- Вид: `class`
- `now(): DateTimeImmutable`

### `Wolfcharaa\MessageBus\Worker\DefaultFailureClassifier`

Built-in classification for cancellation and explicit terminal failures.

- Вид: `class`
- `classify(Throwable $failure): ?Wolfcharaa\MessageBus\Delivery\FailureCategory`

### `Wolfcharaa\MessageBus\Worker\NativeWorkerProcessSystem`

Native Unix process adapter used by the production supervisor.

- Вид: `class`
- `available(): bool`
- `currentPid(): int`
- `fork(): int`
- `host(): string`
- `installStopSignals(callable $stop): void`
- `monotonicTime(): float`
- `signal(int $pid, int $signal): void`
- `sleep(int $microseconds): void`
- `waitNoHang(int $pid): bool`

### `Wolfcharaa\MessageBus\Worker\PcntlWorkerSupervisor`

PCNTL parent supervisor: one fresh child process per claimed attempt.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Worker\DeliveryWorkerRuntime $runtime, Wolfcharaa\MessageBus\Worker\ChildExecutionFactory $childRuntimeFactory, ?Wolfcharaa\MessageBus\Worker\WorkerControlOperations $control = NULL, string $buildId = 'unknown', string $registryHash = '0000000000000000000000000000000000000000000000000000000000000000', Wolfcharaa\MessageBus\Worker\WorkerProcessSystem $process = new Wolfcharaa\MessageBus\Worker\NativeWorkerProcessSystem): mixed`
- `run(Wolfcharaa\MessageBus\Worker\WorkerProfile $profile, ?callable $shouldStop = NULL): void`

### `Wolfcharaa\MessageBus\Worker\ResettingChildExecutionFactory`

Ordered resource reset followed by fresh child runtime construction.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Worker\ChildRuntimeBuilder $builder, array $resets): mixed`
- `createAfterFork(): Wolfcharaa\MessageBus\Worker\DeliveryWorkerRuntime`

## Tooling

### `Wolfcharaa\MessageBus\Registry\CompiledRegistry`

Immutable compiled registry consumed by dispatch and worker runtimes.

- Вид: `class`
- `__construct(array $bindings, string $fingerprint): mixed`
- `bindings(): array`
- `handlersFor(Wolfcharaa\MessageBus\Contract\Message $message): array`

### `Wolfcharaa\MessageBus\Registry\ComposerClassMapSource`

Bounded Composer classmap source filtered by explicit namespace prefixes.

- Вид: `class`
- `__construct(array $classMapFiles, array $namespacePrefixes): mixed`
- `classes(): iterable`

### `Wolfcharaa\MessageBus\Registry\DiagnosticSeverity`

Severity of a stable compiler diagnostic.

- Вид: `enum`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case Error`
- `case Warning`

### `Wolfcharaa\MessageBus\Registry\ExplicitClassSource`

Explicit deterministic class source suitable for production composition.

- Вид: `class`
- `__construct(array $classes): mixed`
- `classes(): iterable`

### `Wolfcharaa\MessageBus\Registry\HandlerBinding`

One validated method-level handler binding.

- Вид: `class`
- `__construct(string $id, string $messageClass, Wolfcharaa\MessageBus\Registry\MessageRole $role, string $handlerClass, string $method, ?string $contextClass, ?string $subscriptionId): mixed`

### `Wolfcharaa\MessageBus\Registry\MessageRole`

Message role derived exclusively from the message class marker.

- Вид: `enum`
- `static cases(): array`
- `static from(string|int $value): static`
- `static tryFrom(string|int $value): ?static`
- `case Query`
- `case Request`
- `case Command`
- `case Event`

### `Wolfcharaa\MessageBus\Registry\RegistryArtifact`

Self-hashed portable compiled-registry artifact.

- Вид: `class`
- `__construct(string $registryFingerprint, array $bindings, ?string $applicationBuildId, string $contentHash): mixed`
- `assertApplicationBuildId(string $expected): void`
- `registry(): Wolfcharaa\MessageBus\Registry\CompiledRegistry`
- `static fromJson(string $json): Wolfcharaa\MessageBus\Registry\RegistryArtifact`
- `static fromRegistry(Wolfcharaa\MessageBus\Registry\CompiledRegistry $registry, ?string $applicationBuildId = NULL): Wolfcharaa\MessageBus\Registry\RegistryArtifact`
- `toJson(): string`

### `Wolfcharaa\MessageBus\Registry\RegistryArtifactStore`

Public tooling root for atomic artifact writes and verified loads.

- Вид: `class`
- `load(string $path, ?string $expectedApplicationBuildId = NULL): Wolfcharaa\MessageBus\Registry\RegistryArtifact`
- `write(Wolfcharaa\MessageBus\Registry\RegistryArtifact $artifact, string $path): void`

### `Wolfcharaa\MessageBus\Registry\RegistryCompilationResult`

Registry compilation outcome with a stable diagnostic catalog.

- Вид: `class`
- `__construct(?Wolfcharaa\MessageBus\Registry\CompiledRegistry $registry, array $diagnostics): mixed`
- `isValid(): bool`
- `requireRegistry(): Wolfcharaa\MessageBus\Registry\CompiledRegistry`

### `Wolfcharaa\MessageBus\Registry\RegistryCompiler`

Compiles method attributes and signatures into a deterministic registry.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Context\MessageContextFactoryResolver $contextFactories = new Wolfcharaa\MessageBus\Context\MessageContextFactories, array $rules = array (
)): mixed`
- `compile(Wolfcharaa\MessageBus\Registry\RegistryClassSource $source): Wolfcharaa\MessageBus\Registry\RegistryCompilationResult`

### `Wolfcharaa\MessageBus\Registry\RegistryDiagnostic`

Machine-readable registry compiler diagnostic.

- Вид: `class`
- `__construct(string $code, Wolfcharaa\MessageBus\Registry\DiagnosticSeverity $severity, string $message, ?string $symbol = NULL, ?string $rule = NULL, ?string $subjectKind = NULL, ?string $expected = NULL, ?string $actual = NULL, ?string $causeClass = NULL): mixed`
- `normalized(): array`

### `Wolfcharaa\MessageBus\Routing\RuntimeConfigurationRule`

Validates the complete route, policy and target-scoped idempotency plan.

- Вид: `class`
- `__construct(Wolfcharaa\MessageBus\Routing\DeliveryRoutes $routes, Wolfcharaa\MessageBus\Delivery\DeliveryPolicies $policies, ?Wolfcharaa\MessageBus\Idempotency\IdempotencyRuntime $idempotency = NULL): mixed`
- `validate(Wolfcharaa\MessageBus\Registry\CompiledRegistry $registry): array`

### `Wolfcharaa\MessageBus\Tooling\Migration\V6ImportConfiguration`

Complete fail-closed migration map; no legacy name or binding fallback exists.

- Вид: `class`
- `__construct(array $messages, array $targets): mixed`
- `message(string $legacyName): Wolfcharaa\MessageBus\Tooling\Migration\V6MessageMigration`
- `target(string $legacyBindingId): Wolfcharaa\MessageBus\Tooling\Migration\V6TargetMigration`

### `Wolfcharaa\MessageBus\Tooling\Migration\V6ImportFailed`

Row-specific fail-closed importer diagnostic.

- Вид: `class`
- `__construct(string $legacyQueueJobId, Throwable $previous): mixed`

### `Wolfcharaa\MessageBus\Tooling\Migration\V6ImportReport`

One bounded offline import result.

- Вид: `class`
- `__construct(int $examined, int $imported, int $alreadyImported, bool $hasMore, bool $dryRun): mixed`

### `Wolfcharaa\MessageBus\Tooling\Migration\V6MessageMigration`

Explicit legacy decoder for one v6 wire name.

- Вид: `class`
- `__construct(callable $decode): mixed`

### `Wolfcharaa\MessageBus\Tooling\Migration\V6QueueImporter`

Offline standard-v6 importer; it never deletes or dual-writes the legacy table.

- Вид: `class`
- `__construct(PDO $pdo, Wolfcharaa\MessageBus\Adapter\Postgres\PostgresDeliveryBackend $backend, Wolfcharaa\MessageBus\Registry\CompiledRegistry $registry, Wolfcharaa\MessageBus\Codec\MessageCodecs $codecs, Wolfcharaa\MessageBus\Delivery\DeliveryPolicies $policies, Wolfcharaa\MessageBus\Identity\IdentityGenerator $identities, Wolfcharaa\MessageBus\Tooling\Migration\V6ImportConfiguration $configuration, string $schema = 'public'): mixed`
- `import(DateTimeImmutable $terminalCutoff, int $limit = 100, bool $execute = false): Wolfcharaa\MessageBus\Tooling\Migration\V6ImportReport`

### `Wolfcharaa\MessageBus\Tooling\Migration\V6TargetMigration`

Explicit legacy binding to current target/lane/policy mapping.

- Вид: `class`
- `__construct(string $targetId, string $lane, string $policy): mixed`

