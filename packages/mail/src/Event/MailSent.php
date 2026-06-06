<?php

declare(strict_types=1);

namespace Switon\Mail\Event;

use JsonSerializable;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Mail\MailerInterface;
use Switon\Mail\MessageInterface;

/**
 * Event emitted after SMTP delivery succeeds.
 *
 * Log category: <code>switon.mail.sent</code>
 *
 * @see \Switon\Mail\Mailer
 * @see \Switon\Mail\Event\MailSending
 * @see \Switon\Mail\Event\MailFailed
 */
#[EventLevel(Severity::DEBUG)]
class MailSent implements JsonSerializable
{
    /**
     * @param MailerInterface $mailer Mailer instance that completed delivery.
     * @param MessageInterface $message Message that was delivered successfully.
     * @param float $elapsed Delivery time in seconds.
     */
    public function __construct(
        public MailerInterface  $mailer,
        public MessageInterface $message,
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
