<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

enum WorkerCliOutputVerbosity: string
{
    case Quiet = 'quiet';
    case Normal = 'normal';
    case Debug = 'debug';
    case Trace = 'trace';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new \InvalidArgumentException('Worker CLI output verbosity must be one of: quiet, normal, debug, trace.');
    }

    public function allows(self $required): bool
    {
        return $this->rank() >= $required->rank();
    }

    private function rank(): int
    {
        return match ($this) {
            self::Quiet => 0,
            self::Normal => 1,
            self::Debug => 2,
            self::Trace => 3,
        };
    }
}
