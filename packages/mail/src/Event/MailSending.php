<?php

declare(strict_types=1);

namespace Switon\Mail\Event;

use JsonSerializable;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Mail\MailerInterface;
use Switon\Mail\MessageInterface;

/**
 * Event emitted before SMTP delivery starts.
 *
 * Log category: <code>switon.mail.sending</code>
 *
 * @see \Switon\Mail\Mailer
 * @see \Switon\Mail\Event\MailSent
 * @see \Switon\Mail\Event\MailFailed
 */
#[EventLevel(Severity::DEBUG)]
class MailSending implements JsonSerializable
{
    /**
     * @param MailerInterface $mailer Mailer instance starting delivery.
     * @param MessageInterface $message Message about to be submitted to SMTP delivery.
     */
    public function __construct(
        public MailerInterface  $mailer,
        public MessageInterface $message,
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
        ];
    }
}
