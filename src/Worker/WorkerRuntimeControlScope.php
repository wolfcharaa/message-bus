<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

final class WorkerRuntimeControlScope
{
    private static ?WorkerRuntimeControlInterface $current = null;

    private function __construct()
    {
    }

    public static function current(): ?WorkerRuntimeControlInterface
    {
        return self::$current;
    }

    public static function run(?WorkerRuntimeControlInterface $control, callable $callback): mixed
    {
        $previous = self::$current;
        self::$current = $control;

        try {
            return $callback();
        } finally {
            self::$current = $previous;
        }
    }
}
