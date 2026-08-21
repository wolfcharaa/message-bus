<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Cli;

enum ExitCode: int
{
    case Success = 0;
    case GenericError = 1;
    case RestartRequested = 10;
    case SchemaMismatch = 20;
    case SchemaValidationFailed = 21;
    case InvalidRuntimeConfig = 30;
    case MissingRequiredExtension = 31;
    case ExhaustedCriticalRetry = 40;
    case UnsupportedCommand = 50;
}
