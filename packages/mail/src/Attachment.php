<?php

declare(strict_types=1);

namespace Switon\Mail;

/**
 * Binary attachment for MIME multipart messages.
 *
 * @see \Switon\Mail\AttachmentInterface
 */
class Attachment implements AttachmentInterface
{
    public function __construct(
        protected string $filename,
        protected string $mediaType,
        protected string $content,
    ) {
    }

    public function getFilename(): string
    {
        return $this->filename;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
