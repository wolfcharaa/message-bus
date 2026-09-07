<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

enum HandlerInvocationMode: string
{
    case ContextAware = 'context_aware';
    case Contextless = 'contextless';
}
