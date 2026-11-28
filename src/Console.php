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
 * Optional Symfony Console composition; core does not require Symfony.
 *
 * `registry()`, `schema()`, `delivery()`, `idempotency()`, and `worker()`
 * create focused standalone applications. Their matching `register*()` methods
 * add the same commands to an existing host Application without replacing its
 * name, version, helpers, or unrelated commands.
 *
 * @api adapter
 */
final readonly class Console
{
    private const string VERSION = '7.0.0-beta.2';

    public static function registry(
        RegistryCompiler $compiler,
        RegistryClassSource $source,
        string $artifactPath,
        ?string $applicationBuildId = null,
    ): Application {
        $application = self::application();
        self::registerRegistry($application, $compiler, $source, $artifactPath, $applicationBuildId);

        return $application;
    }

    /** Registers registry commands in an existing host Symfony application. */
    public static function registerRegistry(
        Application $application,
        RegistryCompiler $compiler,
        RegistryClassSource $source,
        string $artifactPath,
        ?string $applicationBuildId = null,
    ): void {
        self::requireSymfony();
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
    }

    public static function schema(PostgresSchemaManager $schema): Application
    {
        $application = self::application();
        self::registerSchema($application, $schema);

        return $application;
    }

    /** Registers schema commands in an existing host Symfony application. */
    public static function registerSchema(Application $application, PostgresSchemaManager $schema): void
    {
        self::requireSymfony();
        $application->addCommands([new SchemaValidateCommand($schema)]);
    }

    public static function delivery(
        DeliveryOperations $operations,
        DeliveryMaintenance $maintenance,
        DeliveryPolicies $policies,
    ): Application {
        $application = self::application();
        self::registerDelivery($application, $operations, $maintenance, $policies);

        return $application;
    }

    /** Registers delivery control commands in an existing host Symfony application. */
    public static function registerDelivery(
        Application $application,
        DeliveryOperations $operations,
        DeliveryMaintenance $maintenance,
        DeliveryPolicies $policies,
    ): void {
        self::requireSymfony();
        $application->addCommands([
            new DeliveryStatusCommand($operations),
            new DeliveryCancelCommand($operations),
            new DeliveryReplayCommand($operations, $policies),
            new DeliveryRecoverCommand($maintenance),
            new DeliveryPruneCommand($maintenance),
        ]);
    }

    public static function idempotency(IdempotencyMaintenance $maintenance): Application
    {
        $application = self::application();
        self::registerIdempotency($application, $maintenance);

        return $application;
    }

    /** Registers idempotency maintenance commands in an existing host Symfony application. */
    public static function registerIdempotency(
        Application $application,
        IdempotencyMaintenance $maintenance,
    ): void {
        self::requireSymfony();
        $application->addCommands([new IdempotencyPruneCommand($maintenance)]);
    }

    public static function worker(
        PcntlWorkerSupervisor $supervisor,
        WorkerProfile $profile,
        WorkerControlOperations $control,
    ): Application {
        $application = self::application();
        self::registerWorker($application, $supervisor, $profile, $control);

        return $application;
    }

    /** Registers worker lifecycle commands in an existing host Symfony application. */
    public static function registerWorker(
        Application $application,
        PcntlWorkerSupervisor $supervisor,
        WorkerProfile $profile,
        WorkerControlOperations $control,
    ): void {
        self::requireSymfony();
        $application->addCommands([
            new WorkerRunCommand($supervisor, $profile),
            new WorkerStatusCommand($control),
            new WorkerPauseCommand($control),
            new WorkerResumeCommand($control),
            new WorkerPruneCommand($control),
        ]);
    }

    private static function application(): Application
    {
        self::requireSymfony();

        return new Application('message-bus', self::VERSION);
    }

    private static function requireSymfony(): void
    {
        if (!class_exists(Application::class)) {
            throw new \RuntimeException('Symfony Console integration requires symfony/console.');
        }
    }
}
