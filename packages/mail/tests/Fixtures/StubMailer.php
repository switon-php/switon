<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Fixtures;

use Switon\Mail\MailerInterface;
use Switon\Mail\Message;
use Switon\Mail\MessageInterface;

/** @internal Minimal {@see MailerInterface} stand-in for connection owner typing in tests. */
final class StubMailer implements MailerInterface
{
    public function compose(): Message
    {
        return new Message();
    }

    public function send(MessageInterface $message): void
    {
    }
}
