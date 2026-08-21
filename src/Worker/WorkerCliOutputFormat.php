<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Worker;

enum WorkerCliOutputFormat: string
{
    case Text = 'text';
    case Json = 'json';

    public static function fromString(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new \InvalidArgumentException('Worker CLI output format must be one of: text, json.');
    }
}
