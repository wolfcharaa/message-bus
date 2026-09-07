<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

enum DeprecationDiagnosticsMode: string
{
    case Ignore = 'ignore';
    case Warn = 'warn';
    case Fail = 'fail';
}
