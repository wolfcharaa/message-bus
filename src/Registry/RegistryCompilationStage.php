<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

enum RegistryCompilationStage: string
{
    case HandlersDiscovered = 'handlers_discovered';
    case AliasesHydrated = 'aliases_hydrated';
    case BindingsNormalized = 'bindings_normalized';
    case CoreValidated = 'core_validated';
    case ProjectRulesValidated = 'project_rules_validated';
    case DefinitionBuilt = 'definition_built';
}
