<?php

declare(strict_types=1);

namespace Switon\Http\Server\Adapter\Native;

use Switon\Http\ResponseSenderInterface;

/**
 * Native output adapter boundary for headers and body emission.
 *
 * @see \Switon\Http\ServerInterface
 * @see \Switon\Http\Server\Adapter\Native\Sender
 * @see \Switon\Http\Event\HeadersSending
 * @see \Switon\Http\Event\BodySending
 */
interface SenderInterface extends ResponseSenderInterface
{
}
