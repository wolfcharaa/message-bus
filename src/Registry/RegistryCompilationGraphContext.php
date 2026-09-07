<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

use Wolfcharaa\MessageBus\Flow\FlowRegistry;

final readonly class RegistryCompilationGraphContext
{
    /**
     * @param list<HandlerBindingDefinition> $bindings
     * @param array<string, class-string> $aliases
     * @param array<class-string, string> $messageNames
     */
    public function __construct(
        public RegistryCompilationStage $stage,
        public array $bindings = [],
        public array $aliases = [],
        public array $messageNames = [],
        public ?FlowRegistry $flows = null,
    ) {
    }
}
