<?php

declare(strict_types=1);

namespace Switon\Mail\Exception;

/**
 * Message is missing required fields (e.g. from, recipients) before send.
 *
 * @see \Switon\Mail\Message::getFrom()
 * @see \Switon\Mail\Mailer
 */
class InvalidMessageException extends MailException
{
}
