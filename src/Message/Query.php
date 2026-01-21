<?php

declare(strict_types=1);

namespace App\MessageBus\Message;

/**
 * @template-covariant TResult
 * @extends Message<TResult>
 */
interface Query extends Message
{
}
