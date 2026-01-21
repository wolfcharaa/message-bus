<?php

declare(strict_types=1);

namespace App\MessageBus\Message;

/**
 * @extends Message<void>
 */
interface Command extends Message
{
}
