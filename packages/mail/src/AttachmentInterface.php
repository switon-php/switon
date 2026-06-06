<?php

declare(strict_types=1);

namespace Switon\Mail;

/**
 * MIME attachment payload (filename + media type + raw bytes).
 *
 * Guidance: implementations carry the already-loaded attachment bytes; serialization happens later in the RFC 822 serializer.
 *
 * Road-signs:
 * - multipart messages
 * - MailerInterface::send()
 *
 * @see \Switon\Mail\MessageInterface
 * @see \Switon\Mail\MailerInterface
 */
interface AttachmentInterface
{
    public function getFilename(): string;

    /** e.g. application/pdf, image/png */
    public function getMediaType(): string;

    /** Raw attachment body (binary-safe string). */
    public function getContent(): string;
}
