<?php

declare(strict_types=1);

namespace Switon\Mail\Exception;

/**
 * TCP/TLS socket to the SMTP server failed or dropped before a valid SMTP completion.
 *
 * @see \Switon\Mail\Mailer
 * @see \Switon\Mail\Exception\DeliveryException
 */
class ConnectionException extends DeliveryException
{
}
