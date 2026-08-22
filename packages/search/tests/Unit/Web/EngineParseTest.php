<?php

declare(strict_types=1);

namespace Switon\Search\Tests\Unit\Web;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Search\Web\Engine\BaiduEngine;
use Switon\Search\Web\Engine\BingEngine;
use Switon\Search\Web\Engine\GoogleEngine;
use Switon\Search\Web\Engine\ZhihuEngine;
use Switon\Testing\TestCase;

#[CoversClass(BingEngine::class)]
#[CoversClass(BaiduEngine::class)]
#[CoversClass(GoogleEngine::class)]
#[CoversClass(ZhihuEngine::class)]
class EngineParseTest extends TestCase
{
    public function testBingParseResultsExtractsOrganicLinks(): void
    {
        $results = $this->make(BingEngine::class)->parse(<<<'HTML'
<html><body><ol>
<li class="b_algo">
  <h2><a href="https://example.com/docs">Example Docs</a></h2>
  <div class="b_caption"><p>Short summary for the result.</p></div>
</li>
<li class="b_algo">
  <h2><a href="https://www.bing.com/search?q=skip">Bing internal</a></h2>
</li>
<li class="b_algo">
  <h2><a href="https://github.com/switon-php/agency">Switon Agent</a></h2>
  <div class="b_caption"><p>GitHub repository.</p></div>
</li>
</ol></body></html>
HTML, 5);

        $this->assertCount(2, $results);
        $this->assertSame('Example Docs', $results[0]['title']);
        $this->assertSame('https://example.com/docs', $results[0]['url']);
        $this->assertSame('Short summary for the result.', $results[0]['snippet']);
        $this->assertSame('https://github.com/switon-php/agency', $results[1]['url']);
    }

    public function testBaiduParseExtractsContainers(): void
    {
        $results = $this->make(BaiduEngine::class)->parse(<<<'HTML'
<html><body>
<div class="c-container">
  <h3><a href="https://example.com/a">Alpha</a></h3>
  <div class="c-abstract">Snippet A</div>
</div>
<div class="c-container">
  <h3><a href="https://example.com/b">Beta</a></h3>
</div>
</body></html>
HTML, 5);

        $this->assertCount(2, $results);
        $this->assertSame('Alpha', $results[0]['title']);
        $this->assertSame('https://example.com/a', $results[0]['url']);
        $this->assertSame('Snippet A', $results[0]['snippet']);
    }

    public function testGoogleParseExtractsH3Links(): void
    {
        $results = $this->make(GoogleEngine::class)->parse(<<<'HTML'
<html><body>
<a href="https://example.com/g1"><h3>Google One</h3></a>
<a href="javascript:void(0)"><h3>Skip</h3></a>
<a href="https://example.com/g2"><h3>Google Two</h3></a>
</body></html>
HTML, 5);

        $this->assertCount(2, $results);
        $this->assertSame('Google One', $results[0]['title']);
        $this->assertSame('https://example.com/g1', $results[0]['url']);
    }

    public function testGoogleParseUsesCiteHostOnlyWhenHrefIsGoto(): void
    {
        $results = $this->make(GoogleEngine::class)->parse(<<<'HTML'
<html><body>
<div class="N54PNb BToiNc">
  <a href="/goto?url=CAESdeadbeef"><h3 class="LC20lb">Shenzhen Weather</h3>
  <cite>https://weather.sz.gov.cn<span> › forecast › today</span></cite>
  </a>
  <div class="VwiC3b">Sunny with light wind.</div>
</div>
<a href="/url?q=https://example.com/from-url&amp;sa=U"><h3>From Url Param</h3></a>
</body></html>
HTML, 5);

        $this->assertCount(2, $results);
        $this->assertSame('Shenzhen Weather', $results[0]['title']);
        $this->assertSame('https://weather.sz.gov.cn', $results[0]['url']);
        $this->assertSame('Sunny with light wind.', $results[0]['snippet']);
        $this->assertSame('https://example.com/from-url', $results[1]['url']);
    }

    public function testGoogleUnwrapsAbsoluteRedirectAndKeepsPlus(): void
    {
        $results = $this->make(GoogleEngine::class)->parse(<<<'HTML'
<html><body>
<a href="https://www.google.com/url?q=https://example.com/C%2B%2B&amp;sa=U"><h3>Plus</h3></a>
<a href="https://www.google.com/url?q=https://example.org/C++&amp;sa=U"><h3>Raw Plus</h3></a>
<a href="https://mygoogle.com/product"><h3>Not Google</h3></a>
</body></html>
HTML, 5);

        $this->assertSame(
            ['https://example.com/C++', 'https://example.org/C++', 'https://mygoogle.com/product'],
            array_column($results, 'url'),
        );
    }

    public function testEngineRequestUrls(): void
    {
        $baidu = $this->make(BaiduEngine::class);
        $this->assertTrue($baidu->supportsSiteQuery());
        $this->assertStringContainsString('wd=php&rn=5', $baidu->buildUrl('php', 5, null));

        $google = $this->make(GoogleEngine::class)->buildUrl('php', 5, 'zh_CN');
        $this->assertStringContainsString('num=5', $google);
        $this->assertStringContainsString('hl=zh-CN', $google);

        $bing = $this->make(BingEngine::class)->buildUrl('php', 5, 'en-US');
        $this->assertStringContainsString('mkt=en-US', $bing);
        $this->assertStringNotContainsString('cc=CN', $bing);

        $zhihu = $this->make(ZhihuEngine::class);
        $this->assertFalse($zhihu->supportsSiteQuery());
        $this->assertSame(20, $zhihu->pageSize());
        $this->assertSame('https://www.zhihu.com/', $zhihu->originUrl());
        $this->assertStringContainsString('search_v3', $zhihu->buildUrl('php', 5, null));
        $this->assertStringContainsString('q=php', $zhihu->refererUrl('php', 5, null) ?? '');
    }

    public function testParsersDropJavascriptAndInternalLinks(): void
    {
        $baidu = $this->make(BaiduEngine::class)->parse(<<<'HTML'
<html><body>
<div class="c-container"><h3><a href="javascript:void(0)">Skip</a></h3></div>
<div class="c-container"><h3><a href="https://example.com/a">Keep</a></h3></div>
<div class="c-container"><h3><a href="https://example.com/b">Two</a></h3></div>
</body></html>
HTML, 1);
        $this->assertSame(['https://example.com/a'], array_column($baidu, 'url'));

        $bing = $this->make(BingEngine::class)->parse(<<<'HTML'
<html><body>
<li class="b_algo"><h2><a href="javascript:void(0)">Skip</a></h2></li>
<li class="b_algo"><h2><a href="https://www.bing.com/ck/a?q=1">Redirect</a></h2></li>
<li class="b_algo"><h2><a href="https://www.bing.com/images/search?q=1">Images</a></h2></li>
</body></html>
HTML, 5);
        $this->assertSame(['https://www.bing.com/ck/a?q=1'], array_column($bing, 'url'));

        $google = $this->make(GoogleEngine::class)->parse(<<<'HTML'
<html><body>
<a href="https://example.com/a"><h3>A</h3></a>
<a href="https://example.com/a?utm_source=x"><h3>A again</h3></a>
<a href="https://www.google.com/search?q=x"><h3>Search</h3></a>
<a href="https://example.com/b"><h3> </h3></a>
</body></html>
HTML, 5);
        $this->assertSame(['https://example.com/a'], array_column($google, 'url'));
    }

    public function testZhihuFallsBackToHighlightAndApiUrls(): void
    {
        $this->assertSame([], $this->make(ZhihuEngine::class)->parse('{', 5));
        $this->assertSame([], $this->make(ZhihuEngine::class)->parse('{"data":[1]}', 5));

        $body = json_encode([
            'data' => [
                ['object' => ['type' => 'question', 'id' => '8', 'title' => 'Q'], 'highlight' => ['description' => '<b>hi</b>']],
                ['object' => ['type' => 'answer', 'title' => 'A', 'url' => 'https://api.zhihu.com/answers/99']],
            ],
        ], JSON_THROW_ON_ERROR);
        $results = $this->make(ZhihuEngine::class)->parse($body, 5);

        $this->assertSame('hi', $results[0]['snippet']);
        $this->assertSame('https://www.zhihu.com/answer/99', $results[1]['url']);
    }

    public function testPageSizes(): void
    {
        $this->assertSame(20, $this->make(BaiduEngine::class)->pageSize());
        $this->assertSame(10, $this->make(GoogleEngine::class)->pageSize());
        $this->assertSame(10, $this->make(BingEngine::class)->pageSize());
    }

    public function testBingRespectsMaxResults(): void
    {
        $results = $this->make(BingEngine::class)->parse(<<<'HTML'
<html><body>
<li class="b_algo"><h2><a href="https://example.com/1">One</a></h2></li>
<li class="b_algo"><h2><a href="https://example.com/2">Two</a></h2></li>
<li class="b_algo"><h2><a href="https://example.com/3">Three</a></h2></li>
</body></html>
HTML, 2);

        $this->assertCount(2, $results);
    }

    public function testHtmlParsersRestoreLibxmlErrorMode(): void
    {
        $previous = libxml_use_internal_errors(false);
        try {
            foreach ([BaiduEngine::class, BingEngine::class, GoogleEngine::class] as $engine) {
                libxml_use_internal_errors(false);
                $this->make($engine)->parse('<html></html>', 1);
                $leftOn = libxml_use_internal_errors(false);
                $this->assertFalse($leftOn, $engine);
            }
        } finally {
            libxml_use_internal_errors($previous);
        }
    }

    public function testZhihuKeepsOnlyReadableContentTypes(): void
    {
        $body = json_encode([
            'data' => [
                ['object' => ['type' => 'article', 'id' => '1', 'title' => '专栏', 'excerpt' => 'e']],
                ['object' => ['type' => 'answer', 'id' => '2', 'question' => ['id' => '3', 'title' => '问'], 'excerpt' => 'e']],
                ['object' => ['type' => 'question', 'id' => '4', 'title' => '问题', 'excerpt' => '问题摘要']],
                ['object' => ['type' => 'people', 'name' => '某人', 'url_token' => 'tok', 'url' => 'https://api.zhihu.com/people/HASH']],
                ['object' => ['type' => 'zvideo', 'id' => '5', 'title' => '视频', 'url' => 'https://api.zhihu.com/zvideo/5']],
                ['object' => ['type' => 'topic', 'id' => '6', 'name' => '话题', 'url' => 'https://api.zhihu.com/topic/6']],
                ['object' => ['type' => 'collection', 'id' => '7', 'title' => '收藏', 'url' => 'https://api.zhihu.com/collection/7']],
            ],
        ], JSON_THROW_ON_ERROR);

        $results = $this->make(ZhihuEngine::class)->parse($body, 20);

        $this->assertSame(
            [
                'https://zhuanlan.zhihu.com/p/1',
                'https://www.zhihu.com/question/3/answer/2',
                'https://www.zhihu.com/question/4',
            ],
            array_column($results, 'url'),
            'a profile, video, topic, or collection has no readable page to open',
        );
        $this->assertSame('问题摘要', $results[2]['snippet']);
    }

    public function testZhihuNeverReturnsAnInternalApiAddress(): void
    {
        $body = json_encode([
            'data' => [
                // Both rows carry an `api.zhihu.com` address and nothing else to point at, so they
                // are dropped instead of being passed on as a dead-end URL.
                ['object' => ['type' => 'question', 'url' => 'https://api.zhihu.com/questions/9']],
                ['object' => ['type' => 'article', 'title' => '内部', 'url' => 'https://api.zhihu.com/other/9']],
            ],
        ], JSON_THROW_ON_ERROR);

        $results = $this->make(ZhihuEngine::class)->parse($body, 20);

        $this->assertSame([], $results, 'an internal API URL must never reach the caller');
    }
}
