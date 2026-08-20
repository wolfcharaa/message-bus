<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Middleware;

enum LoggingMiddlewareMode: string
{
    case FailuresOnly = 'failures_only';
    case StartedAndFailed = 'started_and_failed';
    case StartedFinishedAndFailed = 'started_finished_and_failed';
}
