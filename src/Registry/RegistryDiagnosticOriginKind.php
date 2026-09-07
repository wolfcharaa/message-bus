<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

enum RegistryDiagnosticOriginKind: string
{
    case Attribute = 'attribute';
    case ReflectionClass = 'reflection_class';
    case ReflectionMethod = 'reflection_method';
    case Config = 'config';
    case Flow = 'flow';
    case Compiler = 'compiler';
    case ProjectRule = 'project_rule';
}
