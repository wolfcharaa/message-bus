<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Discovery;

final class RegexFilterClassProvider implements ClassProviderInterface
{
    public function __construct(
        private readonly ClassProviderInterface $inner,
        private readonly string $pattern,
    ) {
    }

    public function classes(): iterable
    {
        foreach ($this->inner->classes() as $class) {
            if (\preg_match($this->pattern, $class) === 1) {
                yield $class;
            }
        }
    }
}
