<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Wolfcharaa\MessageBus\Adapter\InMemoryDeliveryBackend;
use Wolfcharaa\MessageBus\Attribute\Handler;
use Wolfcharaa\MessageBus\Attribute\MessageName;
use Wolfcharaa\MessageBus\Attribute\Payload;
use Wolfcharaa\MessageBus\Codec\JsonMessageCodec;
use Wolfcharaa\MessageBus\Codec\MessageCodecs;
use Wolfcharaa\MessageBus\Contract\Command;
use Wolfcharaa\MessageBus\DefaultMessageBus;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\Delivery\DeliveryState;
use Wolfcharaa\MessageBus\DurableRuntime;
use Wolfcharaa\MessageBus\HandlerServices;
use Wolfcharaa\MessageBus\Receipt\CommandReceipt;
use Wolfcharaa\MessageBus\Registry\CompiledRegistry;
use Wolfcharaa\MessageBus\Registry\ExplicitClassSource;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;
use Wolfcharaa\MessageBus\Routing\DeliveryMode;
use Wolfcharaa\MessageBus\Routing\DeliveryRoute;
use Wolfcharaa\MessageBus\Routing\DeliveryRoutes;
use Wolfcharaa\MessageBus\Worker\ChildRuntimeBuilder;
use Wolfcharaa\MessageBus\Worker\DeliveryWorkerRuntime;
use Wolfcharaa\MessageBus\Worker\ForkResourceReset;
use Wolfcharaa\MessageBus\Worker\PcntlWorkerSupervisor;
use Wolfcharaa\MessageBus\Worker\ResettingChildExecutionFactory;
use Wolfcharaa\MessageBus\Worker\WorkerProcessSystem;
use Wolfcharaa\MessageBus\Worker\WorkerProfile;

#[Group('process')]
final class PcntlWorkerSupervisorTest extends TestCase
{
    public function testSupervisorForksOneFreshChildRuntimeAndDrainsIt(): void
    {
        if (!\extension_loaded('pcntl') || !\extension_loaded('posix')) {
            self::markTestSkipped('ext-pcntl and ext-posix are required.');
        }

        $marker = tempnam(sys_get_temp_dir(), 'message-bus-process-');
        self::assertIsString($marker);
        unlink($marker);
        $resetMarker = $marker . '.reset';

        try {
            $handler = new ProcessCommandHandler($marker);
            $registry = (new RegistryCompiler())->compile(new ExplicitClassSource([
                ProcessCommand::class,
                ProcessCommandHandler::class,
            ]))->requireRegistry();
            $services = new HandlerServices([ProcessCommandHandler::class => $handler]);
            $backend = new InMemoryDeliveryBackend();
            $codecs = new MessageCodecs(
                [ProcessCommand::class => new JsonMessageCodec(ProcessCommand::class)],
                ['process.command' => [1 => new JsonMessageCodec(ProcessCommand::class)]],
            );
            $bus = new DefaultMessageBus(
                $registry,
                $services,
                new DeliveryRoutes([
                    ProcessCommand::class => new DeliveryRoute(DeliveryMode::Durable),
                ]),
                durable: new DurableRuntime(
                    $backend,
                    $codecs,
                    new DeliveryPolicies(['default' => new DeliveryPolicy('default')]),
                ),
            );
            self::assertInstanceOf(CommandReceipt::class, $bus->dispatch(new ProcessCommand('one')));

            $parentRuntime = new DeliveryWorkerRuntime($backend, $codecs, $registry, $services, $bus);
            $supervisor = new PcntlWorkerSupervisor(
                $parentRuntime,
                new ResettingChildExecutionFactory(
                    new ProcessChildRuntimeBuilder($backend, $codecs, $registry, $services, $bus),
                    [new ProcessForkResourceReset($resetMarker)],
                ),
            );
            $deadline = microtime(true) + 5.0;
            $supervisor->run(
                new WorkerProfile('process', 'default', pollMicroseconds: 10_000),
                static fn(): bool => is_file($marker) || microtime(true) >= $deadline,
            );

            self::assertFileExists($marker);
            self::assertSame('handled:one', file_get_contents($marker));
            self::assertSame('reset', file_get_contents($resetMarker));
        } finally {
            if (is_file($marker)) {
                unlink($marker);
            }
            if (is_file($resetMarker)) {
                unlink($resetMarker);
            }
        }
    }

    public function testForkFailureIsSettledBeforeHandlerAndStopsWhenLaneIsEmpty(): void
    {
        $marker = sys_get_temp_dir() . '/message-bus-unused-' . bin2hex(random_bytes(4));
        $handler = new ProcessCommandHandler($marker);
        $registry = (new RegistryCompiler())->compile(new ExplicitClassSource([
            ProcessCommand::class,
            ProcessCommandHandler::class,
        ]))->requireRegistry();
        $services = new HandlerServices([ProcessCommandHandler::class => $handler]);
        $backend = new InMemoryDeliveryBackend();
        $codecs = new MessageCodecs(
            [ProcessCommand::class => new JsonMessageCodec(ProcessCommand::class)],
            ['process.command' => [1 => new JsonMessageCodec(ProcessCommand::class)]],
        );
        $bus = new DefaultMessageBus(
            $registry,
            $services,
            new DeliveryRoutes([
                ProcessCommand::class => new DeliveryRoute(DeliveryMode::Durable),
            ]),
            durable: new DurableRuntime(
                $backend,
                $codecs,
                new DeliveryPolicies(['default' => new DeliveryPolicy('default')]),
            ),
        );
        $receipt = $bus->dispatch(new ProcessCommand('fork-failure'));
        self::assertInstanceOf(CommandReceipt::class, $receipt);
        $deliveryId = $receipt->entry->deliveryId;
        self::assertNotNull($deliveryId);

        $parent = new DeliveryWorkerRuntime($backend, $codecs, $registry, $services, $bus);
        $supervisor = new PcntlWorkerSupervisor(
            $parent,
            new ResettingChildExecutionFactory(
                new ProcessChildRuntimeBuilder($backend, $codecs, $registry, $services, $bus),
                [],
            ),
            process: new ForkFailureProcessSystem(),
        );
        $supervisor->run(new WorkerProfile('fork-failure', 'default', stopWhenEmpty: true));

        self::assertSame(DeliveryState::Failed, $backend->status($deliveryId)?->state);
        self::assertFileDoesNotExist($marker);
    }
}

final readonly class ProcessChildRuntimeBuilder implements ChildRuntimeBuilder
{
    public function __construct(
        private InMemoryDeliveryBackend $backend,
        private MessageCodecs $codecs,
        private CompiledRegistry $registry,
        private HandlerServices $services,
        private DefaultMessageBus $bus,
    ) {}

    public function create(): DeliveryWorkerRuntime
    {
        return new DeliveryWorkerRuntime(
            $this->backend,
            $this->codecs,
            $this->registry,
            $this->services,
            $this->bus,
        );
    }
}

final readonly class ProcessForkResourceReset implements ForkResourceReset
{
    public function __construct(private string $marker) {}

    public function resetAfterFork(): void
    {
        file_put_contents($this->marker, 'reset');
    }
}

final class ForkFailureProcessSystem implements WorkerProcessSystem
{
    private float $time = 1.0;

    public function available(): bool
    {
        return true;
    }

    public function installStopSignals(callable $stop): void {}

    public function fork(): int
    {
        return -1;
    }

    public function currentPid(): int
    {
        return 1_234;
    }

    public function waitNoHang(int $pid): bool
    {
        return true;
    }

    public function signal(int $pid, int $signal): void {}

    public function sleep(int $microseconds): void
    {
        $this->time += $microseconds / 1_000_000;
    }

    public function monotonicTime(): float
    {
        return $this->time;
    }

    public function host(): string
    {
        return 'test-host';
    }
}

#[MessageName('process.command')]
#[Payload]
final readonly class ProcessCommand implements Command
{
    public function __construct(public string $value) {}
}

final readonly class ProcessCommandHandler
{
    public function __construct(private string $marker) {}

    #[Handler]
    public function __invoke(ProcessCommand $command): void
    {
        file_put_contents($this->marker, 'handled:' . $command->value);
    }
}
