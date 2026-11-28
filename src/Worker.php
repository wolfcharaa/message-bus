<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

use Psr\Clock\ClockInterface;
use Wolfcharaa\MessageBus\Codec\MessageCodecs;
use Wolfcharaa\MessageBus\Context\CancellationRequested;
use Wolfcharaa\MessageBus\Context\MessageContext;
use Wolfcharaa\MessageBus\Context\MessageContextFactories;
use Wolfcharaa\MessageBus\Context\MessageContextFactoryResolver;
use Wolfcharaa\MessageBus\Context\MessageContextSeed;
use Wolfcharaa\MessageBus\Contract\MessageBus;
use Wolfcharaa\MessageBus\Delivery\ClaimedDelivery;
use Wolfcharaa\MessageBus\Delivery\DurableDeliveryBackend;
use Wolfcharaa\MessageBus\Delivery\FailureCategory;
use Wolfcharaa\MessageBus\Delivery\FailureClassification;
use Wolfcharaa\MessageBus\Delivery\FailureClassifier;
use Wolfcharaa\MessageBus\Delivery\FailureClassifiers;
use Wolfcharaa\MessageBus\Exception\DeliverySettlementFailed;
use Wolfcharaa\MessageBus\Exception\IncompatibleDeliveryFailure;
use Wolfcharaa\MessageBus\Exception\TerminalDeliveryFailure;
use Wolfcharaa\MessageBus\HandlerServiceResolver;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyAttempt;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyRuntime;
use Wolfcharaa\MessageBus\Internal\Runtime\BestEffortRuntimeFailureSink;
use Wolfcharaa\MessageBus\Internal\Runtime\BestEffortRuntimeObserver;
use Wolfcharaa\MessageBus\Internal\Runtime\DeliveryCancellationSignal;
use Wolfcharaa\MessageBus\Internal\Runtime\HandlerPipeline;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddlewareResolver;
use Wolfcharaa\MessageBus\Middleware\HandlerMiddlewares;
use Wolfcharaa\MessageBus\Observability\FailureDiagnostic;
use Wolfcharaa\MessageBus\Observability\FailureStage;
use Wolfcharaa\MessageBus\Observability\NullObserverFailureSink;
use Wolfcharaa\MessageBus\Observability\NullRuntimeFailureSink;
use Wolfcharaa\MessageBus\Observability\NullRuntimeObserver;
use Wolfcharaa\MessageBus\Observability\ObserverFailureSink;
use Wolfcharaa\MessageBus\Observability\RuntimeEvent;
use Wolfcharaa\MessageBus\Observability\RuntimeEventType;
use Wolfcharaa\MessageBus\Observability\RuntimeFailureSink;
use Wolfcharaa\MessageBus\Observability\RuntimeObserver;
use Wolfcharaa\MessageBus\Registry\CompiledRegistry;
use Wolfcharaa\MessageBus\Registry\HandlerBinding;
use Wolfcharaa\MessageBus\SystemClock;

/**
 *  Built-in classification for cancellation and explicit terminal failures.
 *
 * @api adapter
 */
final readonly class DefaultFailureClassifier implements FailureClassifier
{
    public function classify(\Throwable $failure): ?FailureCategory
    {
        return match (true) {
            $failure instanceof CancellationRequested => FailureCategory::Cancelled,
            $failure instanceof IncompatibleDeliveryFailure => FailureCategory::Incompatible,
            $failure instanceof TerminalDeliveryFailure => FailureCategory::Terminal,
            default => null,
        };
    }
}

/**
 *  Result of one bounded worker iteration.
 *
 * @api application
 */
enum WorkerIteration: string
{
    case Idle = 'idle';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case LostLease = 'lost_lease';
}

/**
 *  Complete worker lane and process policy.
 *
 * @api application
 */
final readonly class WorkerProfile
{
    public function __construct(
        public string $name,
        public string $lane,
        public int $parallelism = 1,
        public int $pollMicroseconds = 100_000,
        public int $heartbeatMicroseconds = 1_000_000,
        public int $shutdownGraceSeconds = 10,
        public int $forceKillGraceSeconds = 5,
        public int $maxHandledAttempts = 0,
        public int $maxRuntimeSeconds = 0,
        public int $maxMemoryBytes = 0,
        public bool $stopWhenEmpty = false,
    ) {
        if ($this->name === '') {
            throw new \InvalidArgumentException('WorkerProfile name must not be empty.');
        }
        if ($this->lane === '') {
            throw new \InvalidArgumentException('WorkerProfile lane must not be empty.');
        }
        if ($this->parallelism < 1) {
            throw new \InvalidArgumentException('WorkerProfile parallelism must be at least 1.');
        }
        if ($this->pollMicroseconds < 1_000) {
            throw new \InvalidArgumentException('WorkerProfile pollMicroseconds must be at least 1000.');
        }
        if ($this->heartbeatMicroseconds < 1_000) {
            throw new \InvalidArgumentException('WorkerProfile heartbeatMicroseconds must be at least 1000.');
        }
        if ($this->shutdownGraceSeconds < 1) {
            throw new \InvalidArgumentException('WorkerProfile shutdownGraceSeconds must be at least 1.');
        }
        if ($this->forceKillGraceSeconds < 1) {
            throw new \InvalidArgumentException('WorkerProfile forceKillGraceSeconds must be at least 1.');
        }
        if ($this->maxHandledAttempts < 0) {
            throw new \InvalidArgumentException('WorkerProfile maxHandledAttempts must not be negative.');
        }
        if ($this->maxRuntimeSeconds < 0) {
            throw new \InvalidArgumentException('WorkerProfile maxRuntimeSeconds must not be negative.');
        }
        if ($this->maxMemoryBytes < 0) {
            throw new \InvalidArgumentException('WorkerProfile maxMemoryBytes must not be negative.');
        }
    }
}

/**
 * Fork-sensitive application resource reset before child runtime creation.
 *
 * @api spi
 */
interface ForkResourceReset
{
    public function resetAfterFork(): void;
}

/**
 * Creates a fresh child execution runtime after every inherited resource reset.
 *
 * @api spi
 */
interface ChildExecutionFactory
{
    public function createAfterFork(): DeliveryWorkerRuntime;
}

/**
 * Builds process-local handler services and connections in an already reset child.
 *
 * @api spi
 */
interface ChildRuntimeBuilder
{
    public function create(): DeliveryWorkerRuntime;
}

/**
 * Ordered resource reset followed by fresh child runtime construction.
 *
 * @api adapter
 */
final readonly class ResettingChildExecutionFactory implements ChildExecutionFactory
{
    /** @param list<ForkResourceReset> $resets */
    public function __construct(
        private ChildRuntimeBuilder $builder,
        private array $resets,
    ) {}

    public function createAfterFork(): DeliveryWorkerRuntime
    {
        foreach ($this->resets as $reset) {
            $reset->resetAfterFork();
        }

        return $this->builder->create();
    }
}

/**
 * Isolates native PCNTL/POSIX operations for deterministic supervisor tests.
 *
 * @api spi
 */
interface WorkerProcessSystem
{
    public function available(): bool;

    /** @param callable(): void $stop */
    public function installStopSignals(callable $stop): void;

    public function fork(): int;

    public function currentPid(): int;

    public function waitNoHang(int $pid): bool;

    public function signal(int $pid, int $signal): void;

    public function sleep(int $microseconds): void;

    public function monotonicTime(): float;

    public function host(): string;
}

/**
 * Native Unix process adapter used by the production supervisor.
 *
 * @api adapter
 */
final readonly class NativeWorkerProcessSystem implements WorkerProcessSystem
{
    public function available(): bool
    {
        return \extension_loaded('pcntl') && \extension_loaded('posix');
    }

    public function installStopSignals(callable $stop): void
    {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use ($stop): void {
            $stop();
        });
        pcntl_signal(SIGINT, static function () use ($stop): void {
            $stop();
        });
    }

    public function fork(): int
    {
        return pcntl_fork();
    }

    public function currentPid(): int
    {
        return (int) getmypid();
    }

    public function waitNoHang(int $pid): bool
    {
        $result = pcntl_waitpid($pid, $status, WNOHANG);

        return $result === $pid || $result === -1;
    }

    public function signal(int $pid, int $signal): void
    {
        posix_kill($pid, $signal);
    }

    public function sleep(int $microseconds): void
    {
        usleep($microseconds);
    }

    public function monotonicTime(): float
    {
        return hrtime(true) / 1_000_000_000;
    }

    public function host(): string
    {
        $host = gethostname();

        return \is_string($host) && $host !== '' ? $host : 'unknown';
    }
}

/**
 *  Claims and executes one fenced durable attempt.
 *
 * @api application
 */
final readonly class DeliveryWorkerRuntime
{
    private RuntimeObserver $observer;

    private RuntimeFailureSink $failureSink;

    public function __construct(
        private DurableDeliveryBackend $backend,
        private MessageCodecs $codecs,
        private CompiledRegistry $registry,
        private HandlerServiceResolver $handlers,
        private MessageBus $dispatcher,
        private MessageContextFactoryResolver $contextFactories = new MessageContextFactories(),
        private HandlerMiddlewareResolver $middleware = new HandlerMiddlewares(),
        private FailureClassifiers $classifiers = new FailureClassifiers(),
        RuntimeObserver $observer = new NullRuntimeObserver(),
        ObserverFailureSink $observerFailureSink = new NullObserverFailureSink(),
        private ClockInterface $clock = new SystemClock(),
        private ?IdempotencyRuntime $idempotency = null,
        RuntimeFailureSink $runtimeFailureSink = new NullRuntimeFailureSink(),
    ) {
        $this->observer = new BestEffortRuntimeObserver($observer, $observerFailureSink);
        $this->failureSink = new BestEffortRuntimeFailureSink($runtimeFailureSink, $observerFailureSink);
    }

    public function claim(WorkerProfile $profile, string $workerId): ?ClaimedDelivery
    {
        return $this->backend->claim($profile->lane, $workerId);
    }

    public function heartbeat(ClaimedDelivery $claim): bool
    {
        return $this->backend->heartbeat($claim);
    }

    public function runOnce(WorkerProfile $profile, string $workerId): WorkerIteration
    {
        $claim = $this->claim($profile, $workerId);

        return $claim instanceof ClaimedDelivery
            ? $this->execute($claim, workerId: $workerId, profile: $profile->name)
            : WorkerIteration::Idle;
    }

    public function execute(
        ClaimedDelivery $claim,
        ?int $childPid = null,
        string $workerId = 'unknown',
        string $profile = 'unknown',
    ): WorkerIteration {
        $started = hrtime(true);
        $stage = FailureStage::PayloadDecode;
        if (!$this->backend->markHandlerStarted($claim, $childPid)) {
            return WorkerIteration::LostLease;
        }
        $this->observer->observe(new RuntimeEvent(
            RuntimeEventType::AttemptStarted,
            $this->clock->now(),
            $claim->delivery->metadata->messageId,
            $claim->delivery->deliveryId,
            $claim->delivery->metadata->correlationId,
            $claim->delivery->metadata->causationId,
            $claim->attempt->attemptId,
            $claim->delivery->payload->messageName,
            $claim->delivery->targetId,
            workerId: $workerId,
            route: $claim->delivery->lane,
            profile: $profile,
            attemptNumber: $claim->attempt->number,
        ));

        try {
            try {
                $message = $this->codecs->decode($claim->delivery->payload);
                $stage = FailureStage::TargetResolution;
                $binding = $this->binding($claim, $this->registry->handlersFor($message));
            } catch (\Throwable $failure) {
                throw new IncompatibleDeliveryFailure(
                    'Persisted delivery cannot be decoded or matched to its compiled target.',
                    0,
                    $failure,
                );
            }
            $signal = new DeliveryCancellationSignal($this->backend, $claim);
            $stage = FailureStage::ContextCreation;
            $contextType = $binding->contextClass ?? MessageContext::class;
            $context = $this->contextFactories->factory($contextType)->create(new MessageContextSeed(
                $this->dispatcher,
                $claim->delivery->metadata,
                $signal,
            ));

            $stage = FailureStage::HandlerResolution;
            $service = $this->handlers->get($binding->handlerClass);
            $method = new \ReflectionMethod($service, $binding->method);
            $handler = $binding->contextClass === null
                ? static fn(): mixed => $method->invoke($service, $message)
                : static fn(): mixed => $method->invoke($service, $message, $context);
            $middleware = $this->middleware->for(
                $binding,
                $this->handlers,
                $claim->delivery->policy,
            );
            $invoke = static fn(): mixed => (new HandlerPipeline($middleware))
                ->invoke($message, $context, $handler);
            $stage = FailureStage::HandlerExecution;
            if ($claim->delivery->policy->idempotent) {
                $stage = FailureStage::Idempotency;
                $idempotency = $this->idempotency
                    ?? throw new TerminalDeliveryFailure(
                        'Idempotent delivery target has no AtomicIdempotencyBoundary.',
                    );
                $identity = $idempotency->identities->resolve($message, $binding->id);
                if ($identity->targetId !== $binding->id) {
                    throw new TerminalDeliveryFailure('Idempotency resolver returned another target identity.');
                }
                $idempotency->boundary->execute(
                    new IdempotencyAttempt(
                        $identity,
                        $claim->delivery->deliveryId,
                        $claim->attempt->startedAt->modify(
                            '+' . $claim->delivery->policy->idempotencyRetentionSeconds . ' seconds',
                        ),
                    ),
                    static function () use ($invoke): void {
                        $invoke();
                    },
                );
            } else {
                $invoke();
            }

            if (!$this->backend->succeed($claim)) {
                return WorkerIteration::LostLease;
            }
            $this->observer->observe(new RuntimeEvent(
                RuntimeEventType::AttemptSucceeded,
                $this->clock->now(),
                $claim->delivery->metadata->messageId,
                $claim->delivery->deliveryId,
                $claim->delivery->metadata->correlationId,
                $claim->delivery->metadata->causationId,
                $claim->attempt->attemptId,
                $claim->delivery->payload->messageName,
                $claim->delivery->targetId,
                workerId: $workerId,
                route: $claim->delivery->lane,
                profile: $profile,
                attemptNumber: $claim->attempt->number,
                durationMicroseconds: $this->durationMicroseconds($started),
                outcome: 'succeeded',
            ));

            return WorkerIteration::Succeeded;
        } catch (\Throwable $failure) {
            $handlerResolutionFailure = $stage === FailureStage::HandlerResolution ? $failure : null;
            $builtInCategory = (new DefaultFailureClassifier())->classify($failure);
            $classification = $builtInCategory instanceof FailureCategory
                ? new FailureClassification($builtInCategory)
                : $this->classifiers->classifyDetailed($failure);
            $category = $classification->category;
            $primaryDiagnostic = FailureDiagnostic::fromThrowable($stage, $failure, $failure->getPrevious());
            $primaryEvent = $this->failureEvent(
                $claim,
                $workerId,
                $profile,
                $category,
                $primaryDiagnostic,
                $started,
            );
            $this->failureSink->report($failure, $primaryEvent);

            $diagnostic = $primaryDiagnostic;
            if ($classification->classifierFailure instanceof \Throwable) {
                $diagnostic = FailureDiagnostic::fromThrowable(
                    FailureStage::FailureClassification,
                    $classification->classifierFailure,
                    $failure,
                );
                $this->failureSink->report(
                    $classification->classifierFailure,
                    $this->failureEvent($claim, $workerId, $profile, $category, $diagnostic, $started),
                );
            }

            try {
                $settled = $this->backend->fail($claim, $category, $diagnostic->encode());
            } catch (\Throwable $settlementFailure) {
                $settlementDiagnostic = FailureDiagnostic::fromThrowable(
                    FailureStage::DeliverySettlement,
                    $settlementFailure,
                    $failure,
                );
                $this->failureSink->report(
                    $settlementFailure,
                    $this->failureEvent(
                        $claim,
                        $workerId,
                        $profile,
                        FailureCategory::OutcomeUnknown,
                        $settlementDiagnostic,
                        $started,
                    ),
                );

                throw new DeliverySettlementFailed(
                    $claim->delivery->deliveryId,
                    $claim->attempt->attemptId,
                    $failure,
                    $settlementFailure,
                );
            }
            if (!$settled) {
                if ($handlerResolutionFailure instanceof \Throwable) {
                    throw $handlerResolutionFailure;
                }

                return WorkerIteration::LostLease;
            }
            $this->observer->observe(
                $this->failureEvent($claim, $workerId, $profile, $category, $diagnostic, $started),
            );

            if ($handlerResolutionFailure instanceof \Throwable) {
                throw $handlerResolutionFailure;
            }

            return WorkerIteration::Failed;
        }
    }

    public function forkFailed(ClaimedDelivery $claim): WorkerIteration
    {
        $diagnostic = new FailureDiagnostic(FailureStage::WorkerFork, \RuntimeException::class);
        $settled = $this->backend->fail(
            $claim,
            FailureCategory::Retryable,
            $diagnostic->encode(),
        );

        return $settled ? WorkerIteration::Failed : WorkerIteration::LostLease;
    }

    public function cancellationRequested(ClaimedDelivery $claim): bool
    {
        return $this->backend->cancellationRequested($claim);
    }

    /** @param list<HandlerBinding> $bindings */
    private function binding(ClaimedDelivery $claim, array $bindings): HandlerBinding
    {
        foreach ($bindings as $binding) {
            if ($binding->id === $claim->delivery->targetId
                && $binding->handlerClass === $claim->delivery->handlerClass
                && $binding->method === $claim->delivery->handlerMethod) {
                return $binding;
            }
        }

        throw new TerminalDeliveryFailure('Compiled target no longer matches the accepted delivery.');
    }

    private function durationMicroseconds(int $started): int
    {
        return max(0, (int) ((hrtime(true) - $started) / 1_000));
    }

    private function failureEvent(
        ClaimedDelivery $claim,
        string $workerId,
        string $profile,
        FailureCategory $category,
        FailureDiagnostic $diagnostic,
        int $started,
    ): RuntimeEvent {
        return new RuntimeEvent(
            RuntimeEventType::AttemptFailed,
            $this->clock->now(),
            $claim->delivery->metadata->messageId,
            $claim->delivery->deliveryId,
            $claim->delivery->metadata->correlationId,
            $claim->delivery->metadata->causationId,
            $claim->attempt->attemptId,
            $claim->delivery->payload->messageName,
            $claim->delivery->targetId,
            workerId: $workerId,
            route: $claim->delivery->lane,
            profile: $profile,
            attemptNumber: $claim->attempt->number,
            durationMicroseconds: $this->durationMicroseconds($started),
            outcome: 'failed',
            failureCategory: $category->value,
            failureClass: $diagnostic->failureClass,
            errorFingerprint: $diagnostic->fingerprint,
            failureCode: $diagnostic->code,
            failureStage: $diagnostic->stage->value,
            failureSummary: $diagnostic->summary,
            relatedFailureClass: $diagnostic->relatedFailureClass,
        );
    }
}

/**
 *  PCNTL parent supervisor: one fresh child process per claimed attempt.
 *
 * @api adapter
 */
final class PcntlWorkerSupervisor
{
    private bool $stopRequested = false;

    /**
     * The child factory must create fresh process-local connections after fork.
     */
    public function __construct(
        private readonly DeliveryWorkerRuntime $runtime,
        private readonly ChildExecutionFactory $childRuntimeFactory,
        private readonly ?WorkerControlOperations $control = null,
        private readonly string $buildId = 'unknown',
        private readonly string $registryHash = '0000000000000000000000000000000000000000000000000000000000000000',
        private readonly WorkerProcessSystem $process = new NativeWorkerProcessSystem(),
    ) {
        if ($this->buildId === '') {
            throw new \InvalidArgumentException('Worker supervisor buildId must not be empty.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->registryHash) !== 1) {
            throw new \InvalidArgumentException(
                'Worker supervisor registryHash must be a lowercase SHA-256 digest.',
            );
        }
    }

    /**
     * Runs until SIGTERM/SIGINT or the optional process-local stop callback.
     *
     * @param (callable(): bool)|null $shouldStop
     */
    public function run(WorkerProfile $profile, ?callable $shouldStop = null): void
    {
        if (!$this->process->available()) {
            throw new \RuntimeException('PCNTL supervisor requires ext-pcntl and ext-posix.');
        }
        $this->process->installStopSignals(function (): void {
            $this->stopRequested = true;
        });

        /** @var array<int, array{claim: ClaimedDelivery, heartbeat: float, started: float, term_sent: float|null}> $children */
        $children = [];
        $workerId = $profile->name . ':' . $this->process->currentPid();
        $startedAt = new \DateTimeImmutable();
        $startedMonotonic = $this->process->monotonicTime();
        $handledAttempts = 0;

        while (!$this->stopRequested && ($shouldStop === null || !$shouldStop())) {
            $handledAttempts += $this->reap($children);
            $this->heartbeat($children, $profile);
            if (($profile->maxHandledAttempts > 0 && $handledAttempts >= $profile->maxHandledAttempts)
                || ($profile->maxRuntimeSeconds > 0
                    && $this->process->monotonicTime() - $startedMonotonic >= $profile->maxRuntimeSeconds)
                || ($profile->maxMemoryBytes > 0 && memory_get_usage(true) >= $profile->maxMemoryBytes)) {
                break;
            }

            [$paused, $globalRevision, $profileRevision] = $this->controlSnapshot($profile);
            $this->report(
                $workerId,
                $profile,
                $startedAt,
                $paused ? 'paused' : 'running',
                \count($children),
                $globalRevision,
                $profileRevision,
            );

            $empty = false;

            while (!$paused && \count($children) < $profile->parallelism) {
                $claim = $this->runtime->claim($profile, $workerId);
                if (!$claim instanceof ClaimedDelivery) {
                    $empty = true;
                    break;
                }
                if ($profile->heartbeatMicroseconds > ($claim->delivery->policy->leaseSeconds * 1_000_000) / 3) {
                    $this->runtime->forkFailed($claim);

                    throw new \InvalidArgumentException('Worker heartbeat must not exceed one third of the attempt lease.');
                }
                $pid = $this->process->fork();
                if ($pid === -1) {
                    if ($this->runtime->forkFailed($claim) === WorkerIteration::LostLease) {
                        throw new \RuntimeException('Unable to settle MessageBus worker fork failure.');
                    }

                    continue;
                }
                if ($pid === 0) {
                    $exitCode = 1;

                    try {
                        $result = $this->childRuntimeFactory->createAfterFork()->execute(
                            $claim,
                            $this->process->currentPid(),
                            $workerId,
                            $profile->name,
                        );
                        $exitCode = $result === WorkerIteration::Succeeded ? 0 : 1;
                    } catch (\Throwable $failure) {
                        fwrite(STDERR, $failure . PHP_EOL);
                    }
                    // A normal PHP shutdown would destruct inherited parent resources (notably PDO)
                    // and can send a protocol-level disconnect over the parent's still-live socket.
                    // The single-attempt child has already settled its delivery through fresh resources,
                    // so terminate without running the inherited object graph's destructors.
                    $this->process->signal($this->process->currentPid(), SIGKILL);
                    exit($exitCode);
                }
                $children[$pid] = [
                    'claim' => $claim,
                    'heartbeat' => $this->process->monotonicTime(),
                    'started' => $this->process->monotonicTime(),
                    'term_sent' => null,
                ];
            }
            if ($profile->stopWhenEmpty && $empty && $children === []) {
                break;
            }

            $this->process->sleep($profile->pollMicroseconds);
        }

        $deadline = $this->process->monotonicTime() + $profile->shutdownGraceSeconds;

        while ($children !== [] && $this->process->monotonicTime() < $deadline) {
            $this->reap($children);
            $this->heartbeat($children, $profile);
            [, $globalRevision, $profileRevision] = $this->controlSnapshot($profile);
            $this->report(
                $workerId,
                $profile,
                $startedAt,
                'draining',
                \count($children),
                $globalRevision,
                $profileRevision,
            );
            $this->process->sleep(min($profile->pollMicroseconds, 100_000));
        }
        foreach (array_keys($children) as $pid) {
            $this->process->signal($pid, SIGTERM);
            $children[$pid]['term_sent'] = $this->process->monotonicTime();
        }
        $killDeadline = $this->process->monotonicTime() + $profile->forceKillGraceSeconds;

        while ($children !== [] && $this->process->monotonicTime() < $killDeadline) {
            $this->reap($children);
            $this->process->sleep(min($profile->pollMicroseconds, 100_000));
        }
        foreach (array_keys($children) as $pid) {
            $this->process->signal($pid, SIGKILL);
        }
        $this->reap($children);
        [, $globalRevision, $profileRevision] = $this->controlSnapshot($profile);
        $this->report(
            $workerId,
            $profile,
            $startedAt,
            'stopped',
            \count($children),
            $globalRevision,
            $profileRevision,
        );
    }

    private function report(
        string $workerId,
        WorkerProfile $profile,
        \DateTimeImmutable $startedAt,
        string $state,
        int $activeAttempts,
        int $globalRevision,
        int $profileRevision,
    ): void {
        $reportedAt = new \DateTimeImmutable();
        $this->control?->report(new WorkerStatus(
            $workerId,
            $profile->name,
            $profile->lane,
            $this->process->currentPid(),
            $state,
            $activeAttempts,
            $startedAt,
            $reportedAt,
            $this->process->host(),
            $this->buildId,
            $this->registryHash,
            $profile->parallelism,
            $globalRevision,
            $profileRevision,
            $state === 'stopped' ? $reportedAt : null,
        ));
    }

    /** @return array{bool, int, int} */
    private function controlSnapshot(WorkerProfile $profile): array
    {
        if (!$this->control instanceof WorkerControlOperations) {
            return [false, 0, 0];
        }
        $global = $this->control->state(WorkerControlScope::global());
        $profileState = $this->control->state(WorkerControlScope::profile($profile->name));

        return [
            $global->desiredState === WorkerDesiredState::Paused
                || $profileState->desiredState === WorkerDesiredState::Paused,
            $global->revision,
            $profileState->revision,
        ];
    }

    /** @param array<int, array{claim: ClaimedDelivery, heartbeat: float, started: float, term_sent: float|null}> $children */
    private function reap(array &$children): int
    {
        $reaped = 0;
        foreach (array_keys($children) as $pid) {
            if ($this->process->waitNoHang($pid)) {
                unset($children[$pid]);
                ++$reaped;
            }
        }

        return $reaped;
    }

    /** @param array<int, array{claim: ClaimedDelivery, heartbeat: float, started: float, term_sent: float|null}> $children */
    private function heartbeat(array &$children, WorkerProfile $profile): void
    {
        $now = $this->process->monotonicTime();
        foreach ($children as $pid => &$child) {
            $deadlineExceeded = $now - $child['started'] >= $child['claim']->delivery->policy->maxElapsedSeconds;
            $cancelRequested = $this->runtime->cancellationRequested($child['claim']);
            if (($deadlineExceeded || $cancelRequested) && $child['term_sent'] === null) {
                $this->process->signal($pid, SIGTERM);
                $child['term_sent'] = $now;

                continue;
            }
            if ($child['term_sent'] !== null && $now - $child['term_sent'] >= $profile->forceKillGraceSeconds) {
                $this->process->signal($pid, SIGKILL);

                continue;
            }
            if (($now - $child['heartbeat']) * 1_000_000 < $profile->heartbeatMicroseconds) {
                continue;
            }
            if (!$this->runtime->heartbeat($child['claim'])) {
                $this->process->signal($pid, SIGTERM);
            }
            $child['heartbeat'] = $now;
        }
        unset($child);
    }
}
