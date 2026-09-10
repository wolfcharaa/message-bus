<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use BackedEnum;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Wolfcharaa\MessageBus\Cli\Command\PostgresLibrarySchemaCommand;
use Wolfcharaa\MessageBus\Cli\Command\PostgresSchemaCommand;
use Wolfcharaa\MessageBus\Cli\Command\PostgresSchemaValidateCommand;
use Wolfcharaa\MessageBus\Cli\Command\RecoverStaleCommand;
use Wolfcharaa\MessageBus\Cli\Command\WorkerPostgresSchemaCommand;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\MessageBatchItem;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\Postgres\PostgresQueueStorageInterface;
use Wolfcharaa\MessageBus\Queue\QueueBatchEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueEnqueueResult;
use Wolfcharaa\MessageBus\Queue\QueueJobStatus;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\ReceivedQueueMessage;
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;

final class CliSchemaCommandTest extends TestCase
{
    public function testQueueSchemaCommandWritesStdoutAndFile(): void
    {
        $tester = new CommandTester(new PostgresSchemaCommand());
        $exitCode = $tester->execute([
            '--table' => 'custom_queue_jobs',
            '--schema-version-table' => 'custom_schema_versions',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS "custom_queue_jobs"', $tester->getDisplay());
        self::assertStringContainsString('custom_schema_versions', $tester->getDisplay());

        $target = $this->targetFile();
        try {
            $exitCode = (new CommandTester(new PostgresSchemaCommand()))->execute([
                '--output' => $target,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertFileExists($target);
            self::assertStringContainsString('message_bus__queue_jobs', (string) \file_get_contents($target));
        } finally {
            @\unlink($target);
        }
    }

    public function testLibrarySchemaCommandGeneratesSelectedComponentsAndRejectsUnknownComponent(): void
    {
        $tester = new CommandTester(new PostgresLibrarySchemaCommand());
        $exitCode = $tester->execute([
            '--with' => 'queue',
            '--queue-table' => 'library_queue_jobs',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('library_queue_jobs', $tester->getDisplay());
        self::assertStringNotContainsString('message_bus__worker_control_commands', $tester->getDisplay());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('schema:postgres --with supports queue, worker-control or all.');

        (new CommandTester(new PostgresLibrarySchemaCommand()))->execute(['--with' => 'unknown']);
    }

    public function testWorkerSchemaCommandWritesOutputFile(): void
    {
        $target = $this->targetFile();

        try {
            $tester = new CommandTester(new WorkerPostgresSchemaCommand());
            $exitCode = $tester->execute([
                '--schema-version-table' => 'custom_worker_schema_versions',
                '--output' => $target,
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('custom_worker_schema_versions', (string) \file_get_contents($target));
            self::assertStringContainsString('message_bus__worker_control_commands', (string) \file_get_contents($target));
        } finally {
            @\unlink($target);
        }
    }

    public function testRecoverStaleCommandUsesRuntimeQueueStorage(): void
    {
        CliSchemaRecoverStorage::$lastOptions = null;
        $bootstrap = $this->bootstrap('return \\' . CliSchemaCommandFactory::class . '::runtimeWithRecoverableQueue();');

        try {
            $tester = new CommandTester(new RecoverStaleCommand());
            $exitCode = $tester->execute([
                '--bootstrap' => $bootstrap,
                '--transport' => 'postgres',
                '--queue' => 'slow',
                '--lock-ttl' => '45',
            ]);

            self::assertSame(Command::SUCCESS, $exitCode);
            self::assertStringContainsString('recovered=3', $tester->getDisplay());
            self::assertNotNull(CliSchemaRecoverStorage::$lastOptions);
            self::assertSame('postgres', CliSchemaRecoverStorage::$lastOptions->transport);
            self::assertSame('slow', CliSchemaRecoverStorage::$lastOptions->queue);
            self::assertSame(45, CliSchemaRecoverStorage::$lastOptions->lockTtlSeconds);
        } finally {
            @\unlink($bootstrap);
        }
    }

    public function testRecoverStaleCommandRequiresPostgresQueueStorageRuntime(): void
    {
        $bootstrap = $this->bootstrap('return new \\' . MessageBusRuntime::class . '(new \\' . CliSchemaBus::class . '());');

        try {
            $tester = new CommandTester(new RecoverStaleCommand());

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('queue:recover-stale requires MessageBusRuntime with PostgresQueueStorageInterface.');

            $tester->execute(['--bootstrap' => $bootstrap]);
        } finally {
            @\unlink($bootstrap);
        }
    }

    public function testPostgresSchemaValidateCommandRequiresDsn(): void
    {
        $previous = \getenv('MESSAGE_BUS_POSTGRES_DSN');
        \putenv('MESSAGE_BUS_POSTGRES_DSN');

        try {
            $tester = new CommandTester(new PostgresSchemaValidateCommand());

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Pass --dsn or set MESSAGE_BUS_POSTGRES_DSN.');

            $tester->execute([]);
        } finally {
            if ($previous === false) {
                \putenv('MESSAGE_BUS_POSTGRES_DSN');
            } else {
                \putenv('MESSAGE_BUS_POSTGRES_DSN=' . $previous);
            }
        }
    }

    private function targetFile(): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-schema-');
        self::assertIsString($file);
        @\unlink($file);

        return $file . '.sql';
    }

    private function bootstrap(string $body): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-cli-schema-');
        self::assertIsString($file);
        \file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");

        return $file;
    }
}

final class CliSchemaCommandFactory
{
    public static function runtimeWithRecoverableQueue(): MessageBusRuntime
    {
        $storage = new CliSchemaRecoverStorage();

        return new MessageBusRuntime(
            new CliSchemaBus(),
            queueStatus: $storage,
            queueControl: $storage,
        );
    }
}

final class CliSchemaRecoverStorage implements PostgresQueueStorageInterface
{
    public static ?ConsumerOptions $lastOptions = null;

    public function enqueue(QueueMessage $message): QueueEnqueueResult
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function enqueueMany(iterable $messages): QueueBatchEnqueueResult
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function next(ConsumerOptions $options): ?ReceivedQueueMessage
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function ack(ReceivedQueueMessage $message): void
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function cancelReceived(ReceivedQueueMessage $message, \Throwable $reason): void
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function recoverStale(ConsumerOptions $options): int
    {
        self::$lastOptions = $options;

        return 3;
    }

    public function get(string $queueMessageId): ?QueueJobStatus
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function listByMessageId(string $messageId): array
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function listByCorrelationId(string $correlationId): array
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function cancel(string $queueMessageId): void
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function requestCancellation(string $queueMessageId): void
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function heartbeat(string $queueMessageId): void
    {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function isCancellationRequested(string $queueMessageId): bool
    {
        throw new LogicException('Not used by CLI schema tests.');
    }
}

final class CliSchemaBus implements MessageBusInterface
{
    public function dispatch(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function dispatchAll(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function publish(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        throw new LogicException('Not used by CLI schema tests.');
    }

    /**
     * @param iterable<object|MessageBatchItem> $messages
     */
    public function publishMany(
        iterable $messages,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function dispatchPublishedSync(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function dispatchBindingSync(
        object $message,
        string|BackedEnum $bindingId,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        throw new LogicException('Not used by CLI schema tests.');
    }

    public function dispatchEnvelopeToBinding(Envelope $envelope): mixed
    {
        throw new LogicException('Not used by CLI schema tests.');
    }
}
