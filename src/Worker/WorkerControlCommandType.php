<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

enum WorkerControlCommandType: string
{
    case Pause = 'pause';
    case Resume = 'resume';
    case Drain = 'drain';
    case Stop = 'stop';
    case Kill = 'kill';
    case Restart = 'restart';

    public function isOneShot(): bool
    {
        return match ($this) {
            self::Drain, self::Stop, self::Kill, self::Restart => true,
            self::Pause, self::Resume => false,
        };
    }
}
