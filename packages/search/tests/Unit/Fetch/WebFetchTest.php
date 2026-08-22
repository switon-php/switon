<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Unit\Fetch;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\WebFetcherInterface;
use Switon\Search\WebFetch;
use Switon\Testing\TestCase;
use Switon\Tooling\Spiller;

#[CoversClass(WebFetch::class)]
class WebFetchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A browser is only reached for a login-walled host; every other URL goes over curl. The
        // stub fails loudly so a test that unexpectedly needs one says so.
        $this->container->set(
            \Switon\Core\WebFetcherInterface::class,
            new class () implements \Switon\Core\WebFetcherInterface {
                public function fetch(
                    string $url,
                    string $method = 'GET',
                    array $headers = [],
                    ?string $body = null,
                    array $options = [],
                ): array {
                    throw new LogicException('No browser is configured for this test: ' . $url);
                }
            },
        );
    }

    public function testExtractsReadableMarkdownFromHtml(): void
    {
        $result = $this->extract('https://example.com/docs/page', <<<'HTML'
<!doctype html>
<html>
<head><title>Example Doc</title><meta name="description" content="Short summary."></head>
<body><nav>skip</nav><article><h1>Title</h1><p>Body <a href="/next">link</a>.</p></article></body>
</html>
HTML);

        $this->assertSame('Example Doc', $result['title']);
        $this->assertArrayNotHasKey('description', $result);
        $this->assertSame(200, $result['status_code']);
        $this->assertStringContainsString('# Title', $result['content']);
        $this->assertStringContainsString('[link](/next)', $result['content']);
        $this->assertStringNotContainsString('skip', $result['content']);
    }

    public function testUsesOpenGraphFallbackAndCodeBlocks(): void
    {
        $result = $this->extract('https://example.com/post', <<<'HTML'
<html><head>
<meta property="og:title" content="OG Title">
<meta property="og:description" content="OG summary.">
</head><body><article><pre>echo hi;</pre></article></body></html>
HTML);

        $this->assertSame('OG Title', $result['title']);
        $this->assertArrayNotHasKey('description', $result);
        $this->assertStringContainsString("```\necho hi;\n```", $result['content']);
    }

    public function testFormatsJsonResponses(): void
    {
        $result = $this->extract('https://example.com/api', '{"ok":true,"n":1}', [
            'body' => '{"ok":true,"n":1}',
            'status_code' => 200,
            'content_type' => 'application/json',
        ]);

        $this->assertStringContainsString('"ok": true', $result['content']);
        $this->assertSame('application/json', $result['content_type']);
        $this->assertSame('json', $result['format']);
    }

    public function testPlainResponsePassesThrough(): void
    {
        $result = $this->extract('https://example.com/plain.txt', 'plain text body');

        $this->assertSame('plain text body', $result['content']);
        $this->assertArrayNotHasKey('truncated', $result);
        $this->assertArrayNotHasKey('download_truncated', $result);
    }

    public function testNormalizeEncodingConvertsLatin1(): void
    {
        $latin1 = mb_convert_encoding('café', 'ISO-8859-1', 'UTF-8');
        $converted = $this->extract('https://example.com/latin1', $latin1, [
            'body' => $latin1,
            'status_code' => 200,
            'content_type' => 'application/pdf; charset="ISO-8859-1"',
        ]);
        $unchanged = $this->extract('https://example.com/latin1', $latin1, [
            'body' => $latin1,
            'status_code' => 200,
            'content_type' => 'application/pdf; charset=not-a-charset',
        ]);

        $this->assertSame('café', $converted['content']);
        $this->assertSame($latin1, $unchanged['content']);
    }

    public function testPreservesLineBreaksInPreformattedText(): void
    {
        $result = $this->extract('https://example.com/post', <<<'HTML'
<html><body><article><pre># ubuntu版本
sudo lsb_release -a
# 初始化配置
sudo lxd init
lxc launch ubuntu:16.04 first</pre></article></body></html>
HTML);

        $this->assertStringContainsString("# ubuntu版本\nsudo lsb_release -a", $result['content']);
        $this->assertStringContainsString("# 初始化配置\nsudo lxd init", $result['content']);
    }

    public function testSplitsInlineHeadingMarkers(): void
    {
        $result = $this->extract('https://example.com/post', '<html><body><article><p>done true **# 本地镜像导出</p><p>lxc publish foo</p></article></body></html>');

        $this->assertStringContainsString("done true **\n\n# 本地镜像导出", $result['content']);
    }

    public function testNestedDivsDoNotAddExcessiveBlankLines(): void
    {
        $result = $this->extract('https://example.com/post', <<<'HTML'
<html><body><article><div><div><p>One</p></div><p>Two</p></div></article></body></html>
HTML);

        $this->assertSame("One\n\nTwo", $result['content']);
    }

    public function testPreCodeDoesNotDoubleFence(): void
    {
        $result = $this->extract('https://example.com/post', <<<'HTML'
<html><body><article><pre><code>meson.build:329:8: ERROR: missing dep</code></pre></article></body></html>
HTML);

        $this->assertStringContainsString("```\nmeson.build:329:8: ERROR: missing dep\n```", trim($result['content']));
        $this->assertStringNotContainsString("```\n\n```", $result['content']);
    }

    public function testKeepsRelativeAndAbsoluteLinksWithoutRewriting(): void
    {
        $result = $this->extract(
            'https://dl.devops.imedicalai.com/installer/',
            '<html><body><a href="../">parent</a><a href="adminer/">adminer</a></body></html>',
        );

        $this->assertStringContainsString('[parent](../)', $result['content']);
        $this->assertStringContainsString('[adminer](adminer/)', $result['content']);
    }

    public function testPreservesUtf8ChineseInLinkText(): void
    {
        $result = $this->extract('https://example.com/', <<<'HTML'
<html><body><a href="http://corp.example.com/">关于搜狗</a><a href="/terms">免责声明</a></body></html>
HTML);

        $this->assertStringContainsString('[关于搜狗](http://corp.example.com/)', $result['content']);
        $this->assertStringContainsString('[免责声明](/terms)', $result['content']);
        $this->assertStringNotContainsString("\u{FFFD}", $result['content']);
    }

    public function testRawReturnsHtmlWithoutMarkdownConversion(): void
    {
        $result = $this->extract('https://example.com/page', <<<'HTML'
<html><head><title>Raw Page</title><meta name="description" content="Raw summary."></head>
<body><article><h1>Title</h1><p>Body <a href="/next">link</a>.</p></article></body></html>
HTML, raw: true);

        $this->assertSame('raw', $result['format']);
        $this->assertSame('Raw Page', $result['title']);
        $this->assertArrayNotHasKey('description', $result);
        $this->assertStringContainsString('<h1>Title</h1>', $result['content']);
        $this->assertStringContainsString('<a href="/next">link</a>', $result['content']);
        $this->assertStringNotContainsString('# Title', $result['content']);
        $this->assertStringContainsString("\n\n\n", $this->extract('https://example.com/raw', "<pre>\n\n\nkeep</pre>", raw: true)['content']);
    }

    public function testStripsAsideAndCommonAdSidebarClasses(): void
    {
        $result = $this->extract('https://example.com/post', <<<'HTML'
<html><body><article><h1>Title</h1><p>Body copy needs enough length so the article root stays trusted after cleanup passes.</p>
<aside class="related"><a href="/1">Rel1</a><a href="/2">Rel2</a><a href="/3">Rel3</a><a href="/4">Rel4</a></aside>
<div class="ad">Buy now</div>
<div class="sidebar menu">Nav</div>
<div class="thread-meta">keep</div></article></body></html>
HTML);

        $this->assertStringContainsString('# Title', $result['content']);
        $this->assertStringContainsString('Body copy needs enough length', $result['content']);
        $this->assertStringContainsString('keep', $result['content']);
        $this->assertStringNotContainsString('Rel1', $result['content']);
        $this->assertStringNotContainsString('Buy now', $result['content']);
        $this->assertStringNotContainsString('Nav', $result['content']);
    }

    public function testNormalizesCarriageReturnsInFetchedContent(): void
    {
        $result = $this->extract(
            'https://example.com/crlf',
            "<html><body><article><p>line one\r\nstill same para</p><p>line two\r</p></article></body></html>",
        );

        $this->assertStringNotContainsString("\r", $result['content']);
        $this->assertStringContainsString('line one', $result['content']);
        $this->assertStringContainsString('still same para', $result['content']);
        $this->assertStringContainsString('line two', $result['content']);
    }

    public function testNormalizesCarriageReturnsInPlainText(): void
    {
        $result = $this->extract('https://example.com/plain.txt', "alpha\r\nbeta\rgamma");

        $this->assertSame("alpha\nbeta\ngamma", $result['content']);
    }

    public function testSkipsJavascriptHrefLinks(): void
    {
        $result = $this->extract('https://example.com/', <<<'HTML'
<html><body><article>
<a href="javascript:void(0)">noop</a>
<a href="/ok">ok</a>
</article></body></html>
HTML);

        $this->assertStringContainsString('noop', $result['content']);
        $this->assertStringNotContainsString('javascript:', $result['content']);
        $this->assertStringContainsString('[ok](/ok)', $result['content']);
    }

    public function testPicksDensestBlockWhenNoSemanticRoot(): void
    {
        $result = $this->extract('https://example.com/portal', <<<'HTML'
<html><body>
<div class="top">
  <a href="/a">A</a><a href="/b">B</a><a href="/c">C</a><a href="/d">D</a>
</div>
<div class="wrap">
  <div class="main-copy">
            <p>Alpha project ships a readable fetch pipeline that keeps body copy and drops portal chrome.</p>
            <p>Regression fixtures stay on disk so remote pages cannot flake the suite.</p>
  </div>
</div>
</body></html>
HTML);

        $this->assertStringContainsString('Alpha project ships a readable fetch pipeline', $result['content']);
        $this->assertStringContainsString('Regression fixtures stay on disk', $result['content']);
        $this->assertStringNotContainsString('[A](', $result['content']);
    }

    public function testPortalDivChromeFixturePrefersCopyOverLinkFarm(): void
    {
        $path = dirname(__DIR__, 2) . '/Fixtures/web-fetch/portal-div-chrome.html';
        $this->assertFileExists($path);
        $html = file_get_contents($path);
        $this->assertNotFalse($html);

        $result = $this->extract('https://example.com/portal-snapshot', $html);

        $this->assertStringContainsString('可抽取正文标题', $result['content']);
        $this->assertStringContainsString('足够长的正文内容', $result['content']);
        $this->assertStringContainsString('本地 fixture 不依赖远程站点', $result['content']);
        $this->assertStringNotContainsString('javascript:', $result['content']);
        $this->assertStringNotContainsString('首页', $result['content']);
        $this->assertStringNotContainsString('侧栏一', $result['content']);
        $this->assertStringNotContainsString('关于', $result['content']);
    }

    public function testFetchReturnsReadableText(): void
    {
        $this->container->set(
            \Switon\Core\WebFetcherInterface::class,
            new class () implements \Switon\Core\WebFetcherInterface {
                public function fetch(
                    string $url,
                    string $method = 'GET',
                    array $headers = [],
                    ?string $body = null,
                    array $options = [],
                ): array {
                    return [
                        'status' => 200,
                        'headers' => ['content-type' => 'text/html; charset=utf-8'],
                        'body' => '<html><head><title>T</title></head><body><article><p>Hello</p></article></body></html>',
                    ];
                }
            },
        );

        $result = $this->make(WebFetch::class)->fetch('https://example.com/a', static fn (): bool => false);

        $this->assertSame('https://example.com/a', $result['url']);
        $this->assertStringContainsString('Hello', $result['content']);
    }

    public function testAmbiguousAndNonHtmlTypes(): void
    {
        $plain = $this->extract('https://example.com/a', 'hello', [
            'body' => 'hello',
            'status_code' => 200,
            'content_type' => 'text/plain',
        ]);
        $this->assertStringContainsString('hello', $plain['content']);

        $pdf = $this->extract('https://example.com/a.pdf', 'bytes', [
            'body' => 'bytes',
            'status_code' => 200,
            'content_type' => 'application/pdf',
        ]);
        $this->assertSame('text', $pdf['format']);
        $this->assertSame('bytes', $pdf['content']);

        $htmlShaped = $this->extract('https://example.com/a', '{"a":1}', [
            'body' => '{"a":1}',
            'status_code' => 200,
            'content_type' => 'text/html',
        ]);
        $this->assertNotSame('json', $htmlShaped['format']);
    }

    public function testTruncateAndHardCut(): void
    {
        $cut = new ProbeWebFetch();
        $this->assertSame('hel', $cut->cut('hello', 3));
        $this->assertSame('', $cut->cut('你好', 1));

        $oversized = '<html><body><article><p>' . str_repeat('a', 1024 * 1024) . '</p></article></body></html>';
        $hard = $this->fetchBody('https://example.com/big', $oversized, 'text/html', 1);
        $this->assertStringContainsString(Spiller::ceilingExceeded(1024 * 1024), $hard['content']);

        $latin1 = mb_convert_encoding('café', 'ISO-8859-1', 'UTF-8');
        $meta = $this->extract('https://example.com/meta', '<meta charset="ISO-8859-1">' . $latin1, [
            'body' => '<meta charset="ISO-8859-1">' . $latin1,
            'status_code' => 200,
            'content_type' => '',
        ]);
        $this->assertStringContainsString('café', $meta['content']);
    }

    public function testRejectsNonHttpUrl(): void
    {
        $this->expectException(\Switon\Search\Exception::class);
        $this->make(WebFetch::class)->fetch('file:///etc/passwd', static fn (): bool => false);
    }

    public function testDownloadUsesWebFetcher(): void
    {
        $chrome = new class () implements \Switon\Core\WebFetcherInterface {
            public string $seen = '';

            /** @var array<string, mixed> */
            public array $options = [];

            public function fetch(
                string $url,
                string $method = 'GET',
                array $headers = [],
                ?string $body = null,
                array $options = [],
            ): array {
                $this->seen = $url;
                $this->options = $options;

                return [
                    'status' => 200,
                    'headers' => ['content-type' => 'text/html; charset=utf-8'],
                    'body' => '<html><head><title>Z</title></head><body><article><p>zhihu body</p></article></body></html>',
                ];
            }
        };

        $this->container->set(WebFetcherInterface::class, $chrome);
        $result = $this->make(WebFetch::class)->fetch('https://example.com/docs', static fn (): bool => false);

        $this->assertSame('https://example.com/docs', $chrome->seen);
        $this->assertSame(15.0, $chrome->options[WebFetcherInterface::TIMEOUT]);
        $this->assertSame(200, $result['status_code']);
        $this->assertStringContainsString('zhihu body', $result['content']);
        $this->assertSame('text/html; charset=utf-8', $result['content_type']);
    }

    /** @return array<string, mixed> */
    protected function extract(string $url, string $body, ?array $fetch = null, bool $raw = false): array
    {
        $fetch ??= [
            'body' => $body,
            'status_code' => 200,
            'content_type' => 'text/html',
        ];

        return $this->fetchBody($url, (string) $fetch['body'], (string) $fetch['content_type'], 32, (int) $fetch['status_code'], $raw);
    }

    /** @return array<string, mixed> */
    protected function fetchBody(
        string $url,
        string $body,
        string $contentType,
        int $ceiling = 32,
        int $status = 200,
        bool $raw = false,
    ): array {
        $chrome = new class ($body, $status, $contentType) implements WebFetcherInterface {
            public function __construct(
                private string $body,
                private int $status,
                private string $contentType,
            ) {
            }

            public function fetch(
                string $url,
                string $method = 'GET',
                array $headers = [],
                ?string $body = null,
                array $options = [],
            ): array {
                return [
                    'status' => $this->status,
                    'headers' => ['content-type' => $this->contentType],
                    'body' => $this->body,
                ];
            }
        };

        return $this->make(WebFetch::class, [
            'chrome' => $chrome,
            'ceiling' => $ceiling,
        ])->fetch($url, static fn (): bool => false, $raw);
    }
}

/** The public ceiling is whole mebibytes, so a 3-byte and 1-byte cut stay on the helper. */
class ProbeWebFetch extends WebFetch
{
    public function __construct()
    {
    }

    public function cut(string $body, int $max): string
    {
        return $this->truncateUtf8($body, $max);
    }
}
