<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Wolfcharaa\MessageBus\Cli\ApplicationFactory;
use Wolfcharaa\MessageBus\Envelope\SerializedEnvelope;
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
        self::assertSame('single', $definition->getOption('mode')->getDefault());
        self::assertSame(2, $definition->getOption('workers')->getDefault());
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
