<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Mail\Address;
use Switon\Mail\Attachment;
use Switon\Mail\Message;
use Switon\Mail\Rfc822Serializer;

/**
 * End-to-end serializer scenarios not covered by {@see Rfc822SerializerTest}.
 */
class Rfc822SerializerDeepTest extends TestCase
{
    public function testAutoMessageIdWhenNullUsesHex32AtFromDomain(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = Message::compose()
            ->from(new Address('a@myapp.test'))
            ->to(new Address('b@example.com'))
            ->text('x')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertMatchesRegularExpression(
            '/Message-ID: <[a-f0-9]{32}@myapp\.test>/',
            $raw,
        );
    }

    public function testEmptyMessageIdStringTriggersAutoGeneration(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = Message::compose()
            ->from(new Address('a@z.test'))
            ->to(new Address('b@test'))
            ->text('x')
            ->messageId('')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertMatchesRegularExpression('/Message-ID: <[a-f0-9]{32}@z\.test>/', $raw);
    }

    public function testMessageIdTrimsAngleBrackets(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = Message::compose()
            ->from(new Address('a@b.com'))
            ->to(new Address('c@d.com'))
            ->text('x')
            ->messageId('<id@example.com>')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('Message-ID: <id@example.com>', $raw);
        $this->assertStringNotContainsString('<<', $raw);
    }

    public function testReservedHeaderKeysSkippedCaseInsensitive(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = Message::compose()
            ->from(new Address('a@b.com'))
            ->to(new Address('c@d.com'))
            ->subject('Real')
            ->text('t')
            ->header('MIME-Version', '2.0')
            ->header('content-type', 'text/bogus')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('MIME-Version: 1.0', $raw);
        $this->assertStringNotContainsString('text/bogus', $raw);
    }

    public function testMultipleToAddressesCommaSeparated(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = Message::compose()
            ->from(new Address('a@b.com'))
            ->to(new Address('one@test.com'), new Address('two@test.com'))
            ->text('x')
            ->messageId('m@b.com')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('To: one@test.com, two@test.com', $raw);
    }

    public function testNeitherTextNorHtmlYieldsEmptyPlainPart(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = Message::compose()
            ->from(new Address('a@b.com'))
            ->to(new Address('c@d.com'))
            ->messageId('m@b.com')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('Content-Type: text/plain; charset=UTF-8', $raw);
        [, $body] = explode("\r\n\r\n", $raw, 2);
        $this->assertIsString($body);
        $this->assertSame('', preg_replace('/\s+/', '', $body), 'empty text part should have no base64 payload');
    }

    public function testBccOnlyWithHeadersShowsBccNotUndisclosed(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = Message::compose()
            ->from(new Address('a@b.com'))
            ->bcc(new Address('hidden@b.com'))
            ->text('x')
            ->messageId('m@b.com')
            ->get();

        $raw = $serializer->serialize($msg, false);
        $this->assertStringContainsString('Bcc: hidden@b.com', $raw);
        $this->assertStringNotContainsString('undisclosed-recipients', $raw);
    }

    public function testHtmlOnlyWithAttachmentUsesMultipartMixed(): void
    {
        $serializer = new Rfc822Serializer();
        $msg = Message::compose()
            ->from(new Address('a@b.com'))
            ->to(new Address('c@d.com'))
            ->html('<p>x</p>')
            ->attach(new Attachment('f.bin', 'application/octet-stream', "\x00\x01"))
            ->messageId('m@b.com')
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertStringContainsString('Content-Type: multipart/mixed; boundary=', $raw);
        $this->assertStringContainsString('Content-Type: text/html; charset=UTF-8', $raw);
    }

    public function testLongCustomHeaderValueIsFolded(): void
    {
        $serializer = new Rfc822Serializer();
        $long = str_repeat('L', 200);
        $msg = Message::compose()
            ->from(new Address('a@b.com'))
            ->to(new Address('c@d.com'))
            ->text('x')
            ->messageId('m@b.com')
            ->header('X-Long', $long)
            ->get();

        $raw = $serializer->serialize($msg, true);
        $this->assertMatchesRegularExpression('/X-Long: L+\r\n /', $raw);
    }

    public function testDotStuffLeavesNormalLinesUnchanged(): void
    {
        $serializer = new Rfc822Serializer();
        $in = "a\r\nplain.line\r\nend";
        $this->assertSame($in, $serializer->dotStuff($in));
    }
}
