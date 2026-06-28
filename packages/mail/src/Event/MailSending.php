<?php

declare(strict_types=1);

namespace Switon\Mail\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
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
class MailSending implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param MailerInterface $sender Mailer instance starting delivery.
     * @param MessageInterface $message Message about to be submitted to SMTP delivery.
     */
    public function __construct(
        public MailerInterface  $sender,
        public MessageInterface $message,
    ) {
    }

    public function getSender(): \Switon\Mail\MailerInterface
    {
        return $this->sender;
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
