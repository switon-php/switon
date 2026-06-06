<?php

declare(strict_types=1);

namespace Switon\Mail\Exception;

use Switon\Mail\Exception;

/**
 * Base mail failure; catch subclasses for validation or delivery diagnosis.
 *
 * Road-signs:
 * - InvalidMessageException
 * - DeliveryException and leaf delivery types
 *
 * @see \Switon\Mail\MailerInterface
 * @see \Switon\Mail\Mailer
 * @see \Switon\Mail\Exception\DeliveryException
 */
class MailException extends Exception
{
}
