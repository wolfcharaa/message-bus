<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

enum RegistryDiagnosticSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
