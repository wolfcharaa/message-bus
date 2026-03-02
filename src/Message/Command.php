<?php

declare(strict_types=1);

namespace App\MessageBus\Message;

/**
 * @template-covariant TResult = void
 * @extends Message<TResult>
 */
interface Command extends Message
{
}
