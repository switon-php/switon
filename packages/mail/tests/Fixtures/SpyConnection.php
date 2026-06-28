<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Fixtures;

use Switon\Mail\ConnectionInterface;
use Switon\Mail\Delivery;
use Switon\Mail\MailerInterface;

/**
 * @internal
 */
final class SpyConnection implements ConnectionInterface
{
    protected MailerInterface $owner;

    public function __construct(MailerInterface $owner = new StubMailer())
    {
        $this->owner = $owner;
    }

    public function getOwner(): MailerInterface
    {
        return $this->owner;
    }

    public ?Delivery $lastRequest = null;

    public int $sendCount = 0;

    public function send(Delivery $request): void
    {
        $this->lastRequest = $request;
        $this->sendCount++;
    }
}
