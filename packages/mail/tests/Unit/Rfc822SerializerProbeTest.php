<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Mail\Address;
use Switon\Mail\Tests\Fixtures\Rfc822SerializerProbe;

/**
 * Direct tests for RFC822/MIME encoding helpers (via {@see Rfc822SerializerProbe}).
 */
class Rfc822SerializerProbeTest extends TestCase
{
    public function testDomainFromEmailUsesHostPart(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame('example.com', $p->probeDomainFromEmail('a@example.com'));
    }

    public function testDomainFromEmailLastAtSign(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame('b.c', $p->probeDomainFromEmail('x@a@b.c'));
    }

    public function testDomainFromEmailWithoutAtReturnsLocalhost(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame('localhost', $p->probeDomainFromEmail('localpart-only'));
    }

    public function testDomainFromEmailEmptyDomainReturnsLocalhost(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame('localhost', $p->probeDomainFromEmail('user@'));
    }

    public function testFilenameParamAsciiUsesQuotedFormWithEscapes(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame(
            'filename="a\\\\b\"c.txt"',
            $p->probeFilenameParam('a\\b"c.txt'),
        );
    }

    public function testFilenameParamEmptyString(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame('filename=""', $p->probeFilenameParam(''));
    }

    public function testFilenameParamNonAsciiUsesRfc2231(): void
    {
        $p = new Rfc822SerializerProbe();
        $name = "résumé.pdf";
        $this->assertSame(
            "filename*=UTF-8''" . rawurlencode($name),
            $p->probeFilenameParam($name),
        );
    }

    public function testFormatAddressEmailOnly(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame('u@example.com', $p->probeFormatAddress(new Address('u@example.com')));
    }

    public function testFormatAddressQuotedPrintableAsciiName(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame(
            '"Jane O\'Hara" <j@example.com>',
            $p->probeFormatAddress(new Address('j@example.com', "Jane O'Hara")),
        );
    }

    public function testFormatAddressEscapesBackslashAndQuoteInName(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame(
            '"Say \\"Hi\\"" <x@y.z>',
            $p->probeFormatAddress(new Address('x@y.z', 'Say "Hi"')),
        );
    }

    public function testFormatAddressUtf8NameUsesEncodedWord(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame(
            '=?UTF-8?B?' . base64_encode('李雷') . '?= <u@example.com>',
            $p->probeFormatAddress(new Address('u@example.com', '李雷')),
        );
    }

    public function testEncodeSubjectEmpty(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame('', $p->probeEncodeSubject(''));
    }

    public function testEncodeSubjectAsciiFoldsWhenLong(): void
    {
        $p = new Rfc822SerializerProbe();
        $long = str_repeat('M', 200);
        $encoded = $p->probeEncodeSubject($long);
        $this->assertStringContainsString("\r\n ", $encoded);
        $this->assertSame($long, str_replace(["\r\n ", "\r", "\n", ' '], '', $encoded));
    }

    public function testEncodeSubjectUtf8UsesBase64Word(): void
    {
        $p = new Rfc822SerializerProbe();
        $this->assertSame(
            '=?UTF-8?B?' . base64_encode('日本語') . '?=',
            $p->probeEncodeSubject('日本語'),
        );
    }

    public function testFoldHeaderValueUnchangedWhenShort(): void
    {
        $p = new Rfc822SerializerProbe();
        $v = str_repeat('x', 78);
        $this->assertSame($v, $p->probeFoldHeaderValue($v));
    }

    public function testFoldHeaderValueWrapsAt78(): void
    {
        $p = new Rfc822SerializerProbe();
        $v = str_repeat('z', 160);
        $folded = $p->probeFoldHeaderValue($v);
        $this->assertStringContainsString("\r\n ", $folded);
        $this->assertSame($v, preg_replace('/\s+/', '', $folded));
    }
}
