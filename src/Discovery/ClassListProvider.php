<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Discovery;

final class ClassListProvider implements ClassProviderInterface
{
    /** @param list<class-string> $classes */
    public function __construct(private readonly array $classes)
    {
    }

    public function classes(): iterable
    {
        yield from $this->classes;
    }
}
