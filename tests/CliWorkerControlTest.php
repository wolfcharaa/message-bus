<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use BackedEnum;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Wolfcharaa\MessageBus\Cli\ApplicationFactory;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\MessageBatchItem;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunner;
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;
use Wolfcharaa\MessageBus\Runtime\WorkerControlRuntime;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;
use Wolfcharaa\MessageBus\Worker\DefaultWorkerControlService;
use Wolfcharaa\MessageBus\Worker\WorkerActivityState;
use Wolfcharaa\MessageBus\Worker\WorkerChildInstance;
use Wolfcharaa\MessageBus\Worker\WorkerChildState;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgement;
use Wolfcharaa\MessageBus\Worker\WorkerControlBatch;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommand;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandReceipt;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;
use Wolfcharaa\MessageBus\Worker\WorkerControlCursor;
use Wolfcharaa\MessageBus\Worker\WorkerControlIdGeneratorInterface;
use Wolfcharaa\MessageBus\Worker\WorkerControlInboxInterface;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredState;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredStateRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerIdentity;
use Wolfcharaa\MessageBus\Worker\WorkerInstance;
use Wolfcharaa\MessageBus\Worker\WorkerLifecycleState;
use Wolfcharaa\MessageBus\Worker\WorkerMode;
use Wolfcharaa\MessageBus\Worker\WorkerRegistryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerStatusRepositoryInterface;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

final class CliWorkerControlTest extends TestCase
{
    public function testWorkerPauseCommandUsesControlRuntime(): void
    {
        $bootstrap = $this->bootstrap('return \\' . CliWorkerControlFactory::class . '::runtime();');
        CliWorkerControlFactory::reset();

        try {
            $tester = new CommandTester(ApplicationFactory::create()->find('worker:pause'));
            $exitCode = $tester->execute([
                '--bootstrap' => $bootstrap,
                '--group' => 'emails',
                '--created-by' => 'root',
                '--reason' => 'maintenance',
            ]);
        } finally {
            @\unlink($bootstrap);
        }

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('command=cli.pause.1 type=pause duplicate=no', $tester->getDisplay());
        self::assertSame(WorkerControlCommandType::Pause, CliWorkerControlFactory::$repository->commands[0]->type);
        self::assertSame('emails', CliWorkerControlFactory::$repository->desiredStates[0]->target->workerGroup);
    }

    public function testWorkerStatusCommandShowsWorkersAndChildren(): void
    {
        $bootstrap = $this->bootstrap('return \\' . CliWorkerControlFactory::class . '::runtimeWithWorker();');
        CliWorkerControlFactory::reset();

        try {
            $tester = new CommandTester(ApplicationFactory::create()->find('worker:status'));
            $exitCode = $tester->execute([
                '--bootstrap' => $bootstrap,
                '--children' => true,
            ]);
        } finally {
            @\unlink($bootstrap);
        }

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('instance-1', $tester->getDisplay());
        self::assertStringContainsString('emails-worker', $tester->getDisplay());
        self::assertStringContainsString('child-1', $tester->getDisplay());
    }

    public function testApplicationFactoryRegistersWorkerControlCommands(): void
    {
        $application = ApplicationFactory::create();

        foreach ([
            'worker:control',
            'worker:pause',
            'worker:resume',
            'worker:drain',
            'worker:stop',
            'worker:kill',
            'worker:restart',
            'worker:status',
            'worker:schema:postgres',
            'schema:postgres',
            'message-bus:postgres:schema:validate',
        ] as $name) {
            self::assertTrue($application->has($name));
        }
    }

    private function bootstrap(string $body): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-cli-worker-control-');
        self::assertIsString($file);
        \file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");

        return $file;
    }
}

final class CliWorkerControlFactory
{
    public static CliWorkerControlRepository $repository;

    public static function reset(): void
    {
        self::$repository = new CliWorkerControlRepository();
    }

    public static function runtime(): MessageBusRuntime
    {
        if (!isset(self::$repository)) {
            self::reset();
        }

        return new MessageBusRuntime(
            new CliWorkerControlBus(),
            workerControlRuntime: self::controlRuntime(),
        );
    }

    public static function runtimeWithWorker(): MessageBusRuntime
    {
        if (!isset(self::$repository)) {
            self::reset();
        }

        $now = new DateTimeImmutable('2026-08-20T10:00:00+00:00');
        $identity = new WorkerIdentity(
            workerName: 'emails-worker',
            workerInstanceId: 'instance-1',
            workerGroup: 'emails',
            host: 'app-01',
            pid: 123,
            startedAt: $now,
            mode: WorkerMode::Auto,
            transport: 'postgres',
            queue: 'default',
            workerId: 'emails-worker',
        );
        self::$repository->registerWorker(new WorkerInstance(
            $identity,
            WorkerLifecycleState::Running,
            WorkerActivityState::Busy,
            $now,
            childrenCount: 1,
        ));
        self::$repository->registerChild(new WorkerChildInstance(
            'child-1',
            'instance-1',
            456,
            WorkerChildState::Running,
            $now,
            $now,
            'queue-1',
            'message-1',
            'correlation-1',
            'user.created.email',
        ));

        return self::runtime();
    }

    private static function controlRuntime(): WorkerControlRuntime
    {
        $service = new DefaultWorkerControlService(
            self::$repository,
            self::$repository,
            new CliWorkerControlIds(),
            new CliWorkerControlFrozenClock(),
        );

        return new WorkerControlRuntime(
            $service,
            self::$repository,
            self::$repository,
            self::$repository,
            self::$repository,
            self::$repository,
        );
    }
}

final class CliWorkerControlIds implements WorkerControlIdGeneratorInterface
{
    private int $index = 0;

    public function nextCommandId(WorkerControlCommandType $type): string
    {
        ++$this->index;

        return 'cli.' . $type->value . '.' . $this->index;
    }

    public function nextDesiredStateId(WorkerControlCommand $command): string
    {
        return 'desired.' . $command->commandId;
    }
}

final class CliWorkerControlFrozenClock implements ClockInterface
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-08-20T10:00:00+00:00');
    }
}

final class CliWorkerControlRepository implements
    WorkerControlCommandRepositoryInterface,
    WorkerDesiredStateRepositoryInterface,
    WorkerControlInboxInterface,
    WorkerRegistryInterface,
    WorkerStatusRepositoryInterface
{
    /** @var list<WorkerControlCommand> */
    public array $commands = [];

    /** @var list<WorkerDesiredState> */
    public array $desiredStates = [];

    /** @var array<string, WorkerInstance> */
    public array $workers = [];

    /** @var array<string, list<WorkerChildInstance>> */
    public array $children = [];

    public function append(WorkerControlCommand $command): WorkerControlCommandReceipt
    {
        $this->commands[] = $command;

        return new WorkerControlCommandReceipt($command->commandId, $command->type, $command->target, $command->createdAt);
    }

    public function findById(string $commandId): ?WorkerControlCommand
    {
        return null;
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?WorkerControlCommand
    {
        return null;
    }

    public function pendingFor(WorkerIdentity $identity, WorkerControlCursor $cursor): array
    {
        return [];
    }

    public function acknowledge(WorkerControlAcknowledgement $acknowledgement): void
    {
    }

    public function apply(WorkerDesiredState $state): void
    {
        $this->desiredStates[] = $state;
    }

    public function resolveFor(WorkerIdentity $identity): ?WorkerDesiredState
    {
        return null;
    }

    public function list(WorkerTarget $target): array
    {
        return $this->desiredStates;
    }

    public function receive(WorkerIdentity $identity, WorkerControlCursor $cursor): WorkerControlBatch
    {
        return new WorkerControlBatch([], $cursor);
    }

    public function registerWorker(WorkerInstance $worker): void
    {
        $this->workers[$worker->identity->workerInstanceId] = $worker;
    }

    public function heartbeatWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        WorkerActivityState $activity,
        DateTimeImmutable $heartbeatAt,
        int $childrenCount = 0,
        ?string $lastCommandId = null,
    ): void {
    }

    public function stopWorker(
        string $workerInstanceId,
        WorkerLifecycleState $state,
        DateTimeImmutable $stoppedAt,
        ?string $failureMessage = null,
    ): void {
    }

    public function registerChild(WorkerChildInstance $child): void
    {
        $this->children[$child->parentWorkerInstanceId][] = $child;
    }

    public function heartbeatChild(string $childInstanceId, WorkerChildState $state, DateTimeImmutable $heartbeatAt): void
    {
    }

    public function finishChild(
        string $childInstanceId,
        WorkerChildState $state,
        DateTimeImmutable $finishedAt,
        ?string $failureMessage = null,
    ): void {
    }

    public function getWorker(string $workerInstanceId): ?WorkerInstance
    {
        return $this->workers[$workerInstanceId] ?? null;
    }

    public function listWorkers(WorkerTarget $target): array
    {
        return \array_values(\array_filter(
            $this->workers,
            static fn (WorkerInstance $worker): bool => $target->matches($worker->identity),
        ));
    }

    public function listChildren(string $workerInstanceId): array
    {
        return $this->children[$workerInstanceId] ?? [];
    }

    public function acknowledgementsForCommand(string $commandId): array
    {
        return [];
    }
}

final class CliWorkerControlBus implements MessageBusInterface
{
    public function dispatch(object $message, PublishOptions $options = new PublishOptions(), ?Envelope $causation = null): mixed
    {
        throw new \LogicException('Not used in worker control CLI tests.');
    }

    public function dispatchAll(object $message, PublishOptions $options = new PublishOptions(), ?Envelope $causation = null): HandlerExecutionResultInterface
    {
        throw new \LogicException('Not used in worker control CLI tests.');
    }

    public function publish(object $message, PublishOptions $options = new PublishOptions(), ?Envelope $causation = null): PublishResult
    {
        throw new \LogicException('Not used in worker control CLI tests.');
    }

    public function publishMany(iterable $messages, PublishOptions $options = new PublishOptions(), ?Envelope $causation = null): PublishResult
    {
        throw new \LogicException('Not used in worker control CLI tests.');
    }

    public function dispatchPublishedSync(object $message, PublishOptions $options = new PublishOptions(), ?Envelope $causation = null): HandlerExecutionResultInterface
    {
        throw new \LogicException('Not used in worker control CLI tests.');
    }

    public function dispatchBindingSync(object $message, string|BackedEnum $bindingId, PublishOptions $options = new PublishOptions(), ?Envelope $causation = null): mixed
    {
        throw new \LogicException('Not used in worker control CLI tests.');
    }

    public function dispatchEnvelopeToBinding(Envelope $envelope): mixed
    {
        throw new \LogicException('Not used in worker control CLI tests.');
    }
}
