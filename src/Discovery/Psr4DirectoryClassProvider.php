<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Discovery;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class Psr4DirectoryClassProvider implements ClassProviderInterface
{
    /** @param array<string, string> $paths namespace prefix => directory */
    public function __construct(private readonly array $paths)
    {
    }

    public function classes(): iterable
    {
        foreach ($this->paths as $prefix => $directory) {
            if (!\is_dir($directory)) {
                continue;
            }

            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
            /** @var SplFileInfo $file */
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $relative = \substr($file->getPathname(), \strlen(\rtrim($directory, DIRECTORY_SEPARATOR)) + 1);
                $class = \rtrim($prefix, '\\') . '\\' . \str_replace(
                    [DIRECTORY_SEPARATOR, '.php'],
                    ['\\', ''],
                    $relative,
                );

                if (\class_exists($class)) {
                    yield $class;
                }
            }
        }
    }
}
