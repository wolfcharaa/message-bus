<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli;

use Psr\Container\ContainerInterface;
use RuntimeException;
use Wolfcharaa\MessageBus\Queue\QueueWorkerRunner;
use Wolfcharaa\MessageBus\Runtime\MessageBusRuntime;

final class BootstrapResolver
{
    /** @param list<string> $conventionPaths */
    public function __construct(
        private readonly array $conventionPaths = [
            'message-bus.php',
            'config/message-bus.php',
            'config/message_bus.php',
            'config/message_bus_runtime.php',
        ],
    ) {
    }

    public function resolve(?string $bootstrapPath = null): MessageBusRuntime|QueueWorkerRunner
    {
        $path = $bootstrapPath
            ?: (\getenv('MESSAGE_BUS_BOOTSTRAP') !== false ? \getenv('MESSAGE_BUS_BOOTSTRAP') : null)
            ?: $this->conventionPath();

        if ($path === null || !\is_file($path)) {
            throw new RuntimeException('MessageBus bootstrap file was not found. Pass --bootstrap or set MESSAGE_BUS_BOOTSTRAP.');
        }

        $runtime = require $path;

        if ($runtime instanceof MessageBusRuntime || $runtime instanceof QueueWorkerRunner) {
            return $runtime;
        }

        if ($runtime instanceof ContainerInterface) {
            return MessageBusRuntime::fromContainer($runtime);
        }

        throw new RuntimeException('MessageBus bootstrap must return MessageBusRuntime, QueueWorkerRunner or PSR-11 container.');
    }

    private function conventionPath(): ?string
    {
        foreach ($this->conventionPaths as $path) {
            if (\is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
