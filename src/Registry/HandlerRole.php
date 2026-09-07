<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

enum HandlerRole: string
{
    case Application = 'application';
    case Domain = 'domain';
}
