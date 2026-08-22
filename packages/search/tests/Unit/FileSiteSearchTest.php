<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\WebFetcherInterface;
use Switon\Search\Exception;
use Switon\Search\Site;
use Switon\Search\SiteExtractorInterface;
use Switon\Search\Sites;
use Switon\Search\SiteSearch;
use Switon\Search\Tests\ToolTestCase;

#[CoversClass(SiteSearch::class)]
class FileSiteSearchTest extends ToolTestCase
{
    public function testSitesListsSupportedIds(): void
    {
        $sites = $this->sites();

        // The registry also picks up site files from installed packages, so assert the built-in
        // set is present rather than pinning the whole list.
        $ids = array_keys($sites->all());
        foreach ([
            'baike',
            'github-code',
            'github-issues',
            'github',
            'gitlab',
            'maven',
            'mdn',
            'npm',
            'packagist',
            'pkggo',
            'pypi',
            'stackoverflow',
            'wikipedia',
            'zhihu',
        ] as $id) {
            $this->assertContains($id, $ids);
        }
    }

    public function testUnknownSiteRaises(): void
    {
        $sites = $this->sites();

        $this->expectException(Exception::class);
        $sites->get('nope');
    }

    public function testEmptyQueryRaises(): void
    {
        $search = $this->make(SiteSearch::class);

        $this->expectException(Exception::class);
        $search->search($this->site('github'), '  ', 5, static fn (): bool => false);
    }

    public function testReadyCapturesWithBrowser(): void
    {
        $html = <<<'HTML'
<html><body>
<a class="package-snippet" href="/project/demo/">
  <span class="package-snippet__name">demo</span>
  <p class="package-snippet__description">A demo</p>
</a>
</body></html>
HTML;
        $chrome = $this->createMock(WebFetcherInterface::class);
        $chrome->expects($this->once())
            ->method('fetch')
            ->with(
                $this->stringContains('https://pypi.org/search/?q=php'),
                'GET',
                [],
                null,
                $this->callback(static fn (array $options): bool => ($options[WebFetcherInterface::READY] ?? '') === 'a.package-snippet'),
            )
            ->willReturn(['status' => 200, 'headers' => [], 'body' => $html]);
        $this->container->set(WebFetcherInterface::class, $chrome);
        $search = $this->make(SiteSearch::class);

        $found = $search->search($this->site('pypi'), 'php', 3, static fn (): bool => false);

        $this->assertSame('demo', $found[0]['title']);
        $this->assertSame('https://pypi.org/project/demo/', $found[0]['url']);
        $this->assertSame('A demo', $found[0]['snippet']);
    }

    /**
     * A field keeps the length the site declared. A long body is what the source returned, not a
     * defect to paper over, so nothing is shortened on the way out.
     */
    public function testLongFieldIsPassedThroughWhole(): void
    {
        $long = str_repeat('涨价', 300);

        $html = '<html><body><div class="hit"><span class="t">T</span>'
            . '<a href="https://example.com/a">open</a>'
            . '<span class="s">' . $long . '</span></div></body></html>';
        $chrome = $this->createStub(WebFetcherInterface::class);
        $chrome->method('fetch')->willReturn(['status' => 200, 'headers' => [], 'body' => $html]);
        $this->container->set(WebFetcherInterface::class, $chrome);

        $site = $this->site('pypi');
        $site->each = '.hit';
        $site->item = ['title' => ['.t'], 'url' => ['a@href'], 'snippet' => ['.s']];

        $found = $this->make(SiteSearch::class)->search($site, 'php', 3, static fn (): bool => false);

        $this->assertSame($long, $found[0]['snippet']);
    }

    /** Every field a site declares reaches the caller, not just the three common ones. */
    public function testDeclaredFieldsArePassedThrough(): void
    {
        $html = <<<'HTML'
<html><body>
<div class="hit">
  <span class="t">Mug</span>
  <a href="https://example.com/mug">open</a>
  <span class="p">12</span>
</div>
</body></html>
HTML;
        $chrome = $this->createStub(WebFetcherInterface::class);
        $chrome->method('fetch')->willReturn(['status' => 200, 'headers' => [], 'body' => $html]);
        $this->container->set(WebFetcherInterface::class, $chrome);

        $site = $this->site('pypi');
        $site->each = '.hit';
        $site->item = [
            'title' => ['.t'],
            'url' => ['a@href'],
            'snippet' => ['.p'],
            'price' => ['.p'],
        ];

        $found = $this->make(SiteSearch::class)->search($site, 'php', 3, static fn (): bool => false);

        $this->assertSame([
            'title' => 'Mug',
            'url' => 'https://example.com/mug',
            'snippet' => '12',
            'price' => '12',
        ], $found[0]);
    }

    public function testJsonBodySkipsThePreWrapperAndDropsABlankTitle(): void
    {
        $chrome = $this->createStub(WebFetcherInterface::class);
        $chrome->method('fetch')->willReturn([
            'status' => 200,
            'headers' => [],
            'body' => '{"data":[{"object":{"type":"article","excerpt":"no title"}},{"object":{"type":"article","id":"1","title":"Kept","excerpt":"e"}},{"object":{"type":"article","id":"2","title":"Extra","excerpt":"e"}}]}',
        ]);
        $this->container->set(WebFetcherInterface::class, $chrome);

        $found = $this->make(SiteSearch::class)->search($this->site('zhihu'), 'php', 1, static fn (): bool => false);

        $this->assertCount(1, $found);
        $this->assertSame('Kept', $found[0]['title']);
    }

    public function testCurlRejectsNonHttpAndAbortsBeforeTheRequest(): void
    {
        $site = $this->site('github');
        $site->request = 'file:///tmp/x';
        try {
            $this->make(SiteSearch::class)->search($site, 'php', 1, static fn (): bool => false);
            $this->fail('A file URL must not be fetched.');
        } catch (Exception) {
        }

        $this->expectException(Exception::class);
        $this->make(SiteSearch::class, ['proxy' => ''])->search(
            $this->site('github'),
            'php',
            1,
            static fn (): bool => true,
        );
    }

    public function testBrowserFetchesASameOriginApiAsAPath(): void
    {
        $site = $this->site('zhihu');
        $site->request = 'https://example.com:8443/api?q=1';
        $this->assertSame(['/api?q=1'], $this->fetchedUrls($site, '{"items":[]}'));

        $root = $this->site('zhihu');
        $root->request = 'https://example.com';
        $this->assertSame(['/'], $this->fetchedUrls($root, '{"items":[]}'));
    }

    public function testSameOriginPathKeepsAForeignHost(): void
    {
        $search = new ProbeSiteSearch();

        $this->assertSame('https://example.com/a', $search->originPath('https://example.com/a', ''));
        $this->assertSame('https://other.test/a', $search->originPath('https://other.test/a', 'https://example.com'));
        $this->assertSame(
            'https://example.com:8443/api',
            $search->originPath('https://example.com:8443/api', 'https://example.com/'),
        );
    }

    public function testBrowserNon200ReturnsStatus(): void
    {
        $chrome = $this->createStub(WebFetcherInterface::class);
        $chrome->method('fetch')->willReturn([
            'status' => 401,
            'headers' => [],
            'body' => '{"message":"Requires authentication"}',
        ]);
        $this->container->set(WebFetcherInterface::class, $chrome);
        $search = $this->make(SiteSearch::class);

        $page = $search->search($this->site('zhihu'), 'php', 3, static fn (): bool => false);

        $this->assertSame(['results' => [], 'status' => 401], $page);
    }

    public function testCurlNon200ReturnsStatus(): void
    {
        $proc = proc_open(
            [PHP_BINARY, '-r', <<<'PHP'
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $err);
fwrite(STDOUT, stream_socket_get_name($server, false) . "\n");
fflush(STDOUT);
$conn = stream_socket_accept($server, 5);
$req = '';
while (!str_contains($req, "\r\n\r\n")) {
    $chunk = fread($conn, 1024);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $req .= $chunk;
}
fwrite($conn, "HTTP/1.1 401 Unauthorized\r\nContent-Length: 13\r\nConnection: close\r\n\r\n{\"message\":1}");
fclose($conn);
PHP],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        $addr = trim((string) fgets($pipes[1]));
        $site = $this->site('github');
        $site->request = 'http://' . $addr . '/';

        $page = $this->make(SiteSearch::class)->search($site, 'php', 1, static fn (): bool => false);

        $this->assertSame(['results' => [], 'status' => 401], $page);
        proc_close($proc);
    }

    public function testCurlFailureStillRaises(): void
    {
        $site = $this->site('github');
        $site->request = 'http://127.0.0.1:1/';

        $this->expectException(Exception::class);
        $this->make(SiteSearch::class)->search($site, 'php', 1, static fn (): bool => false);
    }

    public function testJsonTextKeepsUtf8InsidePre(): void
    {
        $site = $this->site('zhihu');

        $this->assertSame('{"a":1}', $this->extractedDocument($site, '{"a":1}'));
        $this->assertSame('<html></html>', $this->extractedDocument($site, '<html></html>'));
        $this->assertSame(
            '{"t":"知乎"}',
            $this->extractedDocument($site, '<html><body><pre>{"t":"知乎"}</pre></body></html>'),
        );
    }

    /** @return list<string> */
    protected function fetchedUrls(Site $site, string $body, ?SiteExtractorInterface $extractor = null): array
    {
        $urls = new ArrayObject();
        $chrome = $this->createStub(WebFetcherInterface::class);
        $chrome->method('fetch')->willReturnCallback(static function (string $url) use ($urls, $body): array {
            $urls[] = $url;

            return ['status' => 200, 'headers' => [], 'body' => $body];
        });
        $parameters = ['chrome' => $chrome];
        if ($extractor !== null) {
            $parameters['extractor'] = $extractor;
        }
        $this->make(SiteSearch::class, $parameters)->search($site, 'php', 1, static fn (): bool => false);

        return $urls->getArrayCopy();
    }

    protected function extractedDocument(Site $site, string $body): string
    {
        $seen = new ArrayObject();
        $extractor = new class ($seen) implements SiteExtractorInterface {
            public function __construct(private ArrayObject $seen)
            {
            }

            public function extract(Site $site, string $document, string $documentUrl): array
            {
                $this->seen[] = $document;

                return [];
            }
        };
        $this->fetchedUrls($site, $body, $extractor);

        return (string) ($seen[0] ?? '');
    }
}

/** The public search always derives the origin from the request URL. These inputs are the other branches. */
class ProbeSiteSearch extends SiteSearch
{
    public function __construct()
    {
    }

    public function originPath(string $url, ?string $origin): string
    {
        return $this->sameOriginPath($url, $origin);
    }
}
