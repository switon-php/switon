<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Search\Exception;
use Switon\Search\Site;
use Switon\Search\SiteExtractor;
use Switon\Search\Sites;
use Switon\Testing\TestCase;
use Switon\Yaml\YamlReaderInterface;

#[CoversClass(Site::class)]
#[CoversClass(SiteExtractor::class)]
class SiteTest extends TestCase
{
    public function testSitesLoadsTheConvertedSites(): void
    {
        $sites = $this->sites();

        $this->assertSame([
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
        ], array_slice(array_keys($sites), 0, 14), 'the built-in sites come first, in file order');

        foreach ($sites as $id => $site) {
            $this->assertSame($id, $site->id);
            $this->assertStringContainsString('{keyword}', $site->request);
        }

        $this->assertSame('a.package-snippet', $sites['pypi']->ready);
        $this->assertSame('', $sites['github']->ready);
        $this->assertTrue($sites['github']->clean);
        $this->assertSame(
            'https://api.github.com/search/repositories?q={keyword}&per_page=10',
            $sites['github']->request,
        );
    }

    public function testEmptyResponseDoesNotClean(): void
    {
        $site = $this->compile(<<<'YML'
id: sample
description: A page with no rules yet.
request: https://example.com/search?q={keyword}
response:
YML, 'sample');

        $this->assertFalse($site->clean);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('does not clean');

        $this->make(SiteExtractor::class)->extract($site, '<html></html>', 'https://example.com/search?q=php');
    }

    public function testHtmlKeepsARowWhenOnlyTheTitleHits(): void
    {
        $site = $this->compile(<<<'YML'
id: rows
description: Rows.
request: https://example.com/search?q={keyword}
response:
  kind: html
  each: .c-container
  item:
    title: h3 a
    url: h3 a@href
    snippet: .c-abstract, [class*="content-right_"]
YML);
        $this->assertExtract($site, <<<'HTML'
<html><body>
<div class="c-container">
  <h3><a href="https://example.com/a">Hello &amp; world</a></h3>
  <div class="c-abstract">Snippet A</div>
</div>
<div class="c-container">
  <h3><a href="/link?url=2">Beta</a></h3>
  <span class="content-right_123">Snippet B</span>
</div>
<div class="c-container">
  <h3><a href="javascript:void(0)">Skip</a></h3>
</div>
<div class="c-container">
  <h3><a href="#frag">Hash</a></h3>
</div>
</body></html>
HTML, 'https://example.com/search?q=php', [
            ['title' => 'Hello & world', 'url' => 'https://example.com/a', 'snippet' => 'Snippet A'],
            ['title' => 'Beta', 'url' => 'https://example.com/link?url=2', 'snippet' => 'Snippet B'],
            // A `javascript:` or fragment URL resolves to nothing, so the key is absent.
            ['title' => 'Skip'],
            ['title' => 'Hash'],
        ]);
    }

    public function testHtmlExtractsNestedBlocks(): void
    {
        $site = $this->compile(<<<'YML'
id: blocks
description: Blocks.
request: https://example.com/search?q={keyword}
response:
  kind: html
  each: li.b_algo
  item:
    title: h2 a
    url: h2 a@href
    snippet: .b_caption p
YML);
        $this->assertExtract($site, <<<'HTML'
<html><body><ol>
<li class="b_algo">
  <h2><a href="https://example.com/docs">Example Docs</a></h2>
  <div class="b_caption"><p>Short summary for the result.</p></div>
</li>
<li class="b_algo">
  <h2><a href="https://github.com/switon-php/agency">Switon Agent</a></h2>
  <div class="b_caption"><p>GitHub repository.</p></div>
</li>
</ol></body></html>
HTML, 'https://example.com/search?q=php', [
            ['title' => 'Example Docs', 'url' => 'https://example.com/docs', 'snippet' => 'Short summary for the result.'],
            ['title' => 'Switon Agent', 'url' => 'https://github.com/switon-php/agency', 'snippet' => 'GitHub repository.'],
        ]);
    }

    public function testGithubFallsBackFromFullName(): void
    {
        $this->assertHits('github', <<<'JSON'
{"items": [
  {"full_name": "switon/search", "name": "search", "html_url": "https://github.com/switon/search", "description": null},
  {"name": "only-name", "html_url": "https://github.com/acme/only-name", "description": "plain"}
]}
JSON, 'https://api.github.com/search/repositories?q=switon', [
            ['title' => 'switon/search', 'url' => 'https://github.com/switon/search'],
            ['title' => 'only-name', 'url' => 'https://github.com/acme/only-name', 'snippet' => 'plain'],
        ]);
    }

    public function testGithubCodeUsesTheRepoAndPath(): void
    {
        $this->assertHits('github-code', <<<'JSON'
{"items": [{
  "path": "src/Site/GithubSite.php",
  "html_url": "https://github.com/switon-php/site-search/blob/main/src/Site/GithubSite.php",
  "repository": {"full_name": "switon-php/site-search"},
  "text_matches": [{"fragment": "class GithubSite implements SiteInterface"}]
}]}
JSON, 'https://api.github.com/search/code?q=GithubSite', [
            [
                'title' => 'switon-php/site-search:src/Site/GithubSite.php',
                'url' => 'https://github.com/switon-php/site-search/blob/main/src/Site/GithubSite.php',
                'snippet' => 'class GithubSite implements SiteInterface',
            ],
        ]);
    }

    public function testGithubIssuesReadsTheBody(): void
    {
        $this->assertHits('github-issues', <<<'JSON'
{"items": [{
  "title": "Add pagination",
  "html_url": "https://github.com/switon-php/site-search/issues/1",
  "state": "open",
  "body": "Need page support"
}]}
JSON, 'https://api.github.com/search/issues?q=pagination', [
            [
                'title' => 'Add pagination',
                'url' => 'https://github.com/switon-php/site-search/issues/1',
                'snippet' => 'Need page support',
            ],
        ]);
    }

    public function testGitlabReadsARootArray(): void
    {
        $this->assertHits('gitlab', <<<'JSON'
[{"path_with_namespace": "acme/app", "name": "app", "web_url": "https://gitlab.com/acme/app", "description": "proj"}]
JSON, 'https://gitlab.com/api/v4/projects?search=app', [
            ['title' => 'acme/app', 'url' => 'https://gitlab.com/acme/app', 'snippet' => 'proj'],
        ]);
    }

    public function testNpmFillsThePackageUrlWhenTheLinkIsEmpty(): void
    {
        $this->assertHits('npm', <<<'JSON'
{"objects": [{"package": {"name": "left-pad", "description": "pad", "links": {"npm": ""}}}]}
JSON, 'https://registry.npmjs.org/-/v1/search?text=left-pad', [
            ['title' => 'left-pad', 'url' => 'https://www.npmjs.com/package/left-pad', 'snippet' => 'pad'],
        ]);
    }

    public function testPackagistExtractsResults(): void
    {
        $this->assertHits('packagist', <<<'JSON'
{"results": [{"name": "switon/search", "url": "https://packagist.org/packages/switon/search", "description": "lookup"}]}
JSON, 'https://packagist.org/search.json?q=switon', [
            ['title' => 'switon/search', 'url' => 'https://packagist.org/packages/switon/search', 'snippet' => 'lookup'],
        ]);
    }

    public function testMavenBuildsTheCoordinate(): void
    {
        $this->assertHits('maven', <<<'JSON'
{"response": {"docs": [{"g": "com.google", "a": "guava", "latestVersion": "33"}]}}
JSON, 'https://central.sonatype.com/solrsearch/select?q=guava', [
            ['title' => 'com.google:guava', 'url' => 'https://central.sonatype.com/artifact/com.google/guava', 'snippet' => 'latest: 33'],
        ]);
    }

    public function testPypiResolvesTheSnippetHrefAndFallsBack(): void
    {
        $this->assertHits('pypi', <<<'HTML'
<html><body>
<a class="package-snippet" href="/project/demo/">
  <span class="package-snippet__name">demo</span>
  <p class="package-snippet__description">A demo</p>
</a>
<a class="package-snippet">
  <span class="package-snippet__name">other</span>
  <p class="package-snippet__description">No href</p>
</a>
</body></html>
HTML, 'https://pypi.org/search/?q=demo', [
            ['title' => 'demo', 'url' => 'https://pypi.org/project/demo/', 'snippet' => 'A demo'],
            ['title' => 'other', 'url' => 'https://pypi.org/project/other/', 'snippet' => 'No href'],
        ]);
    }

    public function testWikipediaSkipsAZeroPageId(): void
    {
        $this->assertHits('wikipedia', <<<'JSON'
{"query": {"search": [
  {"title": "Ada Lovelace", "pageid": 0, "snippet": "<span>math</span>"},
  {"title": "PHP", "pageid": 42, "snippet": "lang"}
]}}
JSON, 'https://en.wikipedia.org/w/api.php?srsearch=ada', [
            ['title' => 'Ada Lovelace', 'url' => 'https://en.wikipedia.org/wiki/Ada%20Lovelace', 'snippet' => 'math'],
            ['title' => 'PHP', 'url' => 'https://en.wikipedia.org/?curid=42', 'snippet' => 'lang'],
        ]);
    }

    public function testBaikeSkipsAZeroLemmaId(): void
    {
        $this->assertHits('baike', <<<'JSON'
{"list": [
  {"lemmaTitle": "PHP", "lemmaId": 0, "lemmaDesc": "语言"},
  {"lemmaTitle": "知乎", "lemmaId": 8, "lemmaDesc": "网站"}
]}
JSON, 'https://baike.baidu.com/api/searchui/suggest?wd=php', [
            ['title' => 'PHP', 'url' => 'https://baike.baidu.com/item/PHP', 'snippet' => '语言'],
            ['title' => '知乎', 'url' => 'https://baike.baidu.com/item/%E7%9F%A5%E4%B9%8E/8', 'snippet' => '网站'],
        ]);
    }

    public function testStackOverflowDecodesTheTitle(): void
    {
        $this->assertHits('stackoverflow', <<<'JSON'
{"items": [{"title": "Foo &amp; bar", "link": "https://stackoverflow.com/questions/1"}]}
JSON, 'https://api.stackexchange.com/2.3/search/advanced?q=foo', [
            ['title' => 'Foo & bar', 'url' => 'https://stackoverflow.com/questions/1'],
        ]);
    }

    /** A `by` table picks the template whose key matches the branch field. */
    public function testBranchTablePicksTheMatchingTemplate(): void
    {
        $site = $this->compile(<<<'YML'
id: branch
description: Branch by type.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: data
  item:
    title: name
    url:
      by: type
      post: https://example.com/post/{id}
      user: https://example.com/u/{handle}
      _: https://example.com/other/{id}
YML);

        $this->assertExtract($site, <<<'JSON'
{"data": [
  {"type": "post", "id": "7", "name": "Post"},
  {"type": "user", "id": "8", "handle": "ada", "name": "Ada"},
  {"type": "ad", "id": "9", "name": "Ad"}
]}
JSON, 'https://example.com/search?q=x', [
            ['title' => 'Post', 'url' => 'https://example.com/post/7'],
            ['title' => 'Ada', 'url' => 'https://example.com/u/ada'],
            ['title' => 'Ad', 'url' => 'https://example.com/other/9'],
        ]);
    }

    /** A branch value that matches no key yields no url, so the key is absent. */
    public function testBranchTableWithoutFallbackYieldsNoUrl(): void
    {
        $site = $this->compile(<<<'YML'
id: branch
description: Branch without a default.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: data
  item:
    title: name
    url:
      by: type
      post: https://example.com/post/{id}
YML);

        $this->assertExtract($site, <<<'JSON'
{"data": [{"type": "ad", "id": "9", "name": "Ad"}]}
JSON, 'https://example.com/search?q=x', [
            ['title' => 'Ad'],
        ]);
    }

    /** The template a branch selects still misses when its own placeholder is absent. */
    public function testBranchTemplateMissesOnAnAbsentPlaceholder(): void
    {
        $site = $this->compile(<<<'YML'
id: branch
description: Branch template with a missing field.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: data
  item:
    title: name
    url:
      by: type
      user: https://example.com/u/{handle}
      _: https://example.com/post/{id}
YML);

        $this->assertExtract($site, <<<'JSON'
{"data": [{"type": "user", "id": "8", "name": "Ada"}]}
JSON, 'https://example.com/search?q=x', [
            ['title' => 'Ada'],
        ]);
    }

    /**
     * A template that is one whole placeholder is a pass-through: the value is a complete URL,
     * so it must not be encoded and appended to the document's origin as if it were a fragment.
     */
    public function testWholePlaceholderUrlIsPassedThrough(): void
    {
        $site = $this->compile(<<<'YML'
id: whole
description: URL taken verbatim.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: data
  item:
    title: name
    url: "{target}"
YML);

        $this->assertExtract($site, <<<'JSON'
{"data": [{"name": "Ad", "target": "https://cdn.example.com/sku/1"}]}
JSON, 'https://example.com/search?q=x', [
            ['title' => 'Ad', 'url' => 'https://cdn.example.com/sku/1'],
        ]);
    }

    /** The same pass-through applies to a path placeholder, which stays a single path segment. */
    public function testWholePlaceholderKeepsAPathUnencoded(): void
    {
        $site = $this->compile(<<<'YML'
id: whole
description: Path taken verbatim.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: data
  item:
    title: name
    url: "{path}"
YML);

        $this->assertExtract($site, <<<'JSON'
{"data": [{"name": "Docs", "path": "/docs/php"}]}
JSON, 'https://example.com/search?q=x', [
            ['title' => 'Docs', 'url' => 'https://example.com/docs/php'],
        ]);
    }

    /**
     * An encoded tag is markup once decoded, so rules must strip it. Decoding after stripping would
     * leave `&lt;em&gt;` visible as text.
     */
    public function testStripATagThatWasEntities(): void
    {
        $this->assertHits('stackoverflow', <<<'JSON'
{"items": [{"title": "&lt;em&gt;Foo&lt;/em&gt;  bar", "link": "https://stackoverflow.com/questions/2"}]}
JSON, 'https://api.stackexchange.com/2.3/search/advanced?q=foo', [
            ['title' => 'Foo bar', 'url' => 'https://stackoverflow.com/questions/2'],
        ]);
    }

    public function testMdnResolvesARootRelativeUrl(): void
    {
        $this->assertHits('mdn', <<<'JSON'
{"documents": [{"title": "Array", "mdn_url": "/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array", "summary": "list"}]}
JSON, 'https://developer.mozilla.org/api/v1/search?q=array', [
            [
                'title' => 'Array',
                'url' => 'https://developer.mozilla.org/en-US/docs/Web/JavaScript/Reference/Global_Objects/Array',
                'snippet' => 'list',
            ],
        ]);
    }

    public function testPkgGoResolvesTheHeaderPath(): void
    {
        $this->assertHits('pkggo', <<<'HTML'
<html><body>
<div class="SearchSnippet">
  <a class="SearchSnippet-header-path" href="/github.com/gin-gonic/gin">github.com/gin-gonic/gin</a>
  <p class="SearchSnippet-synopsis">web</p>
</div>
</body></html>
HTML, 'https://pkg.go.dev/search?q=gin', [
            ['title' => 'github.com/gin-gonic/gin', 'url' => 'https://pkg.go.dev/github.com/gin-gonic/gin', 'snippet' => 'web'],
        ]);
    }

    public function testItemCanCarryAPrice(): void
    {
        $site = $this->compile(<<<'YML'
id: shop
description: Shop products.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: products
  item:
    title: name
    url: permalink
    price: offers.price
YML, 'shop');

        $hits = $this->make(SiteExtractor::class)->extract($site, <<<'JSON'
{"products": [{"name": "Mug", "permalink": "https://example.com/mug", "offers": {"price": "12"}}]}
JSON, 'https://example.com/search?q=mug');

        $this->assertSame([
            ['title' => 'Mug', 'url' => 'https://example.com/mug', 'price' => '12'],
        ], $hits);
    }

    /**
     * A field that resolves to nothing is absent from the record, so a reply carries only what
     * the source actually had — no empty strings standing in for missing data.
     */
    public function testAbsentFieldIsOmitted(): void
    {
        $site = $this->compile(<<<'YML'
id: sparse
description: Sparse fields.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: rows
  item:
    title: name
    url: href
    board: board
    summary: summary
YML);

        $this->assertExtract($site, <<<'JSON'
{"rows": [
  {"name": "A", "href": "https://example.com/a", "board": "Board", "summary": "Text"},
  {"name": "B", "href": "https://example.com/b", "summary": "Only summary"}
]}
JSON, 'https://example.com/search?q=x', [
            ['title' => 'A', 'url' => 'https://example.com/a', 'board' => 'Board', 'summary' => 'Text'],
            ['title' => 'B', 'url' => 'https://example.com/b', 'summary' => 'Only summary'],
        ]);
    }

    /**
     * With no `item`, a row is passed through exactly as the source carried it: every field, and
     * nested values stay nested. No title/url contract applies.
     */
    public function testNoItemPassesTheRowThrough(): void
    {
        $site = $this->compile(<<<'YML'
id: raw
description: Raw rows.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: rows
YML);

        $this->assertExtract($site, <<<'JSON'
{"rows": [
  {"name": "Mug", "href": "https://example.com/mug", "offers": {"price": "12"}, "count": 9},
  {"type": "ad", "labels": []}
]}
JSON, 'https://example.com/search?q=x', [
            ['name' => 'Mug', 'href' => 'https://example.com/mug', 'offers' => ['price' => '12'], 'count' => 9],
            ['type' => 'ad', 'labels' => []],
        ]);
    }

    /** Any field name is allowed; one that is absent is simply left out. */
    public function testExtraFieldsAreIndependent(): void
    {
        $site = $this->compile(<<<'YML'
id: extra
description: Extra fields.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: rows
  item:
    title: name
    url: href
    price: offers.price
    stars: count
YML);

        $this->assertExtract($site, <<<'JSON'
{"rows": [
  {"name": "Mug", "href": "https://example.com/mug", "offers": {"price": "12"}, "count": 9},
  {"name": "Cup", "href": "https://example.com/cup"}
]}
JSON, 'https://example.com/search?q=x', [
            ['title' => 'Mug', 'url' => 'https://example.com/mug', 'price' => '12', 'stars' => '9'],
            ['title' => 'Cup', 'url' => 'https://example.com/cup'],
        ]);
    }


    /** `jsonp` unwraps a callback body; a bare JSON body still decodes. */
    public function testJsonpUnwrapsTheCallback(): void
    {
        $site = $this->compile(<<<'YML'
id: jsonp
description: JSONP endpoint.
request: https://example.com/search?q={keyword}
jsonp: cb
response:
  kind: json
  each: rows
  item:
    title: name
    url: href
YML);

        $wrapped = 'cb(' . json_encode(['rows' => [['name' => 'A', 'href' => 'https://example.com/a']]]) . ');';

        $this->assertExtract($site, $wrapped, 'https://example.com/search?q=x', [
            ['title' => 'A', 'url' => 'https://example.com/a'],
        ]);
        $this->assertExtract($site, json_encode(['rows' => [['name' => 'B', 'href' => 'https://example.com/b']]]), 'https://example.com/search?q=x', [
            ['title' => 'B', 'url' => 'https://example.com/b'],
        ]);
    }

    /** `https: true` upgrades a plain-HTTP link. */
    public function testUrlUpgradesToHttps(): void
    {
        $site = $this->compile(<<<'YML'
id: secure
description: Upgrade to https.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: rows
  item:
    title: name
    url:
      value: href
      https: true
YML);

        $this->assertExtract($site, <<<'JSON'
{"rows": [
  {"name": "A", "href": "http://example.com/a"},
  {"name": "B", "href": "HTTP://example.com/b"}
]}
JSON, 'https://example.com/search?q=x', [
            ['title' => 'A', 'url' => 'https://example.com/a'],
            ['title' => 'B', 'url' => 'https://example.com/b'],
        ]);
    }

    /** `value` may be a list of alternatives, tried in order. */
    public function testUrlValueListTriesAlternatives(): void
    {
        $site = $this->compile(<<<'YML'
id: values
description: Listed values.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: rows
  item:
    title: name
    url:
      value:
        - missing
        - href
YML);

        $this->assertExtract($site, <<<'JSON'
{"rows": [{"name": "A", "href": "/a"}]}
JSON, 'https://example.com/search?q=php', [
            ['title' => 'A', 'url' => 'https://example.com/a'],
        ]);
    }

    /** A query-only relative link replaces the query and keeps the path. */
    public function testQueryOnlyRelativeKeepsTheDocumentPath(): void
    {
        $site = $this->compile(<<<'YML'
id: query
description: Query relative.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: rows
  item:
    title: name
    url: href
YML);

        $this->assertExtract($site, <<<'JSON'
{"rows": [
  {"name": "A", "href": "?page=2"},
  {"name": "B", "href": "file:///tmp/x"}
]}
JSON, 'https://example.com/search?q=php', [
            ['title' => 'A', 'url' => 'https://example.com/search?page=2'],
            ['title' => 'B'],
        ]);
    }

    /** `base` resolves a site-relative link against the site, not the endpoint that returned it. */
    public function testUrlResolvesAgainstAnExplicitBase(): void
    {
        $site = $this->compile(<<<'YML'
id: based
description: Explicit base.
request: https://api.example.com/search?q={keyword}
response:
  kind: json
  each: rows
  item:
    title: name
    url:
      value: href
      base: https://www.example.com/
YML);

        $this->assertExtract($site, <<<'JSON'
{"rows": [{"name": "A", "href": "/skills/a"}]}
JSON, 'https://api.example.com/search?q=x', [
            ['title' => 'A', 'url' => 'https://www.example.com/skills/a'],
        ]);
    }

    public function testMissingIdUsesTheFileStemAndARelativeProtocol(): void
    {
        $site = Site::of([
            'description' => 'Stem',
            'request' => 'https://example.com/search?q={keyword}',
            'response' => [
                'kind' => 'json',
                'each' => 'rows',
                'item' => [
                    'title' => 'name',
                    'url' => [['nope' => 1], '//cdn.example.com/{path}'],
                ],
            ],
        ], 'from-stem');

        $this->assertSame('from-stem', $site->id);
        $this->assertExtract($site, '{"rows":[{"name":"A","path":"a"}]}', 'https://api.example.com:8443/search', [
            ['title' => 'A', 'url' => 'https://cdn.example.com/a'],
        ]);
        $this->assertExtract($site, '{"rows":"nope"}', 'https://example.com/search', []);
    }

    public function testEmptySiteFileRaises(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'site');
        $this->assertNotFalse($file);
        file_put_contents($file, "# empty\n");

        try {
            $this->make(Sites::class)->load($file);
            $this->fail('An empty site file must raise.');
        } catch (Exception) {
        } finally {
            unlink($file);
        }
    }

    /** A template placeholder may be a path: slashes stay, other characters are encoded. */
    public function testUrlTemplateKeepsSlashesInAPathValue(): void
    {
        $site = $this->compile(<<<'YML'
id: paths
description: Path value in a template.
request: https://example.com/search?q={keyword}
response:
  kind: json
  each: rows
  item:
    title: name
    url: https://example.com/{repo}/{id}
YML);

        $this->assertExtract($site, <<<'JSON'
{"rows": [{"name": "A", "repo": "owner/repo", "id": "x 1"}]}
JSON, 'https://example.com/search?q=x', [
            ['title' => 'A', 'url' => 'https://example.com/owner/repo/x%201'],
        ]);
    }

    /** @return array<string, Site> */
    protected function sites(): array
    {
        $sites = $this->make(Sites::class);
        $sites->reload();

        return $sites->all();
    }

    /** Parse one YAML fixture the way a real site file is loaded. */
    protected function compile(string $yaml): Site
    {
        if (preg_match('/^id: ([a-z0-9-]+)/m', $yaml, $match) !== 1) {
            $this->fail('Fixture is missing id');
        }

        $meta = $this->container->get(YamlReaderInterface::class)->parse($yaml);

        return Site::of($meta, $match[1]);
    }

    /** @param list<array<string, string>> $expected */
    protected function assertHits(string $id, string $document, string $documentUrl, array $expected): void
    {
        $this->assertExtract($this->sites()[$id], $document, $documentUrl, $expected);
    }

    /** @param list<array<string, string>> $expected */
    protected function assertExtract(Site $site, string $document, string $documentUrl, array $expected): void
    {
        $hits = $this->make(SiteExtractor::class)->extract($site, $document, $documentUrl);

        $this->assertSame($expected, $hits);
    }
}
