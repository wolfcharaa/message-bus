<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Discovery;

final class ComposerClassMapProvider implements ClassProviderInterface
{
    /** @param list<string> $namespacePrefixes */
    public function __construct(
        private readonly string $classMapFile,
        private readonly array $namespacePrefixes = [],
    ) {
    }

    public function classes(): iterable
    {
        if (!\is_file($this->classMapFile)) {
            return;
        }

        $classMap = require $this->classMapFile;
        if (!\is_array($classMap)) {
            return;
        }

        foreach (\array_keys($classMap) as $class) {
            if ($this->namespacePrefixes === [] || $this->matchesPrefix($class)) {
                yield $class;
            }
        }
    }

    private function matchesPrefix(string $class): bool
    {
        foreach ($this->namespacePrefixes as $prefix) {
            if (\str_starts_with($class, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
