<?php

declare(strict_types=1);

namespace Switon\Mail\Exception;

/**
 * SMTP submission failed (config, socket, TLS, auth, or protocol).
 *
 * Road-signs:
 * - ConfigurationException
 * - ConnectionException, TlsException
 * - AuthException, ProtocolException
 *
 * @see \Switon\Mail\Mailer
 */
abstract class DeliveryException extends MailException
{
}
