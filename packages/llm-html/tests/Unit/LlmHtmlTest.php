<?php

declare(strict_types=1);

namespace Switon\LlmHtml\Tests\Unit;

use DOMDocument;
use DOMElement;
use DOMXPath;
use PHPUnit\Framework\Attributes\CoversClass;
use Switon\LlmHtml\Cleanup;
use Switon\LlmHtml\ContentRoot;
use Switon\LlmHtml\ContentRootInterface;
use Switon\LlmHtml\DomCleaner;
use Switon\LlmHtml\DomCleanerInterface;
use Switon\LlmHtml\LlmHtml;
use Switon\LlmHtml\LlmHtmlInterface;
use Switon\LlmHtml\MarkdownRenderer;
use Switon\LlmHtml\MarkdownRendererInterface;
use Switon\LlmHtml\PlainTextRenderer;
use Switon\LlmHtml\PlainTextRendererInterface;
use Switon\LlmHtml\ServiceProvider;
use Switon\LlmHtml\Tests\TestCase;

#[CoversClass(LlmHtml::class)]
#[CoversClass(ContentRoot::class)]
#[CoversClass(DomCleaner::class)]
#[CoversClass(MarkdownRenderer::class)]
#[CoversClass(PlainTextRenderer::class)]
#[CoversClass(ServiceProvider::class)]
class LlmHtmlTest extends TestCase
{
    protected function converter(): LlmHtmlInterface
    {
        return $this->make(LlmHtmlInterface::class);
    }

    public function testDependsOnInterfaceNotConcreteInTypeHint(): void
    {
        $this->assertInstanceOf(LlmHtmlInterface::class, $this->converter());
    }

    public function testExtractsArticleAndDropsNav(): void
    {
        $html = <<<'HTML'
<!doctype html>
<html><head><title>Example Doc</title><meta name="description" content="Short summary."></head>
<body><nav>skip</nav><article><h1>Title</h1><p>Body <a href="/next">link</a>.</p></article></body>
</html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertSame('Example Doc', $result->title);
        $this->assertSame('Short summary.', $result->description);
        $this->assertTrue($result->usedMainContent);
        $this->assertSame('article', $result->strategy);
        $this->assertStringContainsString('# Title', $result->content);
        $this->assertStringContainsString('[link](/next)', $result->content);
        $this->assertStringNotContainsString('skip', $result->content);
    }

    public function testPortalFixturePrefersCopyOverLinkFarm(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/portal-div-chrome.html';
        $this->assertFileExists($path);
        $html = file_get_contents($path);
        $this->assertNotFalse($html);

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('可抽取正文标题', $result->content);
        $this->assertStringContainsString('足够长的正文内容', $result->content);
        $this->assertStringContainsString('本地 fixture 不依赖远程站点', $result->content);
        $this->assertStringNotContainsString('javascript:', $result->content);
        $this->assertStringNotContainsString('首页', $result->content);
        $this->assertStringNotContainsString('侧栏一', $result->content);
        $this->assertStringNotContainsString('关于', $result->content);
        $this->assertTrue($result->usedMainContent);
    }

    public function testPicksDensestBlockWhenNoSemanticRoot(): void
    {
        $html = <<<'HTML'
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
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('Alpha project ships a readable fetch pipeline', $result->content);
        $this->assertStringContainsString('Regression fixtures stay on disk', $result->content);
        $this->assertStringNotContainsString('[A](', $result->content);
        $this->assertTrue($result->usedMainContent);
    }

    public function testSkipsJavascriptHrefLinks(): void
    {
        $html = <<<'HTML'
<html><body><article>
<p>Keep enough prose around links so the article root stays viable for extraction.</p>
<a href="javascript:void(0)">noop</a>
<a href="/ok">ok</a>
</article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('noop', $result->content);
        $this->assertStringNotContainsString('javascript:', $result->content);
        $this->assertStringContainsString('[ok](/ok)', $result->content);
    }

    public function testPreservesPreformattedCode(): void
    {
        $html = <<<'HTML'
<html><body><article><pre>echo hi;
line2</pre></article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString("```\necho hi;\nline2\n```", $result->content);
    }

    public function testStructuredDocKeepsTablesListsQuotesAndDropsChrome(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/structured-doc.html';
        $html = file_get_contents($path);
        $this->assertNotFalse($html);

        $result = $this->converter()->convert($html);

        $this->assertSame('Docs With Structure', $result->title);
        $this->assertTrue($result->usedMainContent);
        $this->assertStringContainsString('# Structured Doc', $result->content);
        $this->assertStringContainsString('> Quoted guidance for the model.', $result->content);
        $this->assertStringContainsString('1. First step', $result->content);
        $this->assertStringContainsString('2. Second step', $result->content);
        $this->assertStringContainsString('- Bullet one', $result->content);
        $this->assertStringContainsString('| Col A | Col B |', $result->content);
        $this->assertStringContainsString('| 1 | 2 |', $result->content);
        $this->assertStringContainsString('![flow chart](/img.png)', $result->content);
        $this->assertStringNotContainsString('Widget A', $result->content);
        $this->assertStringNotContainsString('hidden junk', $result->content);
        $this->assertStringNotContainsString('aria junk', $result->content);
        $this->assertStringNotContainsString('leave a comment', $result->content);
        $this->assertStringNotContainsString('Home', $result->content);
    }

    public function testSkipsLinkFarmArticleForDenserCopy(): void
    {
        $html = <<<'HTML'
<html><body>
<article class="promo">
  <a href="/1">One</a><a href="/2">Two</a><a href="/3">Three</a><a href="/4">Four</a>
  <a href="/5">Five</a><a href="/6">Six</a>
</article>
<div class="entry-content">
  <p>Real body copy explains the product in enough detail that density scoring can prefer this block.</p>
  <p>Second paragraph keeps the fixture stable without relying on remote HTML snapshots.</p>
</div>
</body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('Real body copy explains the product', $result->content);
        $this->assertStringNotContainsString('[One](', $result->content);
        $this->assertTrue($result->usedMainContent);
    }

    public function testKeepsInArticleHeaderTitle(): void
    {
        $html = <<<'HTML'
<html><body>
<header class="site-top"><a href="/">Home</a><a href="/x">Nav</a></header>
<article>
  <header><h1>In-article Title</h1></header>
  <p>Body paragraph stays with the article header title for documentation pages.</p>
</article>
</body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('# In-article Title', $result->content);
        $this->assertStringContainsString('Body paragraph stays', $result->content);
        $this->assertStringNotContainsString('Home', $result->content);
        $this->assertTrue($result->usedMainContent);
    }

    public function testPicksHigherScoringArticleAmongSeveral(): void
    {
        $html = <<<'HTML'
<html><body>
<article class="teaser"><p>Short teaser blurb only.</p></article>
<article class="entry-content">
  <h1>Full Story</h1>
  <p>This longer article has multiple paragraphs so scoring should prefer it over the teaser.</p>
  <p>Second paragraph adds enough text length and structure for a clear ranking win.</p>
</article>
</body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('# Full Story', $result->content);
        $this->assertStringNotContainsString('Short teaser blurb', $result->content);
        $this->assertSame('article', $result->strategy);
    }

    public function testChromeOnlyPageFallsBackWithoutMainFlag(): void
    {
        $html = <<<'HTML'
<html><body>
<div class="top">
  <a href="/a">A</a><a href="/b">B</a><a href="/c">C</a><a href="/d">D</a><a href="/e">E</a>
</div>
<div class="rail">
  <a href="/1">One</a><a href="/2">Two</a><a href="/3">Three</a><a href="/4">Four</a>
</div>
</body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertFalse($result->usedMainContent);
        $this->assertSame('body', $result->strategy);
        $this->assertStringNotContainsString('[A](', $result->content);
    }

    public function testSelectsIdContentAndFallsBackToOgMeta(): void
    {
        $html = file_get_contents(dirname(__DIR__) . '/Fixtures/id-content-og-meta.html');
        $this->assertNotFalse($html);

        $result = $this->converter()->convert($html);

        $this->assertSame('OG Title Wins', $result->title);
        $this->assertSame('OG description for LLM context.', $result->description);
        $this->assertTrue($result->usedMainContent);
        $this->assertSame('id:content', $result->strategy);
        $this->assertStringContainsString('# Primary Content Id', $result->content);
        $this->assertStringNotContainsString('Side1', $result->content);
        $this->assertStringNotContainsString('Blog', $result->content);
    }

    public function testSelectsRoleMainLandmark(): void
    {
        $html = file_get_contents(dirname(__DIR__) . '/Fixtures/role-main.html');
        $this->assertNotFalse($html);

        $result = $this->converter()->convert($html);

        $this->assertTrue($result->usedMainContent);
        $this->assertSame('role:main', $result->strategy);
        $this->assertStringContainsString('## Role Main Heading', $result->content);
        $this->assertStringNotContainsString('M1', $result->content);
    }

    public function testKeepsSoftRelatedProseDropsRelatedLinkFarmAndKeepsArticleFooter(): void
    {
        $html = file_get_contents(dirname(__DIR__) . '/Fixtures/soft-related-and-footer.html');
        $this->assertNotFalse($html);

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('related block is mostly prose', $result->content);
        $this->assertStringContainsString('In-article footer note', $result->content);
        $this->assertStringNotContainsString('[R1](', $result->content);
        $this->assertStringNotContainsString('[R6](', $result->content);
    }

    public function testSkipsMailtoAndHashLinksKeepsRelativeHref(): void
    {
        $html = <<<'HTML'
<html><body><article>
<p>Contact paths should stay readable for the model without mailto noise.</p>
<a href="mailto:a@b.c">email</a>
<a href="#section">jump</a>
<a href="/docs/page">docs</a>
</article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('email', $result->content);
        $this->assertStringContainsString('jump', $result->content);
        $this->assertStringNotContainsString('mailto:', $result->content);
        $this->assertStringNotContainsString('(#section)', $result->content);
        $this->assertStringContainsString('[docs](/docs/page)', $result->content);
    }

    public function testRendersNestedListsAndTableWithoutThead(): void
    {
        $html = <<<'HTML'
<html><body><article>
<p>Structured lists and tables must remain legible in markdown for LLM reasoning.</p>
<ul>
  <li>Parent
    <ul><li>Child item</li></ul>
  </li>
  <li>Sibling</li>
</ul>
<table>
  <tr><td>Name</td><td>Value</td></tr>
  <tr><td>alpha</td><td>1</td></tr>
</table>
<img src="/x.png" alt="">
</article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('- Parent', $result->content);
        $this->assertStringContainsString('Child item', $result->content);
        $this->assertStringContainsString('- Sibling', $result->content);
        $this->assertStringContainsString('| Name | Value |', $result->content);
        $this->assertStringContainsString('| alpha | 1 |', $result->content);
        $this->assertStringNotContainsString('![](/x.png)', $result->content);
    }

    public function testDropsAdsIframeAndFormChrome(): void
    {
        $html = <<<'HTML'
<html><body><article>
<p>Readable article body that should survive while ads and embeds are stripped.</p>
<div class="ad-slot"><a href="/ad">Buy now</a></div>
<iframe src="/embed"></iframe>
<form action="/s"><input name="q"><button>go</button></form>
</article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('Readable article body', $result->content);
        $this->assertStringNotContainsString('Buy now', $result->content);
        $this->assertStringNotContainsString('go', $result->content);
    }

    public function testInlineCodeAndEmphasis(): void
    {
        $html = <<<'HTML'
<html><body><article>
<p>Use <code>LlmHtml</code> with <strong>bold</strong> and <em>italic</em> cues for models.</p>
</article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('`LlmHtml`', $result->content);
        $this->assertStringContainsString('**bold**', $result->content);
        $this->assertStringContainsString('*italic*', $result->content);
    }

    public function testTrustedShortArticleStaysCleaned(): void
    {
        $pad = str_repeat('正文补充以超过可信根最小长度阈值。', 40);
        $html = <<<HTML
<html><body>
<nav><a href="/x">NavOnly</a></nav>
<article><h1>短文</h1><p>{$pad}</p></article>
</body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertSame(Cleanup::Cleaned, $result->cleanup);
        $this->assertSame('article', $result->strategy);
        $this->assertStringContainsString('短文', $result->content);
        $this->assertStringNotContainsString('NavOnly', $result->content);
        $this->assertLessThanOrEqual(10000, mb_strlen($result->content));
    }

    public function testOverBudgetPrunes(): void
    {
        $pad = str_repeat('这段正文用于撑过长度预算以便触发可选地标清理。', 500);
        $html = <<<HTML
<html><body>
<main>
<aside class="rail"><a href="/a">RailA</a><a href="/b">RailB</a><a href="/c">RailC</a></aside>
<h1>长文</h1>
<p>{$pad}</p>
</main>
</body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertSame(Cleanup::Pruned, $result->cleanup);
        $this->assertSame('main', $result->strategy);
        $this->assertStringContainsString('长文', $result->content);
        $this->assertStringNotContainsString('RailA', $result->content);
        $this->assertGreaterThan(10000, mb_strlen($result->content));
    }

    public function testDropsHreflangLanguageSwitcherInsideMain(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/hreflang-switcher-in-main.html';
        $this->assertFileExists($path);
        $html = file_get_contents($path);
        $this->assertNotFalse($html);

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('# Markdown', $result->content);
        $this->assertStringContainsString('lightweight markup language', $result->content);
        $this->assertStringNotContainsString('Deutsch', $result->content);
        $this->assertStringNotContainsString('Español', $result->content);
        $this->assertStringNotContainsString('Français', $result->content);
        $this->assertStringNotContainsString('日本語', $result->content);
    }

    public function testDropsDocShellInfoButtonChrome(): void
    {
        $path = dirname(__DIR__) . '/Fixtures/doc-shell-info-button.html';
        $this->assertFileExists($path);
        $html = file_get_contents($path);
        $this->assertNotFalse($html);

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('RFC 8259', $result->content);
        $this->assertStringContainsString('language-independent data interchange', $result->content);
        $this->assertDoesNotMatchRegularExpression('/^Info\b/m', ltrim($result->content));
        $this->assertStringNotContainsString("Info\n\n#", $result->content);
    }

    public function testKeepsLongLinkCatalogInsideMain(): void
    {
        $items = '';
        for ($i = 1; $i <= 8; $i++) {
            $items .= '<li><a href="/t' . $i . '">Deep dive into topic number '
                . $i . ' covering HTTP caching headers, CDN edge behavior, and stale-while-revalidate</a></li>';
        }
        $html = <<<HTML
<html><body>
<main>
<h1>Topic index</h1>
<p>Editorial catalog pages list long descriptive titles that agents should keep.</p>
<ul>{$items}</ul>
</main>
</body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('Topic index', $result->content);
        $this->assertStringContainsString('Deep dive into topic number 1', $result->content);
        $this->assertStringContainsString('Deep dive into topic number 8', $result->content);
    }

    public function testDemotesPageWrappingFormInsteadOfDroppingBody(): void
    {
        $html = <<<'HTML'
<html><body>
<form id="aspnetForm" method="post">
<article>
<h1>Wrapped Article</h1>
<p>ASP.NET-style pages wrap the whole document in a single form; the article body must survive for the model.</p>
<p>Second paragraph confirms demotion to a plain container rather than deleting the keep-set.</p>
</article>
</form>
</body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('Wrapped Article', $result->content);
        $this->assertStringContainsString('ASP.NET-style pages wrap', $result->content);
        $this->assertStringContainsString('demotion to a plain container', $result->content);
    }

    public function testPassesThroughNonHtmlUnchanged(): void
    {
        $markdown = "# Already Markdown\n\nJust a paragraph with a [link](/x).\n";
        $json = "{\"ok\":true,\"items\":[1,2,3]}\n";
        $plain = "plain text without angle-bracket tags\n";

        foreach ([$markdown, $json, $plain] as $payload) {
            $result = $this->converter()->convert($payload);
            $this->assertSame($payload, $result->content);
            $this->assertSame('passthrough', $result->strategy);
            $this->assertSame(Cleanup::Original, $result->cleanup);
            $this->assertFalse($result->usedMainContent);
        }
    }

    public function testStillTreatsHtmlDocumentsAsHtml(): void
    {
        $html = '<!doctype html><html><body><p>Document shell is enough to enter the HTML pipeline.</p><p>Second paragraph keeps viability.</p></body></html>';
        $result = $this->converter()->convert($html);

        $this->assertNotSame('passthrough', $result->strategy);
        $this->assertStringContainsString('Document shell is enough', $result->content);
    }

    public function testBareDivFragmentPassesThrough(): void
    {
        $fragment = '<div><p>Only a div fragment should not force HTML cleaning.</p></div>';
        $result = $this->converter()->convert($fragment);

        $this->assertSame($fragment, $result->content);
        $this->assertSame('passthrough', $result->strategy);
        $this->assertSame(Cleanup::Original, $result->cleanup);
    }

    public function testProseThatMentionsATagStaysOriginal(): void
    {
        $markdown = "# Guide\n\nUse a <script> tag when the page needs one.\n";
        $result = $this->converter()->convert($markdown);

        $this->assertSame($markdown, $result->content);
        $this->assertSame(Cleanup::Original, $result->cleanup);
    }

    public function testCommentBeforeDoctypeStillCleans(): void
    {
        $html = "<!-- lead -->\n<!doctype html><html><body><p>Comment before the doctype still enters cleaning.</p><p>Second paragraph keeps the body viable.</p></body></html>";
        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('Comment before the doctype', $result->content);
        $this->assertNotSame(Cleanup::Original, $result->cleanup);
    }

    public function testOrdinaryIdDoesNotBlockPrune(): void
    {
        $paragraph = 'Class copy stays after an ordinary id, and the inner navigation is structural chrome that the pruned pass removes. ';
        $html = '<html><body><div class="post-content" id="widget-zone"><p>'
            . str_repeat($paragraph, 12)
            . '</p><nav><a href="/n">Site section</a></nav></div></body></html>';
        $result = $this->converter()->convert($html);

        $this->assertSame('dense', $result->strategy);
        $this->assertSame(Cleanup::Pruned, $result->cleanup);
        $this->assertStringNotContainsString('Site section', $result->content);
    }

    public function testEmptyDocumentStaysCleaned(): void
    {
        $html = '<html><head><title>Script only</title></head><body><script>var a = 1;</script></body></html>';
        $result = $this->converter()->convert($html);

        $this->assertSame('', $result->content);
        $this->assertSame(Cleanup::Cleaned, $result->cleanup);
    }

    public function testConvertRestoresLibxmlErrors(): void
    {
        libxml_use_internal_errors(false);
        $this->converter()->convert('<html><body><p>Restore the libxml error flag after a pass.</p></body></html>');

        $this->assertFalse(libxml_use_internal_errors(false));
    }

    public function testTextFormatStripsTagsFromSameKeepSet(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body>
<article>
<h1>Plain Title</h1>
<p>First paragraph with a <a href="/x">link</a> inside.</p>
<p>Second paragraph keeps viability for text export.</p>
</article>
</body></html>
HTML;

        $md = $this->converter()->convert($html, 'markdown');
        $text = $this->converter()->convert($html, 'text');

        $this->assertSame('markdown', $md->format);
        $this->assertSame('text', $text->format);
        $this->assertStringContainsString('# Plain Title', $md->content);
        $this->assertStringContainsString('[link](/x)', $md->content);
        $this->assertStringContainsString('Plain Title', $text->content);
        $this->assertStringContainsString('First paragraph with a link inside.', $text->content);
        $this->assertStringNotContainsString('# ', $text->content);
        $this->assertStringNotContainsString('[link]', $text->content);
        $this->assertStringNotContainsString('<', $text->content);
    }

    public function testPreStripsScriptBodiesThatLibxmlWouldLeak(): void
    {
        // Script contains HTML-like fragments that make libxml spill JS into text nodes.
        $html = <<<'HTML'
<!doctype html><html><body>
<article>
<p>Visible article prose for the model after script cleanup with enough length here.</p>
<p>Second paragraph keeps the root viable under extraction floors.</p>
</article>
<div id="widget">Label:</div>
<script>"use strict";function boom(){var html="";html+="<div class='x'>";html+="</div>";var time=feed.time.s;node.innerHTML=html;}</script>
</body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('Visible article prose', $result->content);
        $this->assertStringNotContainsString('var time', $result->content);
        $this->assertStringNotContainsString('innerHTML', $result->content);
        $this->assertStringNotContainsString('function boom', $result->content);
    }

    public function testPresentationalIIsPlainEmKeepsItalic(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body><article>
<p>High <span>33</span>/<i>26℃</i> with <em>stress</em> and <strong>weight</strong> nearby.</p>
<p>Second paragraph keeps article viability for emphasis rendering checks.</p>
</article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('33/26℃', $result->content);
        $this->assertStringNotContainsString('*26℃*', $result->content);
        $this->assertStringContainsString('*stress*', $result->content);
        $this->assertStringContainsString('**weight**', $result->content);
    }

    public function testListItemHeadingDoesNotSplitIntoEmptyBullet(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body><article>
<ul>
<li><h1>Day one</h1><p>Forecast detail for day one stays under the list item.</p></li>
<li><h1>Day two</h1><p>Forecast detail for day two stays under the list item.</p></li>
</ul>
</article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertDoesNotMatchRegularExpression('/^- *$/m', $result->content);
        $this->assertMatchesRegularExpression('/^- # Day one/m', $result->content);
        $this->assertMatchesRegularExpression('/^- # Day two/m', $result->content);
    }

    public function testEmptyPayloadAndBomHtml(): void
    {
        $empty = $this->converter()->convert('   ');
        $this->assertSame('   ', $empty->content);
        $this->assertSame('passthrough', $empty->strategy);

        $html = "\xEF\xBB\xBF<!doctype html><html><body><article><p>Bom article prose is still extracted for the model.</p><p>Second paragraph keeps the root viable.</p></article></body></html>";
        $result = $this->converter()->convert($html);

        $this->assertNotSame('passthrough', $result->strategy);
        $this->assertStringContainsString('Bom article prose', $result->content);
    }

    public function testPlainTextKeepsBreaksAndDropsNoscript(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body><article>
<p>Visible line</p>
<br>
<hr>
<noscript>script fallback that must not reach the model</noscript>
<p>After the break.</p>
</article></body></html>
HTML;

        $text = $this->converter()->convert($html, 'text');

        $this->assertStringContainsString("Visible line", $text->content);
        $this->assertStringContainsString('After the break.', $text->content);
        $this->assertStringNotContainsString('script fallback', $text->content);
    }

    public function testMarkdownSkipsEmptyBlocksAndNumbersOrderedLists(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body><article>
<ul><li></li><li>Kept bullet with enough words to remain.</li></ul>
<ol><li>First ordered item.</li><li>Second ordered item.</li></ol>
<blockquote>   </blockquote>
<img alt="" src="/blank.png">
<img alt="Chart of results" src="/chart.png">
<a href="void(0)">not a link</a>
<code>standalone()</code>
<p>Closing paragraph keeps the article viable for extraction.</p>
<p>Second paragraph keeps the score above the floor.</p>
</article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('- Kept bullet', $result->content);
        $this->assertStringContainsString('1. First ordered item.', $result->content);
        $this->assertStringContainsString('2. Second ordered item.', $result->content);
        $this->assertStringNotContainsString('>', $result->content);
        $this->assertStringNotContainsString('blank.png', $result->content);
        $this->assertStringContainsString('![Chart of results](/chart.png)', $result->content);
        $this->assertStringContainsString('not a link', $result->content);
        $this->assertStringNotContainsString('[not a link]', $result->content);
        $this->assertStringContainsString("```\nstandalone()\n```", $result->content);
    }

    public function testNestedAndUnevenTablesStayInTheirOwnGrid(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body><article>
<table>
<tbody>
<tr><td>Outer</td><td>Wide</td></tr>
<tr><td>Only</td><td><table><tr><td>Inner</td></tr></table></td></tr>
</tbody>
</table>
<p>Table caption prose keeps the article viable for the model.</p>
<p>Second paragraph keeps the root above the score floor.</p>
</article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('| Outer | Wide |', $result->content);
        $this->assertStringContainsString('| Only |', $result->content);
        $this->assertStringContainsString('Inner', $result->content);
        $this->assertDoesNotMatchRegularExpression('/\| Inner \|/', $result->content);
    }

    public function testShortLinkListInsideArticleIsDropped(): void
    {
        $items = '';
        for ($i = 1; $i <= 10; $i++) {
            $items .= "<li><a href=\"/s{$i}\">s{$i}</a></li>";
        }
        $html = <<<HTML
<!doctype html><html><body><article>
<p>Article prose that the model should keep after the link list is removed from the landmark.</p>
<p>Second paragraph keeps this article viable and trusted.</p>
<ul>{$items}</ul>
<div><ul>{$items}</ul></div>
</article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('Article prose', $result->content);
        $this->assertStringNotContainsString('](/s1)', $result->content);
        $this->assertStringNotContainsString('](/s10)', $result->content);
    }

    public function testHreflangMixedWithOtherLinksStays(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body><article>
<p>Article prose stays even when a language list is not mostly hreflang links.</p>
<p>Second paragraph keeps the article above the viability floor.</p>
<div>
<a hreflang="en" href="/en">English version of this article</a>
<a hreflang="fr" href="/fr">French version of this article</a>
<a hreflang="de" href="/de">German version of this article</a>
<a hreflang="es" href="/es">Spanish version of this article</a>
<a href="/one">One related reading link with a title</a>
<a href="/two">Two related reading link with a title</a>
<a href="/three">Three related reading link with a title</a>
<a href="/four">Four related reading link with a title</a>
<a href="/five">Five related reading link with a title</a>
</div>
</article></body></html>
HTML;

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $this->make(DomCleanerInterface::class)->clean($xpath);

        $this->assertGreaterThan(0, $xpath->query('//a[@href="/en"]')->length);
        $this->assertGreaterThan(0, $xpath->query('//a[@href="/one"]')->length);
    }

    public function testEmptySoftChromeIsRemoved(): void
    {
        $html = <<<'HTML'
<!doctype html><html><body><article>
<div class="related"></div>
<p>Article prose remains after an empty chrome box is removed.</p>
<p>Second paragraph keeps the article viable.</p>
</article></body></html>
HTML;

        $result = $this->converter()->convert($html);

        $this->assertStringContainsString('Article prose remains', $result->content);
        $this->assertStringNotContainsString('class="related"', $result->content);
    }

    public function testSelectsNoRootWithoutABody(): void
    {
        $dom = new DOMDocument();
        $dom->loadXML('<html><head><title>x</title></head></html>');

        $this->assertNull($this->make(ContentRootInterface::class)->select(new DOMXPath($dom)));
    }

    public function testShorterBlockWinsInsideTheScoreFloor(): void
    {
        $paragraph = '<p>Shared prose paragraph with enough words to score as a content block for extraction.</p>';
        $html = '<html><body><div id="long">' . str_repeat($paragraph, 4) . '</div>'
            . '<div id="short">' . str_repeat($paragraph, 3) . '</div></body></html>';
        $dom = new DOMDocument();
        $dom->loadHTML($html);
        $node = $this->make(ContentRootInterface::class)->select(new DOMXPath($dom), false);

        $this->assertInstanceOf(DOMElement::class, $node);
        $this->assertSame('short', $node->getAttribute('id'));
    }

    public function testCleanEntryDropsStructuralChrome(): void
    {
        $html = <<<'HTML'
<html><body>
<nav>skip this navigation</nav>
<article><p>Kept article prose for the full clean entry.</p><p>Second paragraph.</p></article>
</body></html>
HTML;
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);

        $this->make(DomCleanerInterface::class)->clean($xpath);

        $this->assertSame(0, $xpath->query('//nav')->length);
        $this->assertGreaterThan(0, $xpath->query('//article')->length);
    }

    public function testServiceProviderRegisters(): void
    {
        $provider = new ServiceProvider();
        $provider->register($this->container);
        $provider->boot();

        $this->assertInstanceOf(LlmHtmlInterface::class, $this->container->make(LlmHtmlInterface::class));
    }

    public function testPlainRendererDropsNoscript(): void
    {
        $dom = new DOMDocument();
        $dom->loadHTML('<html><body><noscript>hidden fallback</noscript><p>Visible paragraph.</p></body></html>');
        $body = $dom->getElementsByTagName('body')->item(0);
        $this->assertInstanceOf(DOMElement::class, $body);

        $text = $this->make(PlainTextRendererInterface::class)->render($body);

        $this->assertStringContainsString('Visible paragraph.', $text);
        $this->assertStringNotContainsString('hidden fallback', $text);
    }

    public function testRendererCoversDetachedNodesAndVoidHref(): void
    {
        $dom = new DOMDocument();
        $dom->loadHTML('<html><body><ul><li>Lone item</li></ul><code>detached()</code><p><a href="void(0)">void</a></p><table><tr><!--gap--><td>A</td><td>B</td></tr><tr><td>C</td></tr></table></body></html>');
        $renderer = $this->make(MarkdownRendererInterface::class);

        $li = $dom->getElementsByTagName('li')->item(0);
        $this->assertInstanceOf(DOMElement::class, $li);
        $li->parentNode?->removeChild($li);
        $this->assertStringContainsString('Lone item', $renderer->render($li));

        $code = $dom->getElementsByTagName('code')->item(0);
        $this->assertInstanceOf(DOMElement::class, $code);
        $code->parentNode?->removeChild($code);
        $this->assertStringContainsString('detached()', $renderer->render($code));

        $body = $dom->getElementsByTagName('body')->item(0);
        $this->assertInstanceOf(DOMElement::class, $body);
        $markdown = $renderer->render($body);
        $this->assertStringContainsString('void', $markdown);
        $this->assertStringNotContainsString('[void]', $markdown);
        $this->assertStringContainsString('| A | B |', $markdown);
        $this->assertStringContainsString('| C |', $markdown);

        $emptyCode = $dom->createElement('code');
        $this->assertSame('', $renderer->render($emptyCode));
    }

    public function testRootPrunesHreflangAndKeepsLongLinkTitles(): void
    {
        $html = <<<'HTML'
<html><body>
<article>
<p>Article prose that stays after the language list is pruned by the root picker.</p>
<p>Second paragraph keeps the landmark viable.</p>
<ul>
<li><a hreflang="en" href="/en">en</a></li>
<li><a hreflang="fr" href="/fr">fr</a></li>
<li><a hreflang="de" href="/de">de</a></li>
<li><a hreflang="es" href="/es">es</a></li>
</ul>
</article>
<div class="rail"><p>Rail prose that is scored and penalized for its class token.</p><p>Second rail paragraph.</p></div>
<div><a href="/long">This anchor title is intentionally long enough to exceed eighty characters so it is catalog copy.</a></div>
</body></html>
HTML;
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $node = $this->make(ContentRootInterface::class)->select(new DOMXPath($dom));

        $this->assertInstanceOf(DOMElement::class, $node);
        $this->assertSame('article', strtolower($node->nodeName));
        $this->assertSame(0, (new DOMXPath($dom))->query('.//a[@hreflang]', $node)->length);

        $plain = '<html><body><div class="rail"><p>Rail prose that is scored and penalized for its class token here.</p><p>Second rail paragraph keeps a score.</p></div></body></html>';
        $railDom = new DOMDocument();
        $railDom->loadHTML($plain);
        $rail = $this->make(ContentRootInterface::class)->select(new DOMXPath($railDom));
        $this->assertInstanceOf(DOMElement::class, $rail);

        $long = '<html><body><div><a href="/long">This anchor title is intentionally long enough to exceed eighty characters so it is catalog copy.</a></div></body></html>';
        $longDom = new DOMDocument();
        $longDom->loadHTML($long);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $kept = $this->make(ContentRootInterface::class)->select(new DOMXPath($longDom));
        $this->assertInstanceOf(DOMElement::class, $kept);
        $this->assertSame('body', strtolower($kept->nodeName));
        $this->assertGreaterThan(0, (new DOMXPath($longDom))->query('//a[@href="/long"]')->length);
    }

    public function testCleanWithoutABodyStillFinishes(): void
    {
        $dom = new DOMDocument();
        $dom->loadXML('<html><head><title>x</title></head></html>');

        $this->make(DomCleanerInterface::class)->clean(new DOMXPath($dom));

        $this->assertSame(0, $dom->getElementsByTagName('form')->length);
    }
}
