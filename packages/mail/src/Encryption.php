<?php

declare(strict_types=1);

namespace Switon\Mail;

/**
 * TLS mode for the default SMTP mailer (plain, STARTTLS, or implicit TLS on port 465).
 *
 * @see \Switon\Mail\Mailer
 */
enum Encryption: string
{
    case None = 'none';
    case StartTls = 'starttls';
    case Ssl = 'ssl';
}
