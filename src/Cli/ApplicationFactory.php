<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli;

use Symfony\Component\Console\Application;
use Wolfcharaa\MessageBus\Cli\Command\PostgresSchemaCommand;
use Wolfcharaa\MessageBus\Cli\Command\RecoverStaleCommand;
use Wolfcharaa\MessageBus\Cli\Command\RegistryCompileCommand;
use Wolfcharaa\MessageBus\Cli\Command\WorkerRunCommand;

final class ApplicationFactory
{
    public static function create(): Application
    {
        $application = new Application('message-bus');
        $application->add(new WorkerRunCommand());
        $application->add(new PostgresSchemaCommand());
        $application->add(new RecoverStaleCommand());
        $application->add(new RegistryCompileCommand());

        return $application;
    }
}
