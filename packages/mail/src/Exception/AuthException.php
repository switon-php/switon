<?php

declare(strict_types=1);

namespace Switon\Mail\Exception;

/**
 * SMTP AUTH not available or mechanism unsupported by client.
 *
 * @see \Switon\Mail\Mailer
 * @see \Switon\Mail\Exception\DeliveryException
 */
class AuthException extends DeliveryException
{
}
