<?php

declare(strict_types=1);

namespace Switon\Mail\Exception;

/**
 * STARTTLS advertisement or TLS handshake failed for the default SMTP mailer.
 *
 * @see \Switon\Mail\Mailer
 * @see \Switon\Mail\Exception\DeliveryException
 */
class TlsException extends DeliveryException
{
}
