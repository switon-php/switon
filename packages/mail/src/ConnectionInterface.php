<?php

declare(strict_types=1);

namespace Switon\Mail;

/**
 * Sends one RFC 5322 payload through one connection.
 *
 * Guidance:
 * - use this boundary to hide SMTP transport details from application code
 * - the mailer validates message structure before calling this interface; implementations focus on transport delivery
 *
 * Road-signs:
 * - Delivery carries SMTP settings + payload
 * - Connection is the default curl implementation
 * - DeliveryException leaves describe delivery failures
 *
 * @see \Switon\Mail\Delivery
 * @see \Switon\Mail\Connection
 * @see \Switon\Mail\Exception\DeliveryException
 */
interface ConnectionInterface
{
    /**
     * Sends one prepared SMTP payload.
     *
     * @throws \Switon\Mail\Exception\DeliveryException
     */
    public function send(Delivery $request): void;
}
