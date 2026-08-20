<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Worker\Postgres\PostgresWorkerControlSchemaGenerator;
use Wolfcharaa\MessageBus\Worker\Postgres\PostgresWorkerControlStorage;
use Wolfcharaa\MessageBus\Worker\WorkerActivityState;
use Wolfcharaa\MessageBus\Worker\WorkerChildInstance;
use Wolfcharaa\MessageBus\Worker\WorkerChildState;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgement;
use Wolfcharaa\MessageBus\Worker\WorkerControlAcknowledgementState;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommand;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;
use Wolfcharaa\MessageBus\Worker\WorkerControlCursor;
use Wolfcharaa\MessageBus\Worker\WorkerControlTableDefinition;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredState;
use Wolfcharaa\MessageBus\Worker\WorkerDesiredStateType;
use Wolfcharaa\MessageBus\Worker\WorkerIdentity;
use Wolfcharaa\MessageBus\Worker\WorkerInstance;
use Wolfcharaa\MessageBus\Worker\WorkerLifecycleState;
use Wolfcharaa\MessageBus\Worker\WorkerMode;
use Wolfcharaa\MessageBus\Worker\WorkerTarget;

#[RequiresPhpExtension('pdo_pgsql')]
final class PostgresWorkerControlIntegrationTest extends TestCase
{
    private ?PDO $pdo = null;
    private ?WorkerControlTableDefinition $definition = null;

    protected function tearDown(): void
    {
        if ($this->pdo !== null && $this->definition !== null) {
            foreach ([
                $this->definition->acknowledgementsTable,
                $this->definition->childInstancesTable,
                $this->definition->workerInstancesTable,
                $this->definition->desiredStatesTable,
                $this->definition->commandsTable,
            ] as $table) {
                $this->pdo->exec('DROP TABLE IF EXISTS ' . $this->quoteIdentifier($table));
            }
        }
    }

    public function testPostgresWorkerControlLifecycleWithRealDatabase(): void
    {
        $storage = $this->storage();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $identity = $this->identity($now);

        $storage->registerWorker(new WorkerInstance(
            $identity,
            WorkerLifecycleState::Running,
            WorkerActivityState::Idle,
            $now,
        ));

        $storage->registerChild(new WorkerChildInstance(
            'child-1',
            $identity->workerInstanceId,
            456,
            WorkerChildState::Running,
            $now,
            $now,
            'queue-1',
            'message-1',
            'correlation-1',
            'user.created.send_welcome_email',
        ));

        $command = new WorkerControlCommand(
            'command-1',
            WorkerControlCommandType::Stop,
            new WorkerTarget(workerGroup: 'emails'),
            $now,
            createdBy: 'root',
            source: 'ui',
            reason: 'deploy',
            expiresAt: $now->modify('+5 minutes'),
            idempotencyKey: 'stop-emails-deploy',
        );

        $receipt = $storage->append($command);
        $duplicate = $storage->append($command);
        $batch = $storage->receive($identity, new WorkerControlCursor());

        self::assertFalse($receipt->duplicate);
        self::assertTrue($duplicate->duplicate);
        self::assertCount(1, $batch->commands);
        self::assertSame('command-1', $batch->commands[0]->commandId);
        self::assertSame('command-1', $batch->cursor->lastCommandId);

        $storage->acknowledge(new WorkerControlAcknowledgement(
            'command-1',
            $identity->workerInstanceId,
            WorkerControlAcknowledgementState::Applied,
            $now->modify('+1 second'),
            'stopping',
        ));

        $storage->heartbeatWorker(
            $identity->workerInstanceId,
            WorkerLifecycleState::Stopping,
            WorkerActivityState::Busy,
            $now->modify('+2 seconds'),
            childrenCount: 1,
            lastCommandId: 'command-1',
        );
        $storage->finishChild('child-1', WorkerChildState::Succeeded, $now->modify('+3 seconds'));
        $storage->stopWorker($identity->workerInstanceId, WorkerLifecycleState::Stopped, $now->modify('+4 seconds'));

        $worker = $storage->getWorker($identity->workerInstanceId);
        self::assertNotNull($worker);
        self::assertSame(WorkerLifecycleState::Stopped, $worker->state);
        self::assertSame('command-1', $worker->lastCommandId);
        self::assertCount(1, $storage->listChildren($identity->workerInstanceId));
        self::assertCount(1, $storage->acknowledgementsForCommand('command-1'));
    }

    public function testDesiredStateResolutionUsesMostSpecificTarget(): void
    {
        $storage = $this->storage();
        $now = new DateTimeImmutable('2026-08-20T10:00:00+00:00');
        $identity = $this->identity($now);

        $storage->apply(new WorkerDesiredState(
            'pause-emails',
            WorkerDesiredStateType::Paused,
            new WorkerTarget(workerGroup: 'emails'),
            $now,
        ));
        $storage->apply(new WorkerDesiredState(
            'resume-instance',
            WorkerDesiredStateType::Resumed,
            new WorkerTarget(workerInstanceId: 'instance-1'),
            $now->modify('+1 second'),
            override: true,
        ));

        $state = $storage->resolveFor($identity);

        self::assertNotNull($state);
        self::assertSame('resume-instance', $state->desiredStateId);
        self::assertSame(WorkerDesiredStateType::Resumed, $state->type);
    }

    private function storage(): PostgresWorkerControlStorage
    {
        if ($this->pdo === null) {
            $dsn = \getenv('MESSAGE_BUS_TEST_PGSQL_DSN');
            if ($dsn === false || $dsn === '') {
                self::markTestSkipped('Set MESSAGE_BUS_TEST_PGSQL_DSN to run PostgreSQL worker control integration tests.');
            }

            $this->pdo = new PDO(
                $dsn,
                \getenv('MESSAGE_BUS_TEST_PGSQL_USER') !== false ? (string) \getenv('MESSAGE_BUS_TEST_PGSQL_USER') : null,
                \getenv('MESSAGE_BUS_TEST_PGSQL_PASSWORD') !== false ? (string) \getenv('MESSAGE_BUS_TEST_PGSQL_PASSWORD') : null,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ],
            );
        }

        if ($this->definition === null) {
            $suffix = \bin2hex(\random_bytes(4));
            $this->definition = new WorkerControlTableDefinition(
                commandsTable: 'message_bus__worker_commands_test_' . $suffix,
                desiredStatesTable: 'message_bus__worker_desired_states_test_' . $suffix,
                workerInstancesTable: 'message_bus__worker_instances_test_' . $suffix,
                childInstancesTable: 'message_bus__worker_child_instances_test_' . $suffix,
                acknowledgementsTable: 'message_bus__worker_command_acknowledgements_test_' . $suffix,
            );
            $this->pdo->exec((new PostgresWorkerControlSchemaGenerator())->generate($this->definition));
        }

        return new PostgresWorkerControlStorage($this->pdo, $this->definition);
    }

    private function identity(DateTimeImmutable $startedAt): WorkerIdentity
    {
        return new WorkerIdentity(
            workerName: 'emails-worker',
            workerInstanceId: 'instance-1',
            workerGroup: 'emails',
            host: 'app-01',
            pid: 123,
            startedAt: $startedAt,
            mode: WorkerMode::Auto,
            transport: 'postgres',
            queue: 'default',
            bindingPatterns: ['user.created.*'],
            workerId: 'emails-worker',
        );
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . \str_replace('"', '""', $identifier) . '"';
    }
}
