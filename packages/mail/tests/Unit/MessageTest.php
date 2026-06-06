<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Mail\Address;
use Switon\Mail\Attachment;
use Switon\Mail\Exception\InvalidMessageException;
use Switon\Mail\Message;

class MessageTest extends TestCase
{
    public function testGetFromThrowsWhenUnset(): void
    {
        $message = Message::compose();

        $this->expectException(InvalidMessageException::class);
        $this->expectExceptionMessage('Mail message has no from address.');
        $message->getFrom();
    }

    public function testFluentApiPopulatesFields(): void
    {
        $from = new Address('from@example.com', 'Sender');
        $to = new Address('to@example.com');
        $cc = new Address('cc@example.com');
        $bcc = new Address('bcc@example.com');
        $reply = new Address('reply@example.com');
        $att = new Attachment('f.txt', 'text/plain', 'x');

        $message = Message::compose()
            ->from($from)
            ->to($to)
            ->cc($cc)
            ->bcc($bcc)
            ->replyTo($reply)
            ->subject('Subj')
            ->text('plain')
            ->html('<p>h</p>')
            ->attach($att)
            ->header('X-Test', '1')
            ->messageId('fixed-id@example.com')
            ->get();

        $this->assertSame($from, $message->getFrom());
        $this->assertSame([$to], $message->getTo());
        $this->assertSame([$cc], $message->getCc());
        $this->assertSame([$bcc], $message->getBcc());
        $this->assertSame($reply, $message->getReplyTo());
        $this->assertSame('Subj', $message->getSubject());
        $this->assertSame('plain', $message->getTextBody());
        $this->assertSame('<p>h</p>', $message->getHtmlBody());
        $this->assertSame([$att], $message->getAttachments());
        $this->assertSame(['X-Test' => '1'], $message->getHeaders());
        $this->assertSame('fixed-id@example.com', $message->getMessageId());
    }

    public function testAppendMultipleToRecipients(): void
    {
        $a = new Address('a@example.com');
        $b = new Address('b@example.com');
        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->to($a)
            ->to($b)
            ->get();

        $this->assertSame([$a, $b], $message->getTo());
    }

    public function testReplyToCanBeCleared(): void
    {
        $reply = new Address('reply@example.com');
        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->replyTo($reply)
            ->replyTo(null)
            ->get();

        $this->assertNull($message->getReplyTo());
    }

    public function testHeaderWithSameNameOverridesPreviousValue(): void
    {
        $message = Message::compose()
            ->from(new Address('from@example.com'))
            ->header('X-Trace', 'first')
            ->header('X-Trace', 'second')
            ->get();

        $this->assertSame(['X-Trace' => 'second'], $message->getHeaders());
    }
}
