<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Internal\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresSchemaManager;
use Wolfcharaa\MessageBus\Delivery\CancellationReceipt;
use Wolfcharaa\MessageBus\Delivery\ControlActor;
use Wolfcharaa\MessageBus\Delivery\DeliveryMaintenance;
use Wolfcharaa\MessageBus\Delivery\DeliveryOperations;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryStatus;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyMaintenance;
use Wolfcharaa\MessageBus\Identity\DeliveryId;
use Wolfcharaa\MessageBus\Registry\RegistryArtifact;
use Wolfcharaa\MessageBus\Registry\RegistryArtifactStore;
use Wolfcharaa\MessageBus\Registry\RegistryClassSource;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;
use Wolfcharaa\MessageBus\Registry\RegistryDiagnostic;
use Wolfcharaa\MessageBus\Worker\PcntlWorkerSupervisor;
use Wolfcharaa\MessageBus\Worker\WorkerControlOperations;
use Wolfcharaa\MessageBus\Worker\WorkerControlOutcome;
use Wolfcharaa\MessageBus\Worker\WorkerControlScope;
use Wolfcharaa\MessageBus\Worker\WorkerProfile;
use Wolfcharaa\MessageBus\Worker\WorkerStatus;
use Wolfcharaa\MessageBus\Worker\WorkerStatusFilter;

/** @internal */
abstract class JsonCommand extends Command
{
    protected function configureFormat(): void
    {
        $this->addOption('format', null, InputOption::VALUE_REQUIRED, 'text|json', 'text');
    }

    /** @param array<string, mixed> $payload */
    protected function result(InputInterface $input, OutputInterface $output, array $payload): void
    {
        $document = ['schema' => 2, 'command' => (string) $this->getName()] + $payload;
        if ($input->getOption('format') === 'json') {
            $output->writeln(json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return;
        }
        foreach ($document as $key => $value) {
            $output->writeln($key . ': ' . (\is_scalar($value) ? (string) $value : json_encode($value, JSON_THROW_ON_ERROR)));
        }
    }

    /** @return array{error: array{code: string, type: string, stage: string, message: string, exception_type: class-string, subject: array{type: string, id: string}|null}} */
    protected function failurePayload(
        \Throwable $failure,
        string $stage,
        ?string $subjectType = null,
        ?string $subjectId = null,
    ): array {
        [$code, $type, $message] = match (true) {
            $failure instanceof \InvalidArgumentException, $failure instanceof \ValueError => [
                'MBCLI001',
                'invalid_input',
                'Command input validation failed.',
            ],
            $failure instanceof \LogicException => [
                'MBCLI002',
                'state_conflict',
                'Command cannot be applied to the current retained state.',
            ],
            $failure instanceof \PDOException => [
                'MBCLI003',
                'backend_failure',
                'Command backend operation failed.',
            ],
            default => [
                'MBCLI004',
                'internal_failure',
                'Command failed unexpectedly.',
            ],
        };

        return ['error' => [
            'code' => $code,
            'type' => $type,
            'stage' => $stage,
            'message' => $this->detailedFailureMessage(
                $code,
                $message,
                $stage,
                $failure::class,
                $subjectType,
                $subjectId,
            ),
            'exception_type' => $failure::class,
            'subject' => $subjectType !== null && $subjectId !== null
                ? ['type' => $subjectType, 'id' => $subjectId]
                : null,
        ]];
    }

    /** @param class-string $failureClass */
    private function detailedFailureMessage(
        string $code,
        string $summary,
        string $stage,
        string $failureClass,
        ?string $subjectType,
        ?string $subjectId,
    ): string {
        $parts = [
            '[' . $code . '] ' . $summary,
            'Stage: ' . $stage,
        ];
        if ($subjectType !== null && $subjectId !== null) {
            $parts[] = 'Subject: ' . $subjectType . ' "' . $subjectId . '"';
        }
        $parts[] = 'Exception type: ' . $failureClass;

        return implode(' ', array_map(
            static fn(string $part): string => rtrim($part, ". \t\n\r\0\x0B") . '.',
            $parts,
        ));
    }

    protected function failure(
        InputInterface $input,
        OutputInterface $output,
        \Throwable $failure,
        string $stage,
        ?string $subjectType = null,
        ?string $subjectId = null,
    ): void {
        $this->result($input, $output, $this->failurePayload(
            $failure,
            $stage,
            $subjectType,
            $subjectId,
        ));
    }

    protected function rawOptionString(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    protected function optionString(InputInterface $input, string $name, bool $required = false): ?string
    {
        $value = $input->getOption($name);
        if ($value === null && !$required) {
            return null;
        }
        if (!\is_string($value) || ($required && $value === '')) {
            throw new \InvalidArgumentException('--' . $name . ' must be a non-empty string.');
        }

        return $value;
    }

    protected function optionInt(InputInterface $input, string $name): int
    {
        $value = $input->getOption($name);
        if (!\is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new \InvalidArgumentException('--' . $name . ' must be an integer.');
        }

        return (int) $value;
    }

    protected function optionBool(InputInterface $input, string $name): bool
    {
        $value = $input->getOption($name);
        if (!\is_bool($value)) {
            throw new \InvalidArgumentException('--' . $name . ' must be a flag.');
        }

        return $value;
    }
}

/** @internal */
final class RegistryCheckCommand extends JsonCommand
{
    public function __construct(
        private readonly RegistryCompiler $compiler,
        private readonly RegistryClassSource $source,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('registry:check')->setDescription('Validate the MessageBus registry.');
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->compiler->compile($this->source);
            $this->result($input, $output, [
                'valid' => $result->isValid(),
                'diagnostics' => array_map(
                    static fn(RegistryDiagnostic $diagnostic): array => $diagnostic->normalized(),
                    $result->diagnostics,
                ),
            ]);

            return $result->isValid() ? Command::SUCCESS : 2;
        } catch (\Throwable $failure) {
            $this->result($input, $output, ['valid' => false] + $this->failurePayload(
                $failure,
                'registry.check',
                'registry',
                'application',
            ));

            return 2;
        }
    }
}

/** @internal */
final class RegistryCompileCommand extends JsonCommand
{
    public function __construct(
        private readonly RegistryCompiler $compiler,
        private readonly RegistryClassSource $source,
        private readonly RegistryArtifactStore $store,
        private readonly string $path,
        private readonly ?string $applicationBuildId = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('registry:compile')->setDescription('Compile and atomically write the registry artifact.');
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $result = $this->compiler->compile($this->source);
            if (!$result->isValid()) {
                $this->result($input, $output, [
                    'written' => false,
                    'diagnostic_count' => \count($result->diagnostics),
                    'diagnostics' => array_map(
                        static fn(RegistryDiagnostic $diagnostic): array => $diagnostic->normalized(),
                        $result->diagnostics,
                    ),
                ]);

                return 2;
            }
            $artifact = RegistryArtifact::fromRegistry(
                $result->requireRegistry(),
                $this->applicationBuildId,
            );
            $this->store->write($artifact, $this->path);
            $this->result($input, $output, [
                'written' => true,
                'path' => $this->path,
                'content_hash' => $artifact->contentHash,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $this->result($input, $output, ['written' => false] + $this->failurePayload(
                $failure,
                'registry.compile',
                'registry_artifact',
                $this->path,
            ));

            return 2;
        }
    }
}

/** @internal */
final class SchemaValidateCommand extends JsonCommand
{
    public function __construct(private readonly PostgresSchemaManager $schema)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('schema:validate')->setDescription('Validate the installed MessageBus schema manifest.');
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $this->schema->validate();
            $this->schema->validateCatalog();
            $this->result($input, $output, [
                'valid' => true,
                'installation_fingerprint' => $this->schema->installationFingerprint(),
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $this->result($input, $output, ['valid' => false] + $this->failurePayload(
                $failure,
                'schema.validate',
                'schema',
                'message_bus',
            ));

            return 4;
        }
    }
}

/** @internal */
final class DeliveryStatusCommand extends JsonCommand
{
    public function __construct(private readonly DeliveryOperations $operations)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('delivery:status')->setDescription('Read one retained delivery status.')
            ->addOption('delivery-id', null, InputOption::VALUE_REQUIRED, 'Opaque DeliveryId');
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $status = $this->operations->status(new DeliveryId(
                (string) $this->optionString($input, 'delivery-id', true),
            ));
            $this->result($input, $output, [
                'found' => $status instanceof DeliveryStatus,
                'delivery' => $status instanceof DeliveryStatus ? $this->statusPayload($status) : null,
            ]);

            return $status instanceof DeliveryStatus ? Command::SUCCESS : 3;
        } catch (\Throwable $failure) {
            $this->failure(
                $input,
                $output,
                $failure,
                'delivery.status',
                'delivery',
                $this->rawOptionString($input, 'delivery-id'),
            );

            return 2;
        }
    }

    /** @return array<string, bool|int|string|array<string, string|null>|null> */
    private function statusPayload(DeliveryStatus $status): array
    {
        return [
            'delivery_id' => $status->deliveryId->value,
            'message_id' => $status->messageId->value,
            'target_id' => $status->targetId,
            'state' => $status->state->value,
            'attempts' => $status->attempts,
            'updated_at' => $status->updatedAt->format(DATE_ATOM),
            'last_failure_category' => $status->lastFailureCategory,
            'last_failure_attempt_id' => $status->lastFailureAttemptId?->value,
            'last_failure' => $status->lastFailure?->normalized(),
        ];
    }
}

/** @internal */
final class DeliveryCancelCommand extends JsonCommand
{
    public function __construct(private readonly DeliveryOperations $operations)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('delivery:cancel')->setDescription('Request idempotent delivery cancellation.')
            ->addOption('delivery-id', null, InputOption::VALUE_REQUIRED, 'Opaque DeliveryId')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Trusted actor')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Required audit reason')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Optional audit reference');
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $receipt = $this->operations->cancel(
                new DeliveryId((string) $this->optionString($input, 'delivery-id', true)),
                $this->actor($input),
            );
            $this->result($input, $output, $this->receiptPayload($receipt));

            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $this->failure(
                $input,
                $output,
                $failure,
                'delivery.cancel',
                'delivery',
                $this->rawOptionString($input, 'delivery-id'),
            );

            return 2;
        }
    }

    private function actor(InputInterface $input): ControlActor
    {
        return new ControlActor(
            (string) $this->optionString($input, 'actor', true),
            (string) $this->optionString($input, 'reason', true),
            $this->optionString($input, 'reference'),
        );
    }

    /** @return array<string, string> */
    private function receiptPayload(CancellationReceipt $receipt): array
    {
        return [
            'delivery_id' => $receipt->deliveryId->value,
            'outcome' => $receipt->outcome->value,
            'recorded_at' => $receipt->recordedAt->format(DATE_ATOM),
        ];
    }
}

/** @internal */
final class DeliveryReplayCommand extends JsonCommand
{
    public function __construct(
        private readonly DeliveryOperations $operations,
        private readonly DeliveryPolicies $policies,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('delivery:replay')->setDescription('Replay one retained unsuccessful terminal delivery.')
            ->addOption('delivery-id', null, InputOption::VALUE_REQUIRED, 'Opaque DeliveryId')
            ->addOption('policy', null, InputOption::VALUE_REQUIRED, 'Configured policy name')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Trusted actor')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Required audit reason')
            ->addOption('reference', null, InputOption::VALUE_REQUIRED, 'Optional audit reference');
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $policy = $this->policies->get((string) $this->optionString($input, 'policy', true));
            $receipt = $this->operations->replay(
                new DeliveryId((string) $this->optionString($input, 'delivery-id', true)),
                $policy,
                new ControlActor(
                    (string) $this->optionString($input, 'actor', true),
                    (string) $this->optionString($input, 'reason', true),
                    $this->optionString($input, 'reference'),
                ),
            );
            $this->result($input, $output, [
                'original_delivery_id' => $receipt->originalDeliveryId->value,
                'new_delivery_id' => $receipt->newDeliveryId->value,
                'policy' => $receipt->policy->name,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $this->failure(
                $input,
                $output,
                $failure,
                'delivery.replay',
                'delivery',
                $this->rawOptionString($input, 'delivery-id'),
            );

            return 3;
        }
    }
}

/** @internal */
final class DeliveryRecoverCommand extends JsonCommand
{
    public function __construct(private readonly DeliveryMaintenance $maintenance)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('delivery:recover')->setDescription('Recover a bounded batch of expired attempts.')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Bounded batch size', '100');
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $report = $this->maintenance->recoverExpired($this->optionInt($input, 'limit'));
            $this->result($input, $output, [
                'examined' => $report->examined,
                'changed' => $report->changed,
                'has_more' => $report->hasMore,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $this->failure($input, $output, $failure, 'delivery.recover');

            return 2;
        }
    }
}

/** @internal */
final class DeliveryPruneCommand extends JsonCommand
{
    public function __construct(private readonly DeliveryMaintenance $maintenance)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('delivery:prune')->setDescription('Plan or execute bounded terminal delivery retention.')
            ->addOption('terminal-before', null, InputOption::VALUE_REQUIRED, 'ISO-8601 terminal cutoff')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Bounded batch size', '100')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Apply deletion; default is dry-run');
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $execute = $this->optionBool($input, 'execute');
            $report = $this->maintenance->prune(
                new \DateTimeImmutable((string) $this->optionString($input, 'terminal-before', true)),
                $this->optionInt($input, 'batch-size'),
                $execute,
            );
            $this->result($input, $output, [
                'examined' => $report->examined,
                'changed' => $report->changed,
                'executed' => $execute,
                'has_more' => $report->hasMore,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $this->failure($input, $output, $failure, 'delivery.prune');

            return 2;
        }
    }
}

/** @internal */
final class IdempotencyPruneCommand extends JsonCommand
{
    public function __construct(private readonly IdempotencyMaintenance $maintenance)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('idempotency:prune')->setDescription('Plan or prune completed expired idempotency records.')
            ->addOption('at', null, InputOption::VALUE_REQUIRED, 'ISO-8601 retention clock')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Bounded batch size', '100')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Apply deletion; default is dry-run');
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $execute = $this->optionBool($input, 'execute');
            $report = $this->maintenance->pruneExpired(
                new \DateTimeImmutable((string) $this->optionString($input, 'at', true)),
                $this->optionInt($input, 'batch-size'),
                $execute,
            );
            $this->result($input, $output, [
                'examined' => $report->examined,
                'changed' => $report->changed,
                'executed' => $execute,
                'has_more' => $report->hasMore,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $this->failure($input, $output, $failure, 'idempotency.prune');

            return 2;
        }
    }
}

/** @internal */
final class WorkerRunCommand extends JsonCommand
{
    public function __construct(
        private readonly PcntlWorkerSupervisor $supervisor,
        private readonly WorkerProfile $profile,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('worker:run')
            ->setDescription('Run the configured PCNTL worker profile.')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Configured profile name', $this->profile->name);
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $profile = $this->optionString($input, 'profile', true);
            if ($profile !== $this->profile->name) {
                throw new \InvalidArgumentException(
                    'Configured worker profile is ' . $this->profile->name . '; received ' . $profile . '.',
                );
            }
            $this->supervisor->run($this->profile);
            $this->result($input, $output, [
                'profile' => $this->profile->name,
                'outcome' => 'stopped',
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $this->failure(
                $input,
                $output,
                $failure,
                'worker.run',
                'worker_profile',
                $this->rawOptionString($input, 'profile'),
            );

            return 2;
        }
    }
}

/** @internal */
final class WorkerStatusCommand extends JsonCommand
{
    public function __construct(private readonly WorkerControlOperations $control)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('worker:status')->setDescription('Show reported worker instances.')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Filter by profile')
            ->addOption('instance', null, InputOption::VALUE_REQUIRED, 'Filter by exact worker instance')
            ->addOption('stale-after', null, InputOption::VALUE_REQUIRED, 'Liveness horizon in seconds', '30');
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $statuses = $this->control->statuses(new WorkerStatusFilter(
                $this->optionString($input, 'profile'),
                $this->optionString($input, 'instance'),
                $this->optionInt($input, 'stale-after'),
            ));
            $this->result($input, $output, ['workers' => array_map(static fn(WorkerStatus $status): array => [
                'worker_id' => $status->workerId,
                'profile' => $status->profile,
                'lane' => $status->lane,
                'pid' => $status->pid,
                'state' => $status->state,
                'active_attempts' => $status->activeAttempts,
                'capacity' => $status->capacity,
                'host' => $status->host,
                'build_id' => $status->buildId,
                'registry_hash' => $status->registryHash,
                'applied_global_revision' => $status->appliedGlobalRevision,
                'applied_profile_revision' => $status->appliedProfileRevision,
                'required_global_revision' => $status->requiredGlobalRevision,
                'required_profile_revision' => $status->requiredProfileRevision,
                'effective_desired_state' => $status->effectiveDesiredState->value,
                'activity' => $status->activity(),
                'stale' => $status->stale,
                'drained' => $status->drained,
                'started_at' => $status->startedAt->format(DATE_ATOM),
                'heartbeat_at' => $status->heartbeatAt->format(DATE_ATOM),
                'stopped_at' => $status->stoppedAt?->format(DATE_ATOM),
            ], $statuses)]);

            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $this->failure($input, $output, $failure, 'worker.status');

            return 2;
        }
    }
}

/** @internal */
abstract class WorkerControlMutationCommand extends JsonCommand
{
    public function __construct(protected readonly WorkerControlOperations $control)
    {
        parent::__construct();
    }

    protected function configureMutation(string $name, string $description): void
    {
        $this->setName($name)->setDescription($description)
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'Profile scope')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Global scope')
            ->addOption('reason', null, InputOption::VALUE_REQUIRED, 'Required audit reason')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Trusted actor', 'cli')
            ->addOption('idempotency-key', null, InputOption::VALUE_REQUIRED, 'Required stable request key')
            ->addOption('expected-revision', null, InputOption::VALUE_REQUIRED, 'Optional CAS revision');
        $this->configureFormat();
    }

    protected function scope(InputInterface $input): WorkerControlScope
    {
        $profile = $this->optionString($input, 'profile');
        $all = $this->optionBool($input, 'all');
        if ($all === ($profile !== null)) {
            throw new \InvalidArgumentException('Choose exactly one of --all or --profile.');
        }

        return $all ? WorkerControlScope::global() : WorkerControlScope::profile((string) $profile);
    }

    /** @return array{code: int, payload: array<string, mixed>, scope: WorkerControlScope|null} */
    protected function mutation(InputInterface $input, bool $pause): array
    {
        try {
            $scope = $this->scope($input);
            $expected = $this->optionString($input, 'expected-revision');
            $receipt = $pause
                ? $this->control->pause(
                    $scope,
                    (string) $this->optionString($input, 'actor', true),
                    (string) $this->optionString($input, 'reason', true),
                    (string) $this->optionString($input, 'idempotency-key', true),
                    $expected === null ? null : (int) $expected,
                )
                : $this->control->resume(
                    $scope,
                    (string) $this->optionString($input, 'actor', true),
                    (string) $this->optionString($input, 'reason', true),
                    (string) $this->optionString($input, 'idempotency-key', true),
                    $expected === null ? null : (int) $expected,
                );
            $payload = [
                'scope' => $receipt->state->scope->value,
                'desired_state' => $receipt->state->desiredState->value,
                'revision' => $receipt->state->revision,
                'outcome' => $receipt->outcome->value,
                'operation_id' => $receipt->operationId,
                'accepted_at' => $receipt->acceptedAt?->format(DATE_ATOM),
                'previous_desired_state' => $receipt->previousDesiredState?->value,
                'duplicate' => $receipt->duplicate,
            ];

            return [
                'code' => $receipt->outcome === WorkerControlOutcome::RevisionConflict ? 3 : Command::SUCCESS,
                'payload' => $payload,
                'scope' => $scope,
            ];
        } catch (\Throwable $failure) {
            return [
                'code' => 2,
                'payload' => $this->failurePayload(
                    $failure,
                    $pause ? 'worker.pause' : 'worker.resume',
                ),
                'scope' => null,
            ];
        }
    }
}

/** @internal */
final class WorkerPauseCommand extends WorkerControlMutationCommand
{
    protected function configure(): void
    {
        $this->configureMutation('worker:pause', 'Pause claims globally or for one profile.');
        $this->addOption('wait', null, InputOption::VALUE_NONE, 'Wait until matching workers report zero active attempts')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Wait timeout in seconds', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->mutation($input, true);

        try {
            if ($result['code'] === 0 && $this->optionBool($input, 'wait') && $result['scope'] !== null) {
                $deadline = microtime(true) + max(1, $this->optionInt($input, 'timeout'));
                $profile = str_starts_with($result['scope']->value, 'profile:')
                    ? substr($result['scope']->value, \strlen('profile:'))
                    : null;
                do {
                    $statuses = $this->control->statuses(new WorkerStatusFilter($profile));
                    $drained = true;
                    foreach ($statuses as $status) {
                        if ($status->activeAttempts !== 0 || (!$status->stale && !$status->drained)) {
                            $drained = false;
                            break;
                        }
                    }
                    if ($drained) {
                        $result['payload']['drained'] = true;
                        break;
                    }
                    usleep(100_000);
                } while (microtime(true) < $deadline);
                if (($result['payload']['drained'] ?? false) !== true) {
                    $result['payload']['drained'] = false;
                    $result['code'] = 3;
                }
            }
        } catch (\Throwable $failure) {
            $result = [
                'code' => 2,
                'payload' => $this->failurePayload($failure, 'worker.pause.wait'),
                'scope' => null,
            ];
        }
        $this->result($input, $output, $result['payload']);

        return $result['code'];
    }
}

/** @internal */
final class WorkerResumeCommand extends WorkerControlMutationCommand
{
    protected function configure(): void
    {
        $this->configureMutation('worker:resume', 'Resume claims globally or for one profile.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $result = $this->mutation($input, false);
        $this->result($input, $output, $result['payload']);

        return $result['code'];
    }
}

/** @internal */
final class WorkerPruneCommand extends JsonCommand
{
    public function __construct(private readonly WorkerControlOperations $control)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('worker:prune')->setDescription('Prune stale worker facts; dry-run by default.')
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'ISO-8601 cutoff')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Bounded batch size', '100')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Apply the deletion');
        $this->configureFormat();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $report = $this->control->prune(
                new \DateTimeImmutable((string) $this->optionString($input, 'older-than', true)),
                $this->optionInt($input, 'limit'),
                $this->optionBool($input, 'execute'),
            );
            $this->result($input, $output, [
                'statuses' => $report->statuses,
                'operations' => $report->operations,
                'executed' => $report->executed,
                'has_more' => $report->hasMore,
            ]);

            return Command::SUCCESS;
        } catch (\Throwable $failure) {
            $this->failure($input, $output, $failure, 'worker.prune');

            return 2;
        }
    }
}
