<?php

declare(strict_types=1);

namespace Switon\Mail;

use Switon\Mail\Exception\InvalidMessageException;

/**
 * Outbound mail; assign properties / fluent methods, or start from {@see static::compose()}.
 *
 * @see \Switon\Mail\MessageInterface
 */
class Message implements MessageInterface
{
    protected ?AddressInterface $from = null;

    /** @var list<AddressInterface> */
    protected array $to = [];

    /** @var list<AddressInterface> */
    protected array $cc = [];

    /** @var list<AddressInterface> */
    protected array $bcc = [];

    protected ?AddressInterface $replyTo = null;

    protected string $subject = '';

    protected ?string $textBody = null;

    protected ?string $htmlBody = null;

    /** @var list<AttachmentInterface> */
    protected array $attachments = [];

    /** @var array<string, string> */
    protected array $headers = [];

    protected ?string $messageId = null;

    public function __construct()
    {
    }

    public static function compose(): static
    {
        return new static();
    }

    public function from(AddressInterface $address): static
    {
        $this->from = $address;

        return $this;
    }

    /** Appends primary recipients. */
    public function to(AddressInterface ...$addresses): static
    {
        foreach ($addresses as $a) {
            $this->to[] = $a;
        }

        return $this;
    }

    public function cc(AddressInterface ...$addresses): static
    {
        foreach ($addresses as $a) {
            $this->cc[] = $a;
        }

        return $this;
    }

    public function bcc(AddressInterface ...$addresses): static
    {
        foreach ($addresses as $a) {
            $this->bcc[] = $a;
        }

        return $this;
    }

    public function replyTo(?AddressInterface $address): static
    {
        $this->replyTo = $address;

        return $this;
    }

    public function subject(string $subject): static
    {
        $this->subject = $subject;

        return $this;
    }

    /** Plain-text body (can combine with {@see html()} for multipart/alternative). */
    public function text(?string $body): static
    {
        $this->textBody = $body;

        return $this;
    }

    /** HTML body (can combine with {@see text()} for multipart/alternative). */
    public function html(?string $body): static
    {
        $this->htmlBody = $body;

        return $this;
    }

    public function attach(AttachmentInterface $attachment): static
    {
        $this->attachments[] = $attachment;

        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function messageId(?string $id): static
    {
        $this->messageId = $id;

        return $this;
    }

    public function get(): MessageInterface
    {
        return $this;
    }

    public function getFrom(): AddressInterface
    {
        if ($this->from === null) {
            InvalidMessageException::raise('Mail message has no from address.');
        }

        return $this->from;
    }

    public function getTo(): array
    {
        return $this->to;
    }

    public function getCc(): array
    {
        return $this->cc;
    }

    public function getBcc(): array
    {
        return $this->bcc;
    }

    public function getReplyTo(): ?AddressInterface
    {
        return $this->replyTo;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getTextBody(): ?string
    {
        return $this->textBody;
    }

    public function getHtmlBody(): ?string
    {
        return $this->htmlBody;
    }

    public function getAttachments(): array
    {
        return $this->attachments;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }
}
