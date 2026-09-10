<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use BackedEnum;
use DateTimeImmutable;
use LogicException;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Cli\BootstrapResolver;
use Wolfcharaa\MessageBus\Envelope\Envelope;
use Wolfcharaa\MessageBus\Exception\ContainerServiceInvalid;
use Wolfcharaa\MessageBus\Exception\ContainerServiceNotFound;
use Wolfcharaa\MessageBus\Execution\HandlerExecutionResultInterface;
use Wolfcharaa\MessageBus\MessageBatchItem;
use Wolfcharaa\MessageBus\MessageBusInterface;
use Wolfcharaa\MessageBus\PublishOptions;
use Wolfcharaa\MessageBus\PublishResult;
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;
use Wolfcharaa\MessageBus\Tests\Support\TestContainer;
use Wolfcharaa\MessageBus\Tests\Support\WorkerControlMemoryRuntime;
use Wolfcharaa\MessageBus\Worker\WorkerIdentity;
use Wolfcharaa\MessageBus\Worker\WorkerMode;

final class RuntimeContractsTest extends TestCase
{
    public function testMessageBusRuntimeFromContainerUsesAliasFallbackAndGetters(): void
    {
        $bus = new RuntimeContractsBus();
        $workerControl = (new WorkerControlMemoryRuntime())->runtime();
        $container = new TestContainer([
            'message_bus.bus' => $bus,
            'message_bus.worker_control_runtime' => $workerControl,
        ], autowireClasses: false);

        $runtime = MessageBusRuntime::fromContainer($container);

        self::assertSame($bus, $runtime->bus());
        self::assertNull($runtime->runner());
        self::assertNull($runtime->provider());
        self::assertNull($runtime->consumer());
        self::assertNull($runtime->worker());
        self::assertNull($runtime->queueStatus());
        self::assertNull($runtime->queueControl());
        self::assertSame($workerControl, $runtime->workerControlRuntime());
        self::assertNull($runtime->postgresSchemaValidator());
        self::assertSame($workerControl->controlService(), $workerControl->controlService());
        self::assertSame($workerControl->commandRepository(), $workerControl->commandRepository());
        self::assertSame($workerControl->desiredStateRepository(), $workerControl->desiredStateRepository());
        self::assertSame($workerControl->workerRegistry(), $workerControl->workerRegistry());
        self::assertSame($workerControl->statusRepository(), $workerControl->statusRepository());
        self::assertSame($workerControl->statusService(), $workerControl->statusService());
        self::assertSame($workerControl->inbox(), $workerControl->inbox());
        self::assertNull($workerControl->notifier());
    }

    public function testMessageBusRuntimeFromContainerRequiresBus(): void
    {
        $this->expectException(ContainerServiceNotFound::class);
        $this->expectExceptionMessage('message bus');

        MessageBusRuntime::fromContainer(new TestContainer([], autowireClasses: false));
    }

    public function testMessageBusRuntimeFromContainerRejectsInvalidOptionalService(): void
    {
        $this->expectException(ContainerServiceInvalid::class);
        $this->expectExceptionMessage('queue provider');

        MessageBusRuntime::fromContainer(new TestContainer([
            MessageBusInterface::class => new RuntimeContractsBus(),
            'message_bus.queue_provider' => new \stdClass(),
        ], autowireClasses: false));
    }

    public function testBootstrapResolverReturnsRuntime(): void
    {
        $bootstrap = $this->bootstrap('return \\' . RuntimeContractsFactory::class . '::runtime();');

        try {
            $runtime = (new BootstrapResolver())->resolve($bootstrap);
        } finally {
            @\unlink($bootstrap);
        }

        self::assertInstanceOf(MessageBusRuntime::class, $runtime);
        self::assertInstanceOf(RuntimeContractsBus::class, $runtime->bus());
    }

    public function testBootstrapResolverBuildsRuntimeFromContainer(): void
    {
        $bootstrap = $this->bootstrap('return \\' . RuntimeContractsFactory::class . '::container();');

        try {
            $runtime = (new BootstrapResolver())->resolve($bootstrap);
        } finally {
            @\unlink($bootstrap);
        }

        self::assertInstanceOf(MessageBusRuntime::class, $runtime);
        self::assertInstanceOf(RuntimeContractsBus::class, $runtime->bus());
    }

    public function testBootstrapResolverRejectsMissingBootstrap(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('MessageBus bootstrap file was not found.');

        (new BootstrapResolver())->resolve('/tmp/message-bus-missing-bootstrap.php');
    }

    public function testBootstrapResolverRejectsUnsupportedReturnValue(): void
    {
        $bootstrap = $this->bootstrap('return new \stdClass();');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('MessageBus bootstrap must return MessageBusRuntime, QueueWorkerRunner or PSR-11 container.');
            (new BootstrapResolver())->resolve($bootstrap);
        } finally {
            @\unlink($bootstrap);
        }
    }

    public function testWorkerIdentityRoundTripPreservesPublicContract(): void
    {
        $identity = new WorkerIdentity(
            workerName: 'emails-worker',
            workerInstanceId: 'instance-1',
            workerGroup: 'emails',
            host: 'app-01',
            pid: 123,
            startedAt: new DateTimeImmutable('2026-09-10T10:00:00+00:00'),
            mode: WorkerMode::Auto,
            transport: 'postgres',
            queue: 'default',
            flows: ['async'],
            bindingIds: ['emails.send'],
            bindingPatterns: ['emails.*'],
            workerId: 'emails-worker',
        );

        $data = $identity->toArray();
        self::assertSame('2026-09-10T10:00:00+00:00', $data['startedAt']);
        self::assertSame('auto', $data['mode']);

        $restored = WorkerIdentity::fromArray($data);
        self::assertEquals($identity, $restored);
        self::assertSame($data, $restored->toArray());
    }

    private function bootstrap(string $body): string
    {
        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-runtime-bootstrap-');
        self::assertIsString($file);
        \file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\n" . $body . "\n");

        return $file;
    }
}

final class RuntimeContractsFactory
{
    public static function runtime(): MessageBusRuntime
    {
        return new MessageBusRuntime(new RuntimeContractsBus());
    }

    public static function container(): TestContainer
    {
        return new TestContainer([
            MessageBusInterface::class => new RuntimeContractsBus(),
        ], autowireClasses: false);
    }
}

final class RuntimeContractsBus implements MessageBusInterface
{
    public function dispatch(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        throw new LogicException('Not used by runtime contract tests.');
    }

    public function dispatchAll(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        throw new LogicException('Not used by runtime contract tests.');
    }

    public function publish(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        throw new LogicException('Not used by runtime contract tests.');
    }

    /**
     * @param iterable<object|MessageBatchItem> $messages
     */
    public function publishMany(
        iterable $messages,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): PublishResult {
        throw new LogicException('Not used by runtime contract tests.');
    }

    public function dispatchPublishedSync(
        object $message,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): HandlerExecutionResultInterface {
        throw new LogicException('Not used by runtime contract tests.');
    }

    public function dispatchBindingSync(
        object $message,
        string|BackedEnum $bindingId,
        PublishOptions $options = new PublishOptions(),
        ?Envelope $causation = null,
    ): mixed {
        throw new LogicException('Not used by runtime contract tests.');
    }

    public function dispatchEnvelopeToBinding(Envelope $envelope): mixed
    {
        throw new LogicException('Not used by runtime contract tests.');
    }
}
