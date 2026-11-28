<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Application;
use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresDeliveryBackend;
use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresRuntime;
use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresSchemaManager;
use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresWorkerControl;
use Wolfcharaa\MessageBus\Adapter\Psr\Container\PsrContainerHandlerServiceResolver;
use Wolfcharaa\MessageBus\Adapter\Symfony\Console;
use Wolfcharaa\MessageBus\Attribute\Handler;
use Wolfcharaa\MessageBus\Attribute\MessageName;
use Wolfcharaa\MessageBus\Attribute\Payload;
use Wolfcharaa\MessageBus\Codec\MessageCodecs;
use Wolfcharaa\MessageBus\Contract\Command;
use Wolfcharaa\MessageBus\Delivery\DeliveryMaintenance;
use Wolfcharaa\MessageBus\Delivery\DeliveryOperations;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicy;
use Wolfcharaa\MessageBus\HandlerServices;
use Wolfcharaa\MessageBus\Registry\CompiledRegistry;
use Wolfcharaa\MessageBus\Registry\ExplicitClassSource;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;
use Wolfcharaa\MessageBus\Routing\DeliveryMode;
use Wolfcharaa\MessageBus\Routing\DeliveryRoute;
use Wolfcharaa\MessageBus\Routing\DeliveryRoutes;
use Wolfcharaa\MessageBus\Worker\DeliveryWorkerRuntime;

final class IntegrationCompositionTest extends TestCase
{
    public function testPsrContainerResolverPreservesTheExactContainerFailure(): void
    {
        $containerFailure = new CompositionContainerFailure('autowiring detail');
        $container = new readonly class ($containerFailure) implements ContainerInterface {
            public function __construct(private \Throwable $failure) {}

            public function get(string $id): mixed
            {
                throw $this->failure;
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
        $resolver = new PsrContainerHandlerServiceResolver($container);

        try {
            $resolver->get(CompositionJsonHandler::class);
            self::fail('PSR-11 container failure was not rethrown.');
        } catch (CompositionContainerFailure $failure) {
            self::assertSame($containerFailure, $failure);
        }
    }

    public function testPsrContainerResolverPreservesTheExactHasFailure(): void
    {
        $containerFailure = new CompositionContainerFailure('definition lookup detail');
        $container = new readonly class ($containerFailure) implements ContainerInterface {
            public function __construct(private \Throwable $failure) {}

            public function get(string $id): mixed
            {
                return new CompositionJsonHandler();
            }

            public function has(string $id): bool
            {
                throw $this->failure;
            }
        };
        $resolver = new PsrContainerHandlerServiceResolver($container);

        try {
            $resolver->has(CompositionJsonHandler::class);
            self::fail('PSR-11 container has() failure was not rethrown.');
        } catch (CompositionContainerFailure $failure) {
            self::assertSame($containerFailure, $failure);
        }
    }

    public function testPsrContainerResolverIdentifiesAnIncompatibleReturnedValue(): void
    {
        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                return new \stdClass();
            }

            public function has(string $id): bool
            {
                return true;
            }
        };
        $resolver = new PsrContainerHandlerServiceResolver($container);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'PSR-11 container returned stdClass for handler ' . CompositionJsonHandler::class
            . '; expected an instance of ' . CompositionJsonHandler::class . '.',
        );

        $resolver->get(CompositionJsonHandler::class);
    }

    public function testCommandBindingIdBecomesTheStableCompiledTarget(): void
    {
        $registry = $this->registry();

        self::assertSame(
            'command.composition-json',
            $registry->handlersFor(new CompositionJsonCommand('value'))[0]->id,
        );
    }

    public function testStandardCodecsAreBuiltFromTheCompiledRegistry(): void
    {
        $codecs = MessageCodecs::fromRegistry($this->registry());

        $json = new CompositionJsonCommand('json');
        $encodedJson = $codecs->encode($json);
        self::assertSame('composition.json', $encodedJson->messageName);
        self::assertSame(2, $encodedJson->version);
        self::assertEquals($json, $codecs->decode($encodedJson));

        $native = new CompositionNativeCommand(new CompositionNativeValue('native'));
        $encodedNative = $codecs->encode($native);
        self::assertSame('php-native', $encodedNative->codec);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', (string) $encodedNative->objectGraphFingerprint);
        self::assertEquals($native, $codecs->decode($encodedNative));
    }

    public function testAutomaticCodecFactoryNamesUnsupportedCustomCodecAndMessage(): void
    {
        $registry = (new RegistryCompiler())->compile(new ExplicitClassSource([
            CompositionCustomCommand::class,
            CompositionCustomHandler::class,
        ]))->requireRegistry();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'Cannot automatically build codec "custom" for durable message '
            . CompositionCustomCommand::class . ' (composition.custom v1); '
            . 'construct MessageCodecs explicitly for custom codecs.',
        );

        MessageCodecs::fromRegistry($registry);
    }

    public function testPostgresRuntimeBuildsOneConsistentProcessLocalComposition(): void
    {
        $handler = new CompositionJsonHandler();
        $pdo = self::createStub(\PDO::class);
        $pdo->method('setAttribute')->willReturn(true);
        $runtime = PostgresRuntime::create(
            pdo: $pdo,
            registry: $this->registry(),
            handlers: new HandlerServices([
                CompositionJsonHandler::class => $handler,
                CompositionNativeHandler::class => new CompositionNativeHandler(),
            ]),
            routes: new DeliveryRoutes([
                'command.composition-json' => new DeliveryRoute(DeliveryMode::Durable),
                'command.composition-native' => new DeliveryRoute(DeliveryMode::Durable),
            ]),
            policies: new DeliveryPolicies(['default' => new DeliveryPolicy('default')]),
            schema: 'message_bus_test',
        );

        self::assertInstanceOf(PostgresDeliveryBackend::class, $runtime->backend);
        self::assertInstanceOf(DeliveryWorkerRuntime::class, $runtime->worker);
        self::assertInstanceOf(PostgresSchemaManager::class, $runtime->schemaManager);
        self::assertInstanceOf(DeliveryOperations::class, $runtime->deliveries);
        self::assertInstanceOf(DeliveryMaintenance::class, $runtime->deliveryMaintenance);
        self::assertInstanceOf(PostgresWorkerControl::class, $runtime->workerControl);
        self::assertTrue($runtime->codecs->canEncode(CompositionJsonCommand::class));
    }

    public function testConsoleCommandsCanBeRegisteredInAnExistingHostApplication(): void
    {
        $application = new Application('host-console', '1.0.0');

        Console::registerRegistry(
            $application,
            new RegistryCompiler(),
            new ExplicitClassSource([]),
            sys_get_temp_dir() . '/message-bus-host-registry.json',
        );

        self::assertSame('host-console', $application->getName());
        self::assertSame('1.0.0', $application->getVersion());
        self::assertTrue($application->has('registry:check'));
        self::assertTrue($application->has('registry:compile'));
    }

    private function registry(): CompiledRegistry
    {
        return (new RegistryCompiler())->compile(new ExplicitClassSource([
            CompositionJsonCommand::class,
            CompositionJsonHandler::class,
            CompositionNativeCommand::class,
            CompositionNativeHandler::class,
        ]))->requireRegistry();
    }
}

final class CompositionContainerFailure extends \RuntimeException {}

#[MessageName('composition.json')]
#[Payload(version: 2)]
final readonly class CompositionJsonCommand implements Command
{
    public function __construct(public string $value) {}
}

final class CompositionJsonHandler
{
    #[Handler(bindingId: 'command.composition-json')]
    public function __invoke(CompositionJsonCommand $command): void {}
}

final readonly class CompositionNativeValue
{
    public function __construct(public string $value) {}
}

#[MessageName('composition.native')]
#[Payload(codec: 'php-native')]
final readonly class CompositionNativeCommand implements Command
{
    public function __construct(public CompositionNativeValue $value) {}
}

final class CompositionNativeHandler
{
    #[Handler(bindingId: 'command.composition-native')]
    public function __invoke(CompositionNativeCommand $command): void {}
}

#[MessageName('composition.custom')]
#[Payload(codec: 'custom')]
final readonly class CompositionCustomCommand implements Command {}

final class CompositionCustomHandler
{
    #[Handler(bindingId: 'command.composition-custom')]
    public function __invoke(CompositionCustomCommand $command): void {}
}
