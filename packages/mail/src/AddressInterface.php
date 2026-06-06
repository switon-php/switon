<?php

declare(strict_types=1);

namespace Switon\Mail;

/**
 * RFC-5322-style mailbox address (display name optional).
 *
 * Guidance: use this value object for message headers and recipient lists; callers usually pass <code>Address</code>.
 *
 * Road-signs:
 * - Message envelope
 * - MailerInterface::send()
 *
 * @see \Switon\Mail\MessageInterface
 * @see \Switon\Mail\MailerInterface
 */
interface AddressInterface
{
    /** SMTP addr-spec (e.g. user@example.com). */
    public function getEmail(): string;

    /** Display name for From/To headers, if any. */
    public function getName(): ?string;
}
