<?php

declare(strict_types=1);

namespace Switon\Mail;

/**
 * Serializes MessageInterface to RFC 5322 wire form (headers + MIME body) and applies SMTP dot-stuffing.
 *
 * Guidance: use this when a validated message must become SMTP DATA payload text without exposing MIME details to callers.
 *
 * @see \Switon\Mail\Rfc822Serializer
 * @see \Switon\Mail\Mailer
 */
interface Rfc822SerializerInterface
{
    /**
     * @param bool $omitBccInHeaders Use true when Bcc should stay in the SMTP envelope only.
     */
    public function serialize(MessageInterface $message, bool $omitBccInHeaders): string;

    /** Dot-stuff per RFC 5321 for SMTP DATA payload. */
    public function dotStuff(string $payload): string;
}
