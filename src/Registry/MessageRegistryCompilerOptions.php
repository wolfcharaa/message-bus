<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

final readonly class MessageRegistryCompilerOptions
{
    public function __construct(
        public bool $failOnWarning = false,
    ) {
    }
}
