<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Wolfcharaa\MessageBus\Discovery\ClassListProvider;
use Wolfcharaa\MessageBus\Dumper\CompiledRegistryFileWriter;
use Wolfcharaa\MessageBus\Flow\FlowDefinition;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Registry\CompiledMessageRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryDefinition;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\RegistryRuntimeLoader;

final class RegistryRuntimeLoaderAndWriterTest extends TestCase
{
    public function testWriterAtomicallyWritesLoadableCompiledRegistryFile(): void
    {
        $file = \sys_get_temp_dir() . '/message-bus-registry-writer-' . \bin2hex(\random_bytes(6)) . '/registry.php';
        $definition = $this->definition('writer');

        try {
            $written = (new CompiledRegistryFileWriter())->write($definition, $file);
            $registry = CompiledMessageRegistry::fromFile($file);

            self::assertSame($file, $written);
            self::assertSame('writer', $registry->definition()->sourceHash);
        } finally {
            @\unlink($file);
            @\rmdir(\dirname($file));
        }
    }

    public function testRuntimeLoaderPrefersCompiledRegistryFile(): void
    {
        $file = \tempnam(\sys_get_temp_dir(), 'message-bus-registry-loader-');
        self::assertIsString($file);

        try {
            (new CompiledRegistryFileWriter())->write($this->definition('compiled'), $file);
            $registry = (new RegistryRuntimeLoader())->load(
                new ClassListProvider([]),
                compiledFile: $file,
                sourceHash: 'runtime',
            );

            self::assertSame('compiled', $registry->definition()->sourceHash);
        } finally {
            @\unlink($file);
        }
    }

    public function testRuntimeLoaderCanRequireCompiledRegistryFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Compiled message registry file is required but was not found.');

        (new RegistryRuntimeLoader())->load(
            new ClassListProvider([]),
            compiledFile: \sys_get_temp_dir() . '/message-bus-missing-registry.php',
            requireCompiled: true,
        );
    }

    private function definition(string $sourceHash): MessageRegistryDefinition
    {
        return new MessageRegistryDefinition(
            MessageRegistryCompiler::SCHEMA_VERSION,
            '5.0.0',
            '2026-08-20T10:00:00+00:00',
            $sourceHash,
            new FlowRegistry(FlowDefinition::sync('default')),
            [],
            [],
            [],
            [],
        );
    }
}
