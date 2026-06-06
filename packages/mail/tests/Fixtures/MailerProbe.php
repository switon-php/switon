<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Fixtures;

use Switon\Mail\Mailer;
use Switon\Mail\MessageInterface;

/**
 * @internal
 */
final class MailerProbe extends Mailer
{
    public ?MessageInterface $lastDelivered = null;

    /**
     * Unit tests construct this probe without container wiring.
     */
    public function __construct()
    {
        $this->eventDispatcher = new SpyEventDispatcher();
    }

    public function probeSerializer(): \Switon\Mail\Rfc822SerializerInterface
    {
        return $this->serializer;
    }

    protected function deliver(MessageInterface $message): void
    {
        $this->lastDelivered = $message;
    }
}
