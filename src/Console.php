<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Adapter\Symfony;

use Symfony\Component\Console\Application;
use Wolfcharaa\MessageBus\Adapter\Postgres\PostgresSchemaManager;
use Wolfcharaa\MessageBus\Delivery\DeliveryMaintenance;
use Wolfcharaa\MessageBus\Delivery\DeliveryOperations;
use Wolfcharaa\MessageBus\Delivery\DeliveryPolicies;
use Wolfcharaa\MessageBus\Idempotency\IdempotencyMaintenance;
use Wolfcharaa\MessageBus\Internal\Console\DeliveryCancelCommand;
use Wolfcharaa\MessageBus\Internal\Console\DeliveryPruneCommand;
use Wolfcharaa\MessageBus\Internal\Console\DeliveryRecoverCommand;
use Wolfcharaa\MessageBus\Internal\Console\DeliveryReplayCommand;
use Wolfcharaa\MessageBus\Internal\Console\DeliveryStatusCommand;
use Wolfcharaa\MessageBus\Internal\Console\IdempotencyPruneCommand;
use Wolfcharaa\MessageBus\Internal\Console\RegistryCheckCommand;
use Wolfcharaa\MessageBus\Internal\Console\RegistryCompileCommand;
use Wolfcharaa\MessageBus\Internal\Console\SchemaValidateCommand;
use Wolfcharaa\MessageBus\Internal\Console\WorkerPauseCommand;
use Wolfcharaa\MessageBus\Internal\Console\WorkerPruneCommand;
use Wolfcharaa\MessageBus\Internal\Console\WorkerResumeCommand;
use Wolfcharaa\MessageBus\Internal\Console\WorkerRunCommand;
use Wolfcharaa\MessageBus\Internal\Console\WorkerStatusCommand;
use Wolfcharaa\MessageBus\Registry\RegistryArtifactStore;
use Wolfcharaa\MessageBus\Registry\RegistryClassSource;
use Wolfcharaa\MessageBus\Registry\RegistryCompiler;
use Wolfcharaa\MessageBus\Worker\PcntlWorkerSupervisor;
use Wolfcharaa\MessageBus\Worker\WorkerControlOperations;
use Wolfcharaa\MessageBus\Worker\WorkerProfile;

/**
 *  Optional Symfony Console ready composition; core does not require Symfony.
 *
 * @api adapter
 */
final readonly class Console
{
    public static function registry(
        RegistryCompiler $compiler,
        RegistryClassSource $source,
        string $artifactPath,
        ?string $applicationBuildId = null,
    ): Application {
        self::requireSymfony();
        $application = new Application('message-bus', '7.0.0-alpha.2');
        $application->addCommands([
            new RegistryCheckCommand($compiler, $source),
            new RegistryCompileCommand(
                $compiler,
                $source,
                new RegistryArtifactStore(),
                $artifactPath,
                $applicationBuildId,
            ),
        ]);

        return $application;
    }

    public static function schema(PostgresSchemaManager $schema): Application
    {
        self::requireSymfony();
        $application = new Application('message-bus', '7.0.0-alpha.2');
        $application->addCommands([new SchemaValidateCommand($schema)]);

        return $application;
    }

    public static function delivery(
        DeliveryOperations $operations,
        DeliveryMaintenance $maintenance,
        DeliveryPolicies $policies,
    ): Application {
        self::requireSymfony();
        $application = new Application('message-bus', '7.0.0-alpha.2');
        $application->addCommands([
            new DeliveryStatusCommand($operations),
            new DeliveryCancelCommand($operations),
            new DeliveryReplayCommand($operations, $policies),
            new DeliveryRecoverCommand($maintenance),
            new DeliveryPruneCommand($maintenance),
        ]);

        return $application;
    }

    public static function idempotency(IdempotencyMaintenance $maintenance): Application
    {
        self::requireSymfony();
        $application = new Application('message-bus', '7.0.0-alpha.2');
        $application->addCommands([new IdempotencyPruneCommand($maintenance)]);

        return $application;
    }

    public static function worker(
        PcntlWorkerSupervisor $supervisor,
        WorkerProfile $profile,
        WorkerControlOperations $control,
    ): Application {
        self::requireSymfony();
        $application = new Application('message-bus', '7.0.0-alpha.2');
        $application->addCommands([
            new WorkerRunCommand($supervisor, $profile),
            new WorkerStatusCommand($control),
            new WorkerPauseCommand($control),
            new WorkerResumeCommand($control),
            new WorkerPruneCommand($control),
        ]);

        return $application;
    }

    private static function requireSymfony(): void
    {
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Symfony Console integration requires symfony/console.');
        }
    }
}
