<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli;

use Wolfcharaa\MessageBus\Discovery\ClassProviderInterface;
use Wolfcharaa\MessageBus\Flow\FlowRegistry;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompiler;
use Wolfcharaa\MessageBus\Registry\MessageRegistryCompilerOptions;
use Wolfcharaa\MessageBus\Registry\RegistryValidationRuleInterface;

final readonly class RegistryCompileInput
{
    /** @param iterable<RegistryValidationRuleInterface> $validationRules */
    public function __construct(
        public ClassProviderInterface $provider,
        public ?FlowRegistry $flows = null,
        public string $libraryVersion = MessageRegistryCompiler::LIBRARY_VERSION,
        public string $sourceHash = '',
        public ?MessageRegistryCompilerOptions $options = null,
        public iterable $validationRules = [],
    ) {
    }

    public function compiler(): MessageRegistryCompiler
    {
        return new MessageRegistryCompiler(validationRules: $this->validationRules);
    }
}
