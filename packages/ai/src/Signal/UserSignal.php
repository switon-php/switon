<?php

declare(strict_types=1);

namespace Switon\Ai\Signal;

use Switon\Ai\Message\UserMessage;

/**
 * User-side signal row; wire role {@see UserMessage::Role}.
 *
 * Guidance: `$name` carries the event, so a one-off needs no subclass — subclass only for fixed
 * fields or a stable label ({@see UserAbortSignal}).
 */
class UserSignal extends \Switon\Ai\Signal
{
    public const string Role = UserMessage::Role;
}
