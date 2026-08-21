<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use BackedEnum;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Wolfcharaa\MessageBus\Cli\ApplicationFactory;
use Wolfcharaa\MessageBus\Cli\ExitCode;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\MessageBatchItem;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaComponent;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaValidationIssue;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaValidationResult;
use Wolfcharaa\MessageBus\Postgres\PostgresSchemaValidatorInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;
use Wolfcharaa\MessageBus\Queue\ConsumerOptions;
use Wolfcharaa\MessageBus\Queue\MessageConsumerInterface;
use Wolfcharaa\MessageBus\Queue\QueueMessage;
use Wolfcharaa\MessageBus\Queue\QueueWorkerInterface;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunner;
use Wolfcharaa\MessageBus\Queue\ReceivedQueueMessage;
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;
use Wolfcharaa\MessageBus\Serialization\SerializedMessage;

final class CliRuntimeV5Test extends TestCase
{
    public function testWorkerRunCommandSingleModeUsesBootstrapRunner(): void
    {
        $bootstrap = $this->bootstrap('return \\' . CliRuntimeV5Factory::class . '::singleRunner();');

        try {
            $tester = new CommandTester(ApplicationFactory::create()->find('worker:run'));
            $exitCode = $tester->execute([
                '--bootstrap' => $bootstrap,
                '--transport' => 'postgres',
                '--queue' => 'default',
                '--max-messages' => '1',
                '--stop-when-empty' => true,
            ]);
        } finally {
            @\unlink($bootstrap);
        }

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('worker.started', $tester->getDisplay());
        self::assertStringContainsString('worker.stopped', $tester->getDisplay());
        self::assertStringContainsString('handled=1 succeeded=1 retried=0 rejected=0 cancelled=0', $tester->getDisplay());
    }

    public function testWorkerRunCommandRejectsAutoModeWithoutFullRuntime(): void
    {
        $bootstrap = $this->bootstrap('return \\' . CliRuntimeV5Factory::class . '::singleRunner();');

        try {
            $tester = new CommandTester(ApplicationFactory::create()->find('worker:run'));
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Auto worker mode requires MessageBusRuntime with consumer and worker.');
            $tester->execute([
                '--bootstrap' => $bootstrap,
                '--mode' => 'auto',
                '--stop-when-empty' => true,
            ]);
        } finally {
            @\unlink($bootstrap);
        }
    }

    public function testWorkerRunCommandDefinesAutoModeOptions(): void
    {
        $command = ApplicationFactory::create()->find('worker:run');
        $definition = $command->getDefinition();

        self::assertTrue($definition->hasOption('mode'));
        self::assertTrue($definition->hasOption('workers'));
        self::assertTrue($definition->hasOption('output-verbosity'));
        self::assertTrue($definition->hasOption('output-format'));
        self::assertTrue($definition->hasOption('storage-failure-backoff'));
        self::assertTrue($definition->hasOption('max-heartbeat-failures'));
        self::assertSame('single', $definition->getOption('mode')->getDefault());
        self::assertSame(2, $definition->getOption('workers')->getDefault());
        self::assertSame('normal', $definition->getOption('output-verbosity')->getDefault());
        self::assertSame('text', $definition->getOption('output-format')->getDefault());
        self::assertSame(1000, $definition->getOption('storage-failure-backoff')->getDefault());
        self::assertSame(3, $definition->getOption('max-heartbeat-failures')->getDefault());
    }

    public function testWorkerRunAutoModeFailsFastWhenPostgresSchemaIsInvalid(): void
    {
        $bootstrap = $this->bootstrap('return \\' . CliRuntimeV5Factory::class . '::runtimeWithInvalidPostgresSchema();');

        try {
            $tester = new CommandTester(ApplicationFactory::create()->find('worker:run'));
            $exitCode = $tester->execute([
                '--bootstrap' => $bootstrap,
                '--mode' => 'auto',
                '--transport' => 'postgres',
                '--stop-when-empty' => true,
            ]);
        } finally {
            @\unlink($bootstrap);
        }

        self::assertSame(ExitCode::SchemaMismatch->value, $exitCode);
        self::assertStringContainsString('MessageBus PostgreSQL schema is not compatible with worker auto mode.', $tester->getDisplay());
        self::assertStringContainsString('schema.version_missing', $tester->getDisplay());
    }

    private function bootstrap(string $body): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-cli-bootstrap-');
        self::assertIsString($file);
        \file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");

        return $file;
    }
}

final class CliRuntimeV5Factory
{
    public static function singleRunner(): QueueWorkerRunner
    {
        return new QueueWorkerRunner(
            new CliRuntimeV5Consumer([self::received()]),
            new CliRuntimeV5Worker(),
        );
    }

    public static function runtimeWithoutWorker(): MessageBusRuntime
    {
        throw new \LogicException('Reserved for future CLI runtime tests.');
    }

    public static function runtimeWithInvalidPostgresSchema(): MessageBusRuntime
    {
        return new MessageBusRuntime(
            new CliRuntimeV5Bus(),
            consumer: new CliRuntimeV5Consumer([]),
            worker: new CliRuntimeV5Worker(),
            postgresSchemaValidator: new CliRuntimeV5InvalidPostgresSchemaValidator(),
        );
    }

    private static function received(): ReceivedQueueMessage
    {
        $createdAt = new DateTimeImmutable('2026-08-20T10:00:00+00:00');
        $envelope = new SerializedEnvelope(
            new SerializedMessage('cli_runtime.message', 'application/json', '{"id":1}'),
            [],
            'message-1',
            null,
            'correlation-1',
            'async',
            'cli.runtime.handle',
            $createdAt,
        );

        return new ReceivedQueueMessage(
            'job-1',
            new QueueMessage(
                'postgres',
                'default',
                $envelope,
                'message-1',
                'correlation-1',
                'async',
                'cli.runtime.handle',
                $createdAt,
            ),
        );
    }
}

final class CliRuntimeV5Bus implements MessageBusInterface
{
    public function dispatch(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        throw new \LogicException('Not used by CLI runtime tests.');
    }

    public function dispatchAll(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        throw new \LogicException('Not used by CLI runtime tests.');
    }

    public function publish(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        throw new \LogicException('Not used by CLI runtime tests.');
    }

    /**
     * @param iterable<object|MessageBatchItem> $messages
     */
    public function publishMany(
        iterable $messages,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        throw new \LogicException('Not used by CLI runtime tests.');
    }

    public function dispatchPublishedSync(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        throw new \LogicException('Not used by CLI runtime tests.');
    }

    public function dispatchBindingSync(
        object $message,
        string|BackedEnum $bindingId,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        throw new \LogicException('Not used by CLI runtime tests.');
    }

    public function dispatchEnvelopeToBinding(Envelope $envelope): mixed
    {
        throw new \LogicException('Not used by CLI runtime tests.');
    }
}

final class CliRuntimeV5InvalidPostgresSchemaValidator implements PostgresSchemaValidatorInterface
{
    public function validate(?array $components = null): PostgresSchemaValidationResult
    {
        return new PostgresSchemaValidationResult(
            [PostgresSchemaComponent::Queue->value => '5.1', PostgresSchemaComponent::WorkerControl->value => '5.1'],
            [PostgresSchemaComponent::Queue->value => '5.1', PostgresSchemaComponent::WorkerControl->value => null],
            [
                new PostgresSchemaValidationIssue(
                    'schema.version_missing',
                    'Schema version for component `worker_control` is missing. Required version: 5.1.',
                    PostgresSchemaComponent::WorkerControl,
                ),
            ],
        );
    }
}

final class CliRuntimeV5Consumer implements MessageConsumerInterface
{
    /** @var list<ReceivedQueueMessage> */
    private array $messages;

    /** @param list<ReceivedQueueMessage> $messages */
    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function next(ConsumerOptions $options): ?ReceivedQueueMessage
    {
        return \array_shift($this->messages);
    }

    public function ack(ReceivedQueueMessage $message): void
    {
    }

    public function retry(ReceivedQueueMessage $message, \Throwable $reason): void
    {
    }

    public function reject(ReceivedQueueMessage $message, \Throwable $reason): void
    {
    }

    public function cancel(ReceivedQueueMessage $message, \Throwable $reason): void
    {
    }
}

final class CliRuntimeV5Worker implements QueueWorkerInterface
{
    public function handle(SerializedEnvelope $envelope): mixed
    {
        return 'ok';
    }
}
