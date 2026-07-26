<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Mail\Address;
use Switon\Mail\Attachment;
use Switon\Mail\Message;
use Switon\Mail\Rfc822Serializer;

class Rfc822SerializerTest extends TestCase
{
    protected function baseMessage(): Message
    {
        return Message::compose()
            ->from(new Address('from@example.com', 'From Name'))
            ->to(new Address('to@example.com'))
            ->subject('Hello')
            ->messageId('id@example.com');
    }

    public function testSerializeTextOnly(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = $this->baseMessage()->text('Body line')->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('From: "From Name" <from@example.com>', $raw);
        $this->assertStringContainsString('To: to@example.com', $raw);
        $this->assertStringContainsString('Subject: Hello', $raw);
        $this->assertStringContainsString('Message-ID: <id@example.com>', $raw);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $raw);
        $this->assertMatchesRegularExpression('/\r\n\r\n/', $raw);
        $this->assertStringContainsString(base64_encode('Body line'), str_replace("\r\n", '', $raw));
    }

    public function testSerializeHtmlOnly(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = $this->baseMessage()->html('<b>x</b>')->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $raw);
        $this->assertStringContainsString(base64_encode('<b>x</b>'), str_replace("\r\n", '', $raw));
    }

    public function testSerializeMultipartAlternative(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = $this->baseMessage()
            ->text('t')
            ->html('<p>h</p>')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('Content-Type: multipart/alternative; boundary=', $raw);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $raw);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $raw);
    }

    public function testSerializeWithAttachment(): void
    {
        $serializer = new Rfc822Serializer();
        $att = new Attachment('note.txt', 'text/plain', 'hi');
        $msg = $this->baseMessage()
            ->text('mail')
            ->attach($att)
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('Content-Type: multipart/mixed; boundary=', $raw);
        $this->assertStringContainsString('Content-Disposition: attachment; filename="note.txt"', $raw);
        $this->assertStringContainsString(base64_encode('hi'), str_replace("\r\n", '', $raw));
    }

    public function testOmitBccUsesUndisclosedWhenOnlyBcc(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = Message::compose()
            ->from(new Address('from@example.com'))
            ->bcc(new Address('hidden@example.com'))
            ->text('x')
            ->messageId('mid@example.com')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('To: undisclosed-recipients:;', $raw);
        $this->assertStringNotContainsString('hidden@example.com', $raw);
    }

    public function testIncludeBccInHeadersWhenRequested(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = Message::compose()
            ->from(new Address('from@example.com'))
            ->to(new Address('to@example.com'))
            ->bcc(new Address('bcc@example.com'))
            ->text('x')
            ->messageId('mid@example.com')
            ->get();

        $raw = $serializer->serialize($msg, false);
        $this->assertStringContainsString('Bcc: bcc@example.com', $raw);
    }

    public function testCustomHeaderIncludedReservedSkipped(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = $this->baseMessage()
            ->text('a')
            ->header('X-Custom', 'val')
            ->header('Subject', 'should-not-override')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('X-Custom: val', $raw);
        $this->assertStringContainsString('Subject: Hello', $raw);
        $this->assertStringNotContainsString('should-not-override', $raw);
    }

    public function testReplyToHeader(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = $this->baseMessage()
            ->replyTo(new Address('reply@example.com', 'Re'))
            ->text('a')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('Reply-To: "Re" <reply@example.com>', $raw);
    }

    public function testEncodeNonAsciiSubject(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = $this->baseMessage()
            ->subject('你好')
            ->text('a')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('Subject: =?UTF-8?B?' . base64_encode('你好') . '?=', $raw);
    }

    public function testUnicodeAttachmentFilename(): void
    {
        $serializer = new Rfc822Serializer();
        $name = '文件.txt';
        $att = new Attachment($name, 'text/plain', 'c');
        $msg = $this->baseMessage()
            ->text('a')
            ->attach($att)
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString("filename*=UTF-8''" . rawurlencode($name), $raw);
    }

    public function testDotStuff(): void
    {
        $serializer = new Rfc822Serializer();
        $out = $serializer->dotStuff(".first\r\n.second\r\nend");
        $this->assertSame("..first\r\n..second\r\nend", $out);
    }

    public function testDateHeaderPresent(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = $this->baseMessage()->text('a')->get();
        $raw = $serializer->serialize($msg, true);
        $this->assertMatchesRegularExpression('/^Date: .+/m', $raw);
    }
}
