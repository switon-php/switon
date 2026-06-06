<?php

declare(strict_types=1);

namespace Switon\Mail\Event;

use JsonSerializable;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Mail\MailerInterface;
use Switon\Mail\MessageInterface;
use Throwable;

/**
 * Event emitted when SMTP delivery fails.
 *
 * Log category: <code>switon.mail.failed</code>
 *
 * @see \Switon\Mail\Mailer
 * @see \Switon\Mail\Event\MailSending
 * @see \Switon\Mail\Event\MailSent
 */
#[EventLevel(Severity::INFO)]
class MailFailed implements JsonSerializable
{
    /**
     * @param MailerInterface $mailer Mailer instance whose delivery attempt failed.
     * @param MessageInterface $message Message that failed to send.
     * @param Throwable $exception Delivery failure that interrupted the send.
     * @param float $elapsed Elapsed time in seconds before the failure surfaced.
     */
    public function __construct(
        public MailerInterface  $mailer,
        public MessageInterface $message,
        public Throwable       $exception,
        public float            $elapsed,
    ) {
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $to = [];
        foreach ($this->message->getTo() as $address) {
            $to[] = $address->getEmail();
        }

        return [
            'to' => $to,
            'subject' => $this->message->getSubject(),
            'elapsed' => $this->elapsed,
        ];
    }
}
