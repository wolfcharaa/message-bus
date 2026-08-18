<?php

declare(strict_types=1);

namespace Wolfcharaa\MessageBus\Registry;

enum HandlerKind: string
{
    case Command = 'command';
    case Query = 'query';
    case Event = 'event';
}
