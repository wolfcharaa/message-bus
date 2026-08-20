<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli;

use Symfony\Component\Console\Application;
use Wolfcharaa\MessageBus\Cli\Command\PostgresSchemaCommand;
use Wolfcharaa\MessageBus\Cli\Command\PostgresLibrarySchemaCommand;
use Wolfcharaa\MessageBus\Cli\Command\RecoverStaleCommand;
use Wolfcharaa\MessageBus\Cli\Command\RegistryCompileCommand;
use Wolfcharaa\MessageBus\Cli\Command\WorkerControlActionCommand;
use Wolfcharaa\MessageBus\Cli\Command\WorkerControlCliCommand;
use Wolfcharaa\MessageBus\Cli\Command\WorkerPostgresSchemaCommand;
use Wolfcharaa\MessageBus\Cli\Command\WorkerRunCommand;
use Wolfcharaa\MessageBus\Cli\Command\WorkerStatusCommand;
use Wolfcharaa\MessageBus\Worker\WorkerControlCommandType;

final class ApplicationFactory
{
    public static function create(): Application
    {
        $application = new Application('message-bus');
        $application->add(new WorkerRunCommand());
        $application->add(new WorkerControlCliCommand());
        $application->add(new WorkerControlActionCommand(WorkerControlCommandType::Pause, 'worker:pause'));
        $application->add(new WorkerControlActionCommand(WorkerControlCommandType::Resume, 'worker:resume'));
        $application->add(new WorkerControlActionCommand(WorkerControlCommandType::Drain, 'worker:drain'));
        $application->add(new WorkerControlActionCommand(WorkerControlCommandType::Stop, 'worker:stop'));
        $application->add(new WorkerControlActionCommand(WorkerControlCommandType::Kill, 'worker:kill'));
        $application->add(new WorkerControlActionCommand(WorkerControlCommandType::Restart, 'worker:restart'));
        $application->add(new WorkerStatusCommand());
        $application->add(new PostgresSchemaCommand());
        $application->add(new WorkerPostgresSchemaCommand());
        $application->add(new PostgresLibrarySchemaCommand());
        $application->add(new RecoverStaleCommand());
        $application->add(new RegistryCompileCommand());

        return $application;
    }
}
