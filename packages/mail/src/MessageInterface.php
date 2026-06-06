<?php

declare(strict_types=1);

namespace Switon\Mail;

/**
 * Outbound mail (headers + bodies + attachments); typically a {@see Message} instance.
 *
 * Guidance:
 * - callers usually build this through <code>MailerInterface::compose()</code> or <code>Message::compose()</code>
 * - implementations should validate sender and recipients before send; an empty body is allowed
 * - empty bodies serialize as an empty <code>text/plain</code> part by default
 *
 * Road-signs:
 * - MailerInterface::send()
 * - Mailer SMTP flow
 *
 * @see \Switon\Mail\MailerInterface
 * @see \Switon\Mail\Mailer
 */
interface MessageInterface
{
    /** Returns the sender address used for the SMTP envelope and RFC 5322 headers. */
    public function getFrom(): AddressInterface;

    /** @return list<AddressInterface> */
    public function getTo(): array;

    /** @return list<AddressInterface> */
    public function getCc(): array;

    /** @return list<AddressInterface> */
    public function getBcc(): array;

    public function getReplyTo(): ?AddressInterface;

    /** Returns the subject line exactly as configured by the caller. */
    public function getSubject(): string;

    /** Returns the plain-text body, or null when not set. */
    public function getTextBody(): ?string;

    /** Returns the HTML body, or null when not set. */
    public function getHtmlBody(): ?string;

    /** @return list<AttachmentInterface> */
    public function getAttachments(): array;

    /**
     * Extra RFC 5322 headers not covered by dedicated accessors (single value per name).
     *
     * @return array<string, string>
     */
    public function getHeaders(): array;

    /** Optional Message-ID header value without angle brackets; null if the mailer should generate it. */
    public function getMessageId(): ?string;
}
