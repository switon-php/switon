<?php

declare(strict_types=1);

namespace Switon\Mail\Exception;

/**
 * SMTP mailer is misconfigured (e.g. empty host or invalid encryption).
 *
 * @see \Switon\Mail\Mailer
 * @see \Switon\Mail\Exception\DeliveryException
 */
class ConfigurationException extends DeliveryException
{
}
