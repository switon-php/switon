<?php

declare(strict_types=1);

namespace Switon\Mail;

/**
 * Application entrypoint for sending outbound mail.
 *
 * Guidance:
 * - application services should depend on this contract and keep SMTP details inside the default implementation
 * - callers can compose a pre-filled message from the configured default sender, then send it through one entrypoint
 *
 * Road-signs:
 * - MessageInterface
 * - Mailer validates and dispatches delivery
 * - Rfc822SerializerInterface
 * - ConnectionInterface
 * - InvalidMessageException / DeliveryException
 *
 * @see \Switon\Core\Attribute\Autowired
 * @see \Switon\Mail\Mailer
 * @see \Switon\Mail\MessageInterface
 * @see \Switon\Mail\Rfc822SerializerInterface
 * @see \Switon\Mail\ConnectionInterface
 * @see \Switon\Mail\Exception\InvalidMessageException
 * @see \Switon\Mail\Exception\DeliveryException
 */
interface MailerInterface
{
    /**
     * Creates a new message pre-filled with the mailer's default sender.
     */
    public function compose(): Message;

    /**
     * Sends one message using the default SMTP mailer.
     *
     * Caller contract: provide a message with <code>from</code> plus at least one recipient in
     * <code>to</code>, <code>cc</code>, or <code>bcc</code>. Empty body is allowed.
     *
     * @throws \Switon\Mail\Exception\InvalidMessageException
     * @throws \Switon\Mail\Exception\DeliveryException
     */
    public function send(MessageInterface $message): void;
}
