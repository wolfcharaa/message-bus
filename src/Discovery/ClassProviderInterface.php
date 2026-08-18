<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Discovery;

interface ClassProviderInterface
{
    /** @return iterable<class-string> */
    public function classes(): iterable;
}
