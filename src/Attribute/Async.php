<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Attribute;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class Async
{
    public const IS_STARTED = 'isStarted';
    public function __construct(
        public readonly string $driver = 'sync',
    ) {
    }
}
