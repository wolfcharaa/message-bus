<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

final class WorkerCliOutputConfig
{
    public function __construct(
        public readonly WorkerCliOutputVerbosity $verbosity = WorkerCliOutputVerbosity::Normal,
        public readonly WorkerCliOutputFormat $format = WorkerCliOutputFormat::Text,
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        return new self(
            verbosity: isset($config['verbosity'])
                ? WorkerCliOutputVerbosity::fromString((string) $config['verbosity'])
                : WorkerCliOutputVerbosity::Normal,
            format: isset($config['format'])
                ? WorkerCliOutputFormat::fromString((string) $config['format'])
                : WorkerCliOutputFormat::Text,
        );
    }
}
