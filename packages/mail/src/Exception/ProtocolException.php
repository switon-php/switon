<?php

declare(strict_types=1);

namespace Switon\Mail\Exception;

/**
 * Malformed SMTP reply or negative SMTP completion (4xx/5xx) after a command.
 *
 * @see \Switon\Mail\Mailer
 * @see \Switon\Mail\Exception\DeliveryException
 */
class ProtocolException extends DeliveryException
{
}
