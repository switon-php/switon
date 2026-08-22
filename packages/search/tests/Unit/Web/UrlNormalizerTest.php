<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Unit\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Search\Exception;
use Switon\Search\Web\UrlNormalizer;
use Switon\Testing\TestCase;

use function rawurlencode;

#[CoversClass(UrlNormalizer::class)]
class UrlNormalizerTest extends TestCase
{
    public function testStripsWwwTrailingSlashAndUtm(): void
    {
        $this->assertSame(
            'https://example.com/docs',
            UrlNormalizer::key('https://www.example.com/docs/?utm_source=x&utm_medium=y'),
        );
    }

    public function testUnwrapsDuckDuckGoUddg(): void
    {
        $href = '//duckduckgo.com/l/?uddg=' . rawurlencode('https://github.com/switon-php/agency') . '&rut=abc';
        $this->assertSame('https://github.com/switon-php/agency', UrlNormalizer::key($href));
    }

    public function testKeepsPortAndDoesNotUnwrapLookalikeDuckDuckGoHosts(): void
    {
        $this->assertSame('https://example.com:8443/a', UrlNormalizer::key('https://example.com:8443/a'));
        $this->assertSame('https://example.com/a', UrlNormalizer::key('https://example.com:443/a'));

        $lookalike = 'https://evilduckduckgo.com/l/?uddg=' . rawurlencode('https://example.com/secret');
        $this->assertStringStartsWith('https://evilduckduckgo.com/', UrlNormalizer::key($lookalike));
        $this->assertSame('https://example.com:8443/', UrlNormalizer::origin('https://example.com:8443/api'));
    }

    public function testKeyDropsEmptyUrlsTrackingParamsAndBareHosts(): void
    {
        $this->assertSame('', UrlNormalizer::key('   '));
        $this->assertSame('https://example.com/', UrlNormalizer::key('https://example.com'));
        $this->assertSame(
            'https://example.com/a?x=1',
            UrlNormalizer::key('https://example.com/a?x=1&fbclid=z&gclid=y'),
        );
        $this->assertSame('one two', UrlNormalizer::collapseWhitespace(" one \n two "));
    }

    public function testDuckDuckGoWithoutATargetStaysPut(): void
    {
        $this->assertSame('https://duckduckgo.com/l?q=php', UrlNormalizer::key('https://duckduckgo.com/l/?q=php'));
        $this->assertSame('https://duckduckgo.com/?q=php', UrlNormalizer::key('https://duckduckgo.com/?q=php'));
    }

    public function testSiteHostAndOriginRejectANonHost(): void
    {
        $this->assertSame('github.com', UrlNormalizer::siteHost('GitHub.com/switon'));
        $this->assertSame('https://example.com/', UrlNormalizer::origin('https://example.com/a'));
        $this->assertSame('not a url', UrlNormalizer::origin('not a url'));

        $this->expectException(Exception::class);
        UrlNormalizer::siteHost('localhost');
    }
}
