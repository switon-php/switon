<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Unit\Web;

use ArrayObject;
use PHPUnit\Framework\Attributes\CoversClass;
use RuntimeException;
use stdClass;
use Switon\Core\WebFetcherInterface;
use Switon\Search\Exception;
use Switon\Search\Web\Engine\BaiduEngine;
use Switon\Search\Web\Engine\BingEngine;
use Switon\Search\Web\Engine\GoogleEngine;
use Switon\Search\Web\EngineInterface;
use Switon\Search\WebSearch;
use Switon\Testing\TestCase;

use function array_map;

#[CoversClass(WebSearch::class)]
class WebSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Engine URLs are built and parsed here; the transport itself is not under test.
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
                    return ['status' => 200, 'headers' => [], 'body' => ''];
                }
            },
        );
    }

    public function testEmptyQueryRaises(): void
    {
        $search = $this->make(WebSearch::class);

        $this->expectException(Exception::class);
        $search->search('  ', 5, static fn (): bool => false);
    }

    public function testInvalidSiteRaises(): void
    {
        $search = $this->make(WebSearch::class);

        $this->expectException(Exception::class);
        $search->search('x', 5, static fn (): bool => false, null, 'not a host');
    }

    public function testMergeRoundRobinDedupesNormalizedUrls(): void
    {
        $search = new ProbeWebSearch([
            'baidu' => new HitEngine([
                ['title' => 'A', 'url' => 'https://www.example.com/a/', 'snippet' => 'a1'],
                ['title' => 'B', 'url' => 'https://example.com/b', 'snippet' => 'b1'],
            ]),
            'google' => new HitEngine([
                ['title' => 'A again', 'url' => 'https://example.com/a?utm_source=g', 'snippet' => 'a2'],
                ['title' => 'C', 'url' => 'https://example.com/c', 'snippet' => 'c1'],
            ]),
            'bing' => new HitEngine([
                ['title' => 'D', 'url' => 'https://example.com/d', 'snippet' => 'd1'],
            ]),
            'extra' => new HitEngine([
                ['title' => 'E', 'url' => 'https://www.example.com/e', 'snippet' => 'e1'],
            ]),
        ], new PageFetcher());

        $merged = $search->search('php', 10, static fn (): bool => false);

        $this->assertSame([
            ['url' => 'https://www.example.com/a/', 'engine' => 'baidu'],
            ['url' => 'https://example.com/d', 'engine' => 'bing'],
            ['url' => 'https://www.example.com/e', 'engine' => 'extra'],
            ['url' => 'https://example.com/b', 'engine' => 'baidu'],
            ['url' => 'https://example.com/c', 'engine' => 'google'],
        ], array_map(
            static fn (array $row): array => ['url' => $row['url'], 'engine' => $row['engine']],
            $merged,
        ));
    }

    public function testBingBuildUrlIncludesLang(): void
    {
        $url = $this->make(BingEngine::class)->buildUrl('php swoole', 10, 'zh-CN');

        $this->assertStringContainsString('q=php%20swoole', $url);
        $this->assertStringContainsString('setlang=zh-CN', $url);
        $this->assertStringContainsString('cc=CN', $url);
        $this->assertStringContainsString('mkt=zh-CN', $url);
    }

    public function testBingBuildUrlDefaultsToCnMarket(): void
    {
        $url = $this->make(BingEngine::class)->buildUrl('A股 行情', 10, null);

        $this->assertStringContainsString('setlang=zh-CN', $url);
        $this->assertStringContainsString('cc=CN', $url);
        $this->assertStringContainsString('mkt=zh-CN', $url);
    }

    public function testSiteQuerySupportFlags(): void
    {
        $this->assertTrue($this->make(BaiduEngine::class)->supportsSiteQuery());
        $this->assertTrue($this->make(GoogleEngine::class)->supportsSiteQuery());
        $this->assertTrue($this->make(BingEngine::class)->supportsSiteQuery());
    }

    public function testSiteQueryAppendedOnlyWhenEngineSupportsIt(): void
    {
        $recorded = new ArrayObject();
        $search = new ProbeWebSearch([
            'baidu' => new RecordingEngine(true, $recorded, 'baidu'),
            'nosite' => new RecordingEngine(false, $recorded, 'nosite'),
            'bing' => new RecordingEngine(true, $recorded, 'bing'),
        ], new PageFetcher());

        $search->search('malloc', 5, static fn (): bool => false, null, 'csdn.net');
        $this->assertSame('malloc site:csdn.net', $recorded['baidu']);
        $this->assertSame('malloc', $recorded['nosite']);
        $this->assertSame('malloc site:csdn.net', $recorded['bing']);

        $search->search('malloc site:csdn.net.evil', 5, static fn (): bool => false, null, 'csdn.net');
        $this->assertSame('malloc site:csdn.net.evil site:csdn.net', $recorded['baidu']);
    }

    public function testHttpErrorAndChallengeDoNotBecomeHits(): void
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
                        'status' => 500,
                        'headers' => [],
                        'body' => '<html><body><div class="c-container"><h3><a href="https://example.com/a">Alpha</a></h3></div><p>recaptcha</p></body></html>',
                    ];
                }
            },
        );

        $this->assertSame([], $this->make(WebSearch::class)->search('php', 5, static fn (): bool => false));
    }

    public function testAbortDuringFetchRaises(): void
    {
        $flag = new stdClass();
        $flag->abort = false;
        $this->container->set(
            \Switon\Core\WebFetcherInterface::class,
            new class ($flag) implements \Switon\Core\WebFetcherInterface {
                public function __construct(public stdClass $flag)
                {
                }

                public function fetch(
                    string $url,
                    string $method = 'GET',
                    array $headers = [],
                    ?string $body = null,
                    array $options = [],
                ): array {
                    $this->flag->abort = true;

                    return ['status' => 200, 'headers' => [], 'body' => '<html></html>'];
                }
            },
        );

        $this->expectException(Exception::class);
        $this->make(WebSearch::class)->search('php', 5, static fn (): bool => $flag->abort);
    }

    public function testChallengePageIsNotParsed(): void
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
                        'headers' => [],
                        'body' => '<html><title>百度安全验证</title><div class="c-container"><h3><a href="https://example.com/a">Alpha</a></h3></div></html>',
                    ];
                }
            },
        );

        $this->assertSame([], $this->make(WebSearch::class)->search('php', 5, static fn (): bool => false));
    }

    public function testBlankLangIsIgnoredAndAParsedPageIsMerged(): void
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
                    $html = str_contains($url, 'baidu.com')
                        ? '<html><body><div class="c-container"><h3><a href="https://example.com/a">Alpha</a></h3></div>'
                            . '<div class="c-container"><h3><a href="https://example.com/b">Beta</a></h3></div></body></html>'
                        : '';

                    return ['status' => 200, 'headers' => [], 'body' => $html];
                }
            },
        );

        $rows = $this->make(WebSearch::class)->search('php', 1, static fn (): bool => false, '  ');

        $this->assertSame('https://example.com/a', $rows[0]['url']);
        $this->assertSame('baidu', $rows[0]['engine']);
    }

    public function testAbortBeforeFetchRaises(): void
    {
        $this->expectException(Exception::class);
        $this->make(WebSearch::class)->search('php', 5, static fn (): bool => true);
    }

    public function testAThrowingEngineIsSkipped(): void
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
                    throw new RuntimeException('down');
                }
            },
        );

        $this->assertSame([], $this->make(WebSearch::class)->search('php site:github.com', 5, static fn (): bool => false, null, 'github.com'));
    }
}

/** @internal */
class RecordingEngine implements \Switon\Search\Web\EngineInterface
{
    public function __construct(
        protected bool $supportsSite,
        protected ArrayObject $recorded,
        protected string $id,
    ) {
    }

    public function supportsSiteQuery(): bool
    {
        return $this->supportsSite;
    }

    public function pageSize(): int
    {
        return 10;
    }

    public function buildUrl(string $query, int $maxResults, ?string $lang): string
    {
        $this->recorded[$this->id] = $query;

        return 'https://example.test/' . $this->id;
    }

    public function parse(string $body, int $maxResults): array
    {
        return [];
    }
}

/** Object engines cannot go through `#[Autowired(instances: true)]`, which accepts service ids only. */
class ProbeWebSearch extends WebSearch
{
    /** @param array<string, EngineInterface> $engines */
    public function __construct(array $engines, WebFetcherInterface $chrome)
    {
        $this->engines = $engines;
        $this->chrome = $chrome;
    }
}

class PageFetcher implements WebFetcherInterface
{
    public function fetch(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null,
        array $options = [],
    ): array {
        return ['status' => 200, 'headers' => [], 'body' => '<html>ok</html>'];
    }
}

class HitEngine implements EngineInterface
{
    /** @param list<array{title: string, url: string, snippet: string}> $hits */
    public function __construct(protected array $hits)
    {
    }

    public function supportsSiteQuery(): bool
    {
        return false;
    }

    public function pageSize(): int
    {
        return 10;
    }

    public function buildUrl(string $query, int $maxResults, ?string $lang): string
    {
        return 'https://example.test/hits';
    }

    public function parse(string $body, int $maxResults): array
    {
        return $this->hits;
    }
}
