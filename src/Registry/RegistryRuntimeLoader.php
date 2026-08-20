<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use RuntimeException;
use Wolfcharaa\MessageBus\Discovery\ClassProviderInterface;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;

final class RegistryRuntimeLoader
{
    public function __construct(private readonly MessageRegistryCompiler $compiler = new MessageRegistryCompiler())
    {
    }

    public function load(
        ClassProviderInterface $provider,
        ?string $compiledFile = null,
        ?FlowRegistry $flows = null,
        string $libraryVersion = '5.0.0',
        string $sourceHash = '',
        bool $preferCompiled = true,
        bool $requireCompiled = false,
    ): CompiledMessageRegistry {
        if ($compiledFile !== null && $preferCompiled && \is_file($compiledFile)) {
            return CompiledMessageRegistry::fromFile($compiledFile);
        }

        if ($requireCompiled) {
            throw new RuntimeException('Compiled message registry file is required but was not found.');
        }

        return new CompiledMessageRegistry($this->compiler->compile($provider, $flows, $libraryVersion, $sourceHash));
    }
}
