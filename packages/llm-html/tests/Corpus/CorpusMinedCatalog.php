<?php

declare(strict_types=1);

namespace Switon\LlmHtml\Tests\Corpus;

/**
 * Scenarios mined from mature test suites as *goals* (synthetic HTML, not copied fixtures).
 *
 * Provenance: see SOURCES.md (defuddle fixture names, trafilatura eval with/without, readability page names).
 *
 * @return list<array<string, mixed>>
 */
class CorpusMinedCatalog
{
    public static function cases(): array
    {
        return array_merge(
            self::defuddleGoalCases(),
            self::defuddleWave2Cases(),
            self::defuddleWave3Cases(),
            self::trafilaturaBoilerplateCases(),
            self::trafilaturaWave2Cases(),
            self::trafilaturaWave3Cases(),
            self::readabilityGoalCases(),
            self::readabilityWave2Cases(),
            self::readabilityWave3Cases(),
            CorpusMinedWave4::cases(),
            CorpusMinedWave5::cases(),
        );
    }

    /**
     * Goals inspired by kepano/defuddle tests/fixtures names + expected.md behavior.
     *
     * @return list<array<string, mixed>>
     */
    protected static function defuddleGoalCases(): array
    {
        $prose = static fn (string $msg): string => '<p>' . $msg . ' Extra sentence keeps the article root viable for extraction.</p>';

        return [
            [
                'id' => 'mined.defuddle.javascript-links-unwrapped',
                'html' => self::doc('<article><h1>JavaScript Links</h1>' . $prose('Models should see link text without javascript href payloads.') . '<p>Click <a href="javascript:void(0)">here</a> or <a href="javascript:alert(1)">alert</a> then <a href="https://example.com/ok">ok</a>.</p></article>'),
                'contains' => ['here', 'alert', '[ok](https://example.com/ok)'],
                'notContains' => ['javascript:'],
            ],
            [
                'id' => 'mined.defuddle.leading-breadcrumb-dropped',
                'html' => self::doc('<div class="breadcrumb"><a href="/">Home</a> / <a href="/blog">Blog</a></div><article><h1>Job Post</h1>' . $prose('Leading breadcrumbs are chrome and should not dominate LLM context.') . '<p>Second paragraph about industry research for corpus stability.</p></article>'),
                'contains' => ['# Job Post', 'Leading breadcrumbs are chrome'],
                'notContains' => ['Home', 'Blog'],
            ],
            [
                'id' => 'mined.defuddle.trailing-related-posts-dropped',
                'html' => self::doc('<article><h1>Coffee Cooling</h1>' . $prose('Coffee cools following a simple heat-loss model useful for grounded reading.') . '<p>Most models fit two decay terms for mug and air exchange.</p></article><div class="related"><a href="/1">Related One</a><a href="/2">Related Two</a><a href="/3">Related Three</a><a href="/4">Related Four</a></div>'),
                'contains' => ['Coffee cools following'],
                'notContains' => ['Related One', 'Related Four'],
            ],
            [
                'id' => 'mined.defuddle.author-share-widget-dropped',
                'html' => self::doc('<article><h1>Blog Post Title</h1>' . $prose('Share widgets around bylines should not pollute model context.') . '<div class="share"><a href="/tw">Tweet</a><a href="/fb">Share</a><a href="/li">LinkedIn</a><a href="/mail">Email</a></div><p>Background section continues with enough text for viability.</p></article>'),
                'contains' => ['Share widgets around bylines'],
                'notContains' => ['Tweet', 'LinkedIn'],
            ],
            [
                'id' => 'mined.defuddle.social-engagement-counter-dropped',
                'html' => self::doc('<article><h1>Engagement</h1>' . $prose('Social counters are chrome for LLM extraction goals.') . '<div class="social"><a href="/s1">12k likes</a><a href="/s2">800 shares</a><a href="/s3">90 comments</a><a href="/s4">repost</a></div></article>'),
                'notContains' => ['12k likes', '800 shares'],
            ],
            [
                'id' => 'mined.defuddle.trailing-subscribe-dropped',
                'html' => self::doc('<article><h1>Notes</h1>' . $prose('Trailing newsletter CTAs should be removable chrome after footnotes.') . '<div class="newsletter subscribe"><a href="/sub">Subscribe now</a><a href="/sub2">Join list</a><a href="/sub3">Get updates</a><a href="/sub4">Weekly digests</a></div></article>'),
                'contains' => ['Trailing newsletter CTAs'],
                'notContains' => ['Subscribe now', 'Weekly digests'],
            ],
            [
                'id' => 'mined.defuddle.cookie-consent-dropped',
                'html' => self::doc('<div class="cookie-banner consent"><a href="/p">Privacy</a><a href="/a">Accept</a><a href="/r">Reject</a><a href="/m">Manage</a></div><article><h1>Policy</h1>' . $prose('Cookie consent overlays must not enter LLM markdown context.') . '</article>'),
                'contains' => ['Cookie consent overlays'],
                'notContains' => ['Accept', 'Reject', 'Manage'],
            ],
            [
                'id' => 'mined.defuddle.data-table-kept',
                'html' => self::doc('<article><h2>Language Comparison</h2>' . $prose('Data tables should survive for structured LLM reasoning.') . '<table><tr><th>Language</th><th>Year</th></tr><tr><td>Python</td><td>1991</td></tr><tr><td>Rust</td><td>2015</td></tr></table></article>'),
                'contains' => ['| Language | Year |', '| Python | 1991 |', '| Rust | 2015 |'],
            ],
            [
                'id' => 'mined.defuddle.heading-introduced-list',
                'html' => self::doc('<article><h2>Start Here</h2>' . $prose('Lists introduced by headings should remain readable markdown.') . '<ul><li>Partition pool uses encryption.</li><li>Partition esp is fat32.</li></ul></article>'),
                'contains' => ['## Start Here', '- Partition pool', '- Partition esp'],
            ],
            [
                'id' => 'mined.defuddle.toc-not-only-noise',
                'html' => self::doc('<article><h1>Install Guide</h1><nav class="toc-nav"><a href="#a">A</a><a href="#b">B</a><a href="#c">C</a><a href="#d">D</a></nav>' . $prose('Page TOC chrome can drop while body install steps remain.') . '<h2>Acquire the image</h2><p>Download the latest live ISO and verify checksums before writing media.</p></article>'),
                'contains' => ['Acquire the image', 'verify checksums'],
                'notContains' => ['](#a)', '](#b)'],
            ],
            [
                'id' => 'mined.defuddle.hidden-nodes',
                'html' => self::doc('<article><h1>Visible</h1>' . $prose('Hidden nodes must be stripped like defuddle hidden fixtures.') . '<div hidden>SECRET_HIDDEN</div><div style="visibility:hidden">SECRET_VIS</div><div aria-hidden="true">SECRET_ARIA</div></article>'),
                'contains' => ['Hidden nodes must be stripped'],
                'notContains' => ['SECRET_HIDDEN', 'SECRET_VIS', 'SECRET_ARIA'],
            ],
            [
                'id' => 'mined.defuddle.code-pre-nesting',
                'html' => self::doc('<article><h1>Code</h1>' . $prose('Nested pre/code should still yield a fenced block for models.') . '<pre><code>fn main() {\n  println!("hi");\n}</code></pre></article>'),
                'contains' => ['```', 'fn main()', 'println!'],
            ],
            [
                'id' => 'mined.defuddle.appendix-heading',
                'html' => self::doc('<article><h1>Article with Appendix</h1>' . $prose('Appendix headings should remain available after main sections.') . '<h2>Appendix I</h2><p>Appendix body text for reference material the model may need.</p></article>'),
                'contains' => ['## Appendix I', 'Appendix body text'],
            ],
            [
                'id' => 'mined.defuddle.non-semantic-hero-not-only-root',
                'html' => self::doc('<div class="hero header"><a href="/">Brand</a><a href="/login">Login</a><a href="/pricing">Pricing</a><a href="/docs">Docs</a></div><div class="entry-content"><h1>Real Post</h1>' . $prose('Non-semantic hero headers should lose to real entry-content roots.') . '<p>Second paragraph confirms denser content wins over hero chrome.</p></div>'),
                'contains' => ['# Real Post', 'Non-semantic hero headers'],
                'notContains' => ['Login', 'Pricing'],
            ],
            [
                'id' => 'mined.defuddle.comments-class-dropped-on-article-page',
                'html' => self::doc('<article><h1>Story</h1>' . $prose('Article pages should prefer story body over comment chrome blocks.') . '</article><div class="comments"><p>Please leave a comment about this story below the fold.</p><a href="/c1">c1</a><a href="/c2">c2</a></div>'),
                'contains' => ['prefer story body'],
                'notContains' => ['leave a comment'],
            ],
            [
                'id' => 'mined.defuddle.br-between-blocks',
                'html' => self::doc('<article><h1>Breaks</h1><p>First block for linebreak corpus mining.</p><br><br><p>Second block should still appear after break cleanup for the model.</p></article>'),
                'contains' => ['First block for linebreak', 'Second block should still appear'],
            ],
            [
                'id' => 'mined.defuddle.empty-table-not-crash',
                'html' => self::doc('<article><h1>Empty Table</h1>' . $prose('Empty tables must not break conversion.') . '<table></table><p>Trailing prose after empty table remains.</p></article>'),
                'contains' => ['Trailing prose after empty table'],
            ],
            [
                'id' => 'mined.defuddle.figure-alt',
                'html' => self::doc('<article><h1>Figure</h1>' . $prose('Figures with images should expose alt text to the model.') . '<figure><img src="/chart.png" alt="sales chart"><figcaption>Q1 sales</figcaption></figure></article>'),
                'contains' => ['![sales chart](/chart.png)'],
            ],
            [
                'id' => 'mined.defuddle.lazy-image-src',
                'html' => self::doc('<article><h1>Lazy</h1>' . $prose('Lazy images with real src should still surface alt text when present.') . '<img src="/real.png" data-src="/lazy.png" alt="lazy photo"></article>'),
                'contains' => ['![lazy photo](/real.png)'],
            ],
            [
                'id' => 'mined.defuddle.og-title-brand-not-only',
                'html' => '<!doctype html><html><head><title>Short</title><meta property="og:title" content="Meaningful OG Title For Models"></head><body><article><h1>On-page Title</h1><p>When title tag is weak, og:title fallback still helps callers; on-page h1 remains in content.</p><p>Second paragraph for viability in mined metadata cases.</p></article></body></html>',
                // Our converter prefers <title> over og when title exists — assert on-page content + non-empty title.
                'contains' => ['# On-page Title', 'og:title fallback still helps'],
                'title' => 'Short',
            ],
        ];
    }

    /**
     * Goals inspired by trafilatura evaldata.json with/without fragments (synthetic pages).
     *
     * @return list<array<string, mixed>>
     */
    protected static function trafilaturaBoilerplateCases(): array
    {
        $boilerplates = [
            'Impressum' => 'legal-impressum',
            'Newsletter' => 'newsletter-cta',
            'Twitter' => 'social-twitter',
            'Sharen mit' => 'share-de',
            'Kunden kauften auch' => 'bought-also',
            'Wie bewerten Sie diesen Artikel?' => 'rate-article',
            'weiterempfehlen' => 'recommend',
            'Anzeigentarife' => 'ad-rates',
            'Gutschein sichern' => 'coupon',
            'Creative Commons' => 'cc-footer',
            'Trotz sorgfältiger inhaltlicher Kontrolle' => 'disclaimer-de',
            'Sie könnten auch an folgenden Artikeln interessiert sein' => 'related-de',
            'Bitte geben Sie die Zahlenfolge' => 'captcha-hint',
            'Teste dein Wissen' => 'quiz-teaser',
            'Clipdealer' => 'stock-credit',
        ];

        $out = [];
        foreach ($boilerplates as $noise => $slug) {
            $out[] = [
                'id' => 'mined.trafilatura.without-' . $slug,
                'html' => self::doc(
                    '<article><h1>Main</h1><p>Primary article body that trafilatura-style eval would mark as a true positive for recall.</p><p>Second paragraph with concrete detail keeps density above chrome sidebars.</p></article>'
                    . '<div class="footer site-footer"><a href="/x">' . htmlspecialchars($noise, ENT_QUOTES) . '</a><a href="/y">ExtraChrome</a><a href="/z">MoreChrome</a><a href="/w">EvenMore</a></div>'
                ),
                'contains' => ['Primary article body that trafilatura-style'],
                'notContains' => [$noise, 'ExtraChrome'],
            ];
        }

        $withSnippets = [
            'recipe-bake' => 'Bake fifteen minutes until golden brown and cool on a wire rack carefully.',
            'legal-monopoly' => 'Providers inside a network may hold a monopoly that courts review for fairness.',
            'sensor-farm' => 'Sensors monitor somatic cells and rumination behavior inside modern dairy stalls.',
            'wine-bouquet' => 'The bouquet presents ripe berries and pairs well with spicy Asian dishes tonight.',
            'science-oil' => 'Fossil oil forms over millions of years from buried organic matter under pressure.',
        ];

        foreach ($withSnippets as $slug => $sentence) {
            $out[] = [
                'id' => 'mined.trafilatura.with-' . $slug,
                'html' => self::doc('<nav><a href="/1">N1</a><a href="/2">N2</a><a href="/3">N3</a><a href="/4">N4</a></nav><article><h1>Keep</h1><p>' . $sentence . '</p><p>Supporting paragraph ensures the with-fragment remains inside the selected root.</p></article>'),
                'contains' => [$sentence],
                'notContains' => ['N1', 'N4'],
            ];
        }

        return $out;
    }

    /**
     * Goals inspired by mozilla/readability test/test-pages names (synthetic; no copyrighted HTML).
     *
     * @return list<array<string, mixed>>
     */
    protected static function readabilityGoalCases(): array
    {
        return [
            [
                'id' => 'mined.readability.hidden-nodes',
                'html' => self::doc('<article><h1>Show</h1><p>Readability hidden-nodes goal: visible prose stays, hidden siblings go.</p><div hidden>HID1</div><span style="display:none">HID2</span></article>'),
                'contains' => ['visible prose stays'],
                'notContains' => ['HID1', 'HID2'],
            ],
            [
                'id' => 'mined.readability.basic-tags-cleaning',
                'html' => self::doc('<article><h1>Clean</h1><script>evil()</script><style>.x{}</style><p>Scripts and styles must not appear in LLM markdown output.</p></article>'),
                'contains' => ['Scripts and styles must not appear'],
                'notContains' => ['evil()', '.x{}'],
            ],
            [
                'id' => 'mined.readability.keep-tabular-data',
                'html' => self::doc('<article><h1>Table</h1><p>Tabular data should be kept for model consumption.</p><table><tr><th>City</th><th>Pop</th></tr><tr><td>Berlin</td><td>3.7M</td></tr></table></article>'),
                'contains' => ['| City | Pop |', '| Berlin | 3.7M |'],
            ],
            [
                'id' => 'mined.readability.clean-links',
                'html' => self::doc('<article><h1>Links</h1><p>Clean links keep hrefs that help models cite sources.</p><p>See <a href="https://example.com/a">source A</a> and <a href="/local">local</a>.</p></article>'),
                'contains' => ['[source A](https://example.com/a)', '[local](/local)'],
            ],
            [
                'id' => 'mined.readability.js-link-replacement',
                'html' => self::doc('<article><h1>JS</h1><p>JS link replacement goal: keep anchor text, drop javascript URLs.</p><a href="javascript:doThing()">do thing</a></article>'),
                'contains' => ['do thing'],
                'notContains' => ['javascript:'],
            ],
            [
                'id' => 'mined.readability.data-url-image-skipped',
                'html' => self::doc('<article><h1>Data URL</h1><p>Data URL images should not dump base64 into the model context.</p><img src="data:image/png;base64,aaaa" alt="tiny"></article>'),
                'contains' => ['Data URL images should not dump'],
                'notContains' => ['data:image/png'],
            ],
            [
                'id' => 'mined.readability.metadata-preferred-title',
                'html' => '<!doctype html><html><head><title>Preferred Title</title><meta name="description" content="Preferred description"></head><body><article><p>Metadata preferred goal: title and description populate the result DTO for callers.</p><p>Second paragraph for viability under readability-inspired metadata cases.</p></article></body></html>',
                'title' => 'Preferred Title',
                'description' => 'Preferred description',
            ],
            [
                'id' => 'mined.readability.comment-inside-script-ignored',
                'html' => self::doc('<article><h1>Script Comment</h1><script>/* COMMENT_IN_SCRIPT */</script><p>Comments inside script tags must not leak after cleaning.</p></article>'),
                'contains' => ['must not leak after cleaning'],
                'notContains' => ['COMMENT_IN_SCRIPT'],
            ],
            [
                'id' => 'mined.readability.keep-images-with-alt',
                'html' => self::doc('<article><h1>Images</h1><p>Keep images that carry alt text useful for grounded answers.</p><img src="/photo.jpg" alt="bridge at dusk"></article>'),
                'contains' => ['![bridge at dusk](/photo.jpg)'],
            ],
            [
                'id' => 'mined.readability.links-in-tables',
                'html' => self::doc('<article><h1>Links in Tables</h1><p>Links inside table cells should remain addressable in markdown tables.</p><table><tr><th>Name</th><th>URL</th></tr><tr><td>Docs</td><td><a href="/docs">open</a></td></tr></table></article>'),
                'contains' => ['| Docs | [open](/docs) |'],
            ],
            [
                'id' => 'mined.readability.blogger-style-chrome',
                'html' => self::doc('<div id="sidebar-right" class="sidebar">' . self::links(5, 'S') . '</div><div class="post-body"><h1>Blogger Post</h1><p>Blogger-style templates should still yield the post body for LLM reading.</p><p>Second paragraph mirrors common blog CMS layouts in readability fixtures.</p></div>'),
                'contains' => ['# Blogger Post', 'yield the post body'],
                'notContains' => ['S1', 'S5'],
            ],
            [
                'id' => 'mined.readability.aria-hidden-main-still',
                'html' => self::doc('<div aria-hidden="true" class="modal">MODAL</div><main><h1>Main</h1><p>Even when modals are aria-hidden, main landmark content must remain extractable.</p><p>Second paragraph supports role and main scoring paths.</p></main>'),
                'contains' => ['main landmark content must remain'],
                'notContains' => ['MODAL'],
                'strategy' => 'main',
            ],
        ];
    }

    /**
     * Second wave from remaining defuddle fixture-name families.
     *
     * @return list<array<string, mixed>>
     */
    protected static function defuddleWave2Cases(): array
    {
        $p = static fn (string $msg): string => '<p>' . $msg . ' Supporting sentence keeps article viability for mined corpus cases.</p>';

        $cases = [
            [
                'id' => 'mined.defuddle.footnotes-section-kept-as-prose',
                'html' => self::doc('<article><h1>Essay</h1>' . $p('Footnote bodies should remain readable prose for LLM citation context.') . '<section class="footnotes"><ol><li id="fn1">Source about distributed systems reliability patterns.</li></ol></section></article>'),
                'contains' => ['distributed systems reliability'],
            ],
            [
                'id' => 'mined.defuddle.footnotes-sup-ref-text',
                'html' => self::doc('<article><h1>Paper</h1><p>Claim one<sup><a href="#fn1">1</a></sup> continues with enough surrounding text for extraction.</p><p id="fn1">Footnote one expands the claim for readers and models alike.</p></article>'),
                'contains' => ['Claim one', 'Footnote one expands'],
            ],
            [
                'id' => 'mined.defuddle.wikipedia-references-list',
                'html' => self::doc('<article><h1>Wiki Topic</h1>' . $p('Wikipedia-style reference lists should stay available after chrome cleanup.') . '<h2>References</h2><ol><li>Author, Title, 2020.</li><li>Other, Paper, 2021.</li></ol></article>'),
                'contains' => ['## References', '1. Author, Title, 2020', '2. Other, Paper, 2021'],
            ],
            [
                'id' => 'mined.defuddle.eyebrow-category-not-title',
                'html' => self::doc('<article><p class="eyebrow"><a href="/cat">Category Label</a></p><h1>Real Headline</h1>' . $p('Eyebrow category links should not replace the real headline for models.') . '</article>'),
                'contains' => ['# Real Headline'],
            ],
            [
                'id' => 'mined.defuddle.callout-admonition-prose',
                'html' => self::doc('<article><h1>Docs</h1>' . $p('Admonition callouts should keep their warning text for LLM guidance.') . '<div class="callout"><p>Warning: enable encryption before writing the disk image.</p></div></article>'),
                'contains' => ['enable encryption before writing'],
            ],
            [
                'id' => 'mined.defuddle.bootstrap-alert-kept',
                'html' => self::doc('<article><h1>Alert</h1>' . $p('Bootstrap-style alerts often carry operational notes models need.') . '<div class="alert alert-warning"><p>Restart required after changing the TLS certificate path.</p></div></article>'),
                'contains' => ['Restart required after changing'],
            ],
            [
                'id' => 'mined.defuddle.complex-table-colspan-cells',
                'html' => self::doc('<article><h1>Grid</h1>' . $p('Complex tables should still emit cell text even if layout is imperfect.') . '<table><tr><th>A</th><th>B</th><th>C</th></tr><tr><td>1</td><td>2</td><td>3</td></tr><tr><td>4</td><td>5</td><td>6</td></tr></table></article>'),
                'contains' => ['| A | B | C |', '| 1 | 2 | 3 |', '| 4 | 5 | 6 |'],
            ],
            [
                'id' => 'mined.defuddle.nbsp-normalized',
                'html' => self::doc('<article><h1>Spaces</h1><p>Non-breaking&nbsp;spaces should normalize into readable prose for tokenizers.</p><p>Second&nbsp;paragraph keeps viability after nbsp handling goals.</p></article>'),
                'contains' => ['Non-breaking', 'spaces should normalize'],
            ],
            [
                'id' => 'mined.defuddle.whitespace-newlines-compact',
                'html' => self::doc("<article><h1>Whitespace</h1><p>Too   many    spaces</p>\n\n\n<p>should compact without deleting the words models need.</p></article>"),
                'contains' => ['Too many spaces', 'should compact without deleting'],
            ],
            [
                'id' => 'mined.defuddle.leading-hr-ok',
                'html' => self::doc('<article><hr><h1>After HR</h1>' . $p('Leading horizontal rules should not remove the following title and body.') . '</article>'),
                'contains' => ['# After HR', 'Leading horizontal rules'],
            ],
            [
                'id' => 'mined.defuddle.menu-id-not-content',
                'html' => self::doc('<div id="menu">' . self::links(6, 'Menu') . '</div><article><h1>Body</h1>' . $p('Elements with menu ids are chrome and must lose to article body copy.') . '</article>'),
                'contains' => ['Elements with menu ids are chrome'],
                'notContains' => ['Menu1', 'Menu6'],
            ],
            [
                'id' => 'mined.defuddle.hero-class-chrome',
                'html' => self::doc('<div class="hero">' . self::links(5, 'Hero') . '</div><article><h1>Story</h1>' . $p('Hero class blocks are usually marketing chrome around the real story.') . '<p>Second paragraph confirms article preference over hero link farms.</p></article>'),
                'contains' => ['# Story', 'marketing chrome around the real story'],
                'notContains' => ['Hero1', 'Hero5'],
            ],
            [
                'id' => 'mined.defuddle.time-element-kept',
                'html' => self::doc('<article><h1>Dated</h1><p>Published <time datetime="2025-04-08">April 8, 2025</time> with enough surrounding article text for extraction.</p><p>Second paragraph keeps the dated post viable in the corpus.</p></article>'),
                'contains' => ['April 8, 2025'],
            ],
            [
                'id' => 'mined.defuddle.links-inside-inline-code',
                'html' => self::doc('<article><h1>Code Links</h1>' . $p('Inline code should keep code text even when authors wrap fake links inside.') . '<p>Use <code>fetch()</code> not a bare URL in code spans.</p></article>'),
                'contains' => ['`fetch()`'],
            ],
            [
                'id' => 'mined.defuddle.svg-stripped-safe',
                'html' => self::doc('<article><h1>SVG</h1>' . $p('SVG chrome can be stripped without crashing conversion.') . '<svg class="icon" viewBox="0 0 10 10"><path d="M0 0"/></svg><p>Trailing prose after svg remains for the model.</p></article>'),
                'contains' => ['Trailing prose after svg remains'],
            ],
            [
                'id' => 'mined.defuddle.noscript-stripped',
                'html' => self::doc('<article><h1>Noscript</h1><noscript>ENABLE_JS_PLEASE</noscript>' . $p('Noscript fallback banners should not enter LLM markdown.') . '</article>'),
                'contains' => ['Noscript fallback banners'],
                'notContains' => ['ENABLE_JS_PLEASE'],
            ],
            [
                'id' => 'mined.defuddle.empty-video-ignored',
                'html' => self::doc('<article><h1>Video</h1>' . $p('Empty video tags should not break or dominate output.') . '<video></video><p>Describe the talk in text instead of relying on empty media tags.</p></article>'),
                'contains' => ['Describe the talk in text'],
            ],
            [
                'id' => 'mined.defuddle.buttons-in-paragraphs-text',
                'html' => self::doc('<article><h1>Buttons</h1><p>Click <button>Save</button> only if the surrounding instructions remain visible to the model after cleaning forms.</p><p>Second paragraph without buttons keeps viability high enough.</p></article>'),
                // forms are stripped globally — button inside p without form may remain as text via default
                'contains' => ['surrounding instructions remain visible'],
            ],
            [
                'id' => 'mined.defuddle.header-with-subtitle',
                'html' => self::doc('<article><header><h1>Main Title</h1><p>Subtitle line under the title for documentation pages.</p></header>' . $p('Header wrapping title plus subtitle should keep both for LLM context.') . '</article>'),
                'contains' => ['# Main Title', 'Subtitle line under the title'],
            ],
            [
                'id' => 'mined.defuddle.header-wraps-content-keep',
                'html' => self::doc('<article><header><h1>Wrapped</h1><p>When header wraps primary content, in-article header must not be deleted as page chrome.</p><p>Second paragraph inside header-wrapped content stays extractable.</p></header></article>'),
                'contains' => ['# Wrapped', 'in-article header must not be deleted'],
            ],
            [
                'id' => 'mined.defuddle.sidebar-toggle-ignored',
                'html' => self::doc('<div class="sidebar"><input type="checkbox" id="toggle"><label for="toggle">Menu</label>' . self::links(4, 'T') . '</div><article><h1>Doc</h1>' . $p('Sidebar toggle checkboxes are chrome around documentation bodies.') . '</article>'),
                'contains' => ['Sidebar toggle checkboxes are chrome'],
                'notContains' => ['T1', 'T4'],
            ],
            [
                'id' => 'mined.defuddle.related-inline-stories-dropped',
                'html' => self::doc('<article><h1>Lead</h1>' . $p('Inline related stories blocks should drop when they are link farms.') . '<div class="related">' . self::links(6, 'RelStory') . '</div><p>Closing paragraph after related block remains in the article root.</p></article>'),
                'contains' => ['Closing paragraph after related'],
                'notContains' => ['RelStory1', 'RelStory6'],
            ],
            [
                'id' => 'mined.defuddle.scoring-table-with-links-kept',
                'html' => self::doc('<article><h1>Scores</h1>' . $p('Tables that mix text and links should still render for comparison tasks.') . '<table><tr><th>Item</th><th>Link</th></tr><tr><td>Alpha</td><td><a href="/a">open-a</a></td></tr><tr><td>Beta</td><td><a href="/b">open-b</a></td></tr></table></article>'),
                'contains' => ['| Alpha | [open-a](/a) |', '| Beta | [open-b](/b) |'],
            ],
            [
                'id' => 'mined.defuddle.logo-wrapper-not-body',
                'html' => self::doc('<div class="logo-wrapper"><a href="/">LogoHome</a><a href="/app">LogoApp</a><a href="/blog">LogoBlog</a><a href="/careers">LogoJobs</a></div><article><h1>Post</h1>' . $p('Logo substring wrappers are navigation chrome, not article body.') . '</article>'),
                'contains' => ['Logo substring wrappers are navigation'],
                'notContains' => ['LogoHome', 'LogoJobs'],
            ],
            [
                'id' => 'mined.defuddle.table-layout-two-column-prefer-copy',
                'html' => self::doc('<table><tr><td class="sidebar">' . self::links(5, 'Col') . '</td><td class="post-body"><h1>Column Post</h1><p>Single-column content inside layout tables should still be recoverable for models.</p><p>Second paragraph in the content cell beats the link column.</p></td></tr></table>'),
                'contains' => ['Column Post', 'layout tables should still be recoverable'],
                'notContains' => ['Col1', 'Col5'],
            ],
            [
                'id' => 'mined.defuddle.peripheral-tables-dropped-or-ignored',
                'html' => self::doc('<article><h1>Core</h1>' . $p('Peripheral layout tables around ads should not drown core paragraphs.') . '</article><table class="widget"><tr><td><a href="/ad1">AdOne</a><a href="/ad2">AdTwo</a><a href="/ad3">AdThree</a><a href="/ad4">AdFour</a></td></tr></table>'),
                'contains' => ['Peripheral layout tables'],
                'notContains' => ['AdOne', 'AdFour'],
            ],
            [
                'id' => 'mined.defuddle.see-also-after-thin-section',
                'html' => self::doc('<article><h1>Topic</h1>' . $p('Thin sections before see-also should still keep the main explanation.') . '<h2>See also</h2><ul><li><a href="/x">External X</a></li><li><a href="/y">External Y</a></li></ul></article>'),
                'contains' => ['main explanation', '## See also'],
            ],
            [
                'id' => 'mined.defuddle.back-nav-link-dropped',
                'html' => self::doc('<nav><a href="/back">Back to index</a><a href="/2">N2</a><a href="/3">N3</a><a href="/4">N4</a></nav><article><h1>Detail</h1>' . $p('Back navigation links are chrome relative to the detail article body.') . '</article>'),
                'contains' => ['Back navigation links are chrome'],
                'notContains' => ['Back to index'],
            ],
            [
                'id' => 'mined.defuddle.multi-article-portfolio-pick-best',
                'html' => self::doc('<article><p>Card teaser only.</p></article><article class="entry-content"><h1>Featured Case</h1><p>Portfolio pages often include multiple articles; denser featured case should win for LLM reading.</p><p>Second paragraph adds structure so scoring prefers this entry-content article.</p></article>'),
                'contains' => ['# Featured Case', 'denser featured case should win'],
                'notContains' => ['Card teaser only'],
            ],
            [
                'id' => 'mined.defuddle.trailing-cta-newsletter',
                'html' => self::doc('<article><h1>Essay</h1>' . $p('Trailing CTA newsletter modules should not override the essay body.') . '<div class="subscribe newsletter">' . self::links(5, 'CTA') . '</div></article>'),
                'contains' => ['Trailing CTA newsletter modules'],
                'notContains' => ['CTA1', 'CTA5'],
            ],
            [
                'id' => 'mined.defuddle.math-alt-or-text-kept',
                'html' => self::doc('<article><h1>Math</h1>' . $p('Math images should at least expose alt text when present for LLM grounding.') . '<img src="/eq.png" alt="E = mc^2"></article>'),
                'contains' => ['![E = mc^2](/eq.png)'],
            ],
            [
                'id' => 'mined.defuddle.raw-latex-in-code',
                'html' => self::doc('<article><h1>LaTeX</h1>' . $p('Raw latex in code fences should remain literal for technical posts.') . '<pre>\\frac{a}{b} = c</pre></article>'),
                'contains' => ['\\frac{a}{b} = c'],
            ],
            [
                'id' => 'mined.defuddle.metadata-byline-not-only-root',
                'html' => self::doc('<article><p>By Jane Author</p><h1>Byline Post</h1>' . $p('Bylines can stay as prose but the headline and body remain primary.') . '<p>Second paragraph after byline continues the article narrative for models.</p></article>'),
                'contains' => ['# Byline Post', 'headline and body remain primary'],
            ],
            [
                'id' => 'mined.defuddle.category-links-chrome',
                'html' => self::doc('<div class="menu">' . self::links(5, 'Cat') . '</div><article><h1>Post</h1>' . $p('Category link strips beside posts are chrome for extraction goals.') . '</article>'),
                'contains' => ['Category link strips beside posts'],
                'notContains' => ['Cat1', 'Cat5'],
            ],
            [
                'id' => 'mined.defuddle.card-grid-headings-not-body',
                'html' => self::doc('<div class="grid">' . self::links(6, 'Card') . '</div><article><h1>Longform</h1>' . $p('Card grids with stripped headings should lose to longform article copy.') . '<p>Second longform paragraph ensures density beats the card grid chrome.</p></article>'),
                'contains' => ['Longform', 'lose to longform article copy'],
                'notContains' => ['Card1', 'Card6'],
            ],
            [
                'id' => 'mined.defuddle.content-list-internal-links',
                'html' => self::doc('<article><h1>Indexical</h1>' . $p('Content lists with internal links can remain when they are the page purpose.') . '<ul><li><a href="/a">Section A</a></li><li><a href="/b">Section B</a></li><li><a href="/c">Section C</a></li></ul></article>'),
                'contains' => ['[Section A](/a)', '[Section B](/b)', '[Section C](/c)'],
            ],
            [
                'id' => 'mined.defuddle.live-blog-metadata-body',
                'html' => self::doc('<article><h1>Live Blog</h1><p>Update 10:00 — markets opened higher with enough narrative text for extraction.</p><p>Update 11:00 — analysts noted volatility while describing the same session in prose.</p></article>'),
                'contains' => ['markets opened higher', 'analysts noted volatility'],
            ],
            [
                'id' => 'mined.defuddle.iso-date-and-read-time',
                'html' => self::doc('<article><h1>Timed</h1><p>2024-05-01 · 8 min read — dated posts should keep the body after metadata chrome.</p><p>Second paragraph ensures the timed article remains the selected root.</p></article>'),
                'contains' => ['8 min read', 'dated posts should keep the body'],
            ],
            [
                'id' => 'mined.defuddle.social-counter-link-dropped',
                'html' => self::doc('<article><h1>Counters</h1>' . $p('Social counter links are engagement chrome.') . '<div class="social-share"><a href="/l">Like 9k</a><a href="/s">Share 2k</a><a href="/c">Comment 400</a><a href="/r">Repost 12</a></div></article>'),
                'notContains' => ['Like 9k', 'Share 2k'],
            ],
            [
                'id' => 'mined.defuddle.elementor-archive-prefer-post',
                'html' => self::doc('<div class="elementor-location-archive">' . self::links(6, 'Arch') . '</div><article class="post-content"><h1>Single Post</h1>' . $p('Elementor archive chrome should lose to a single post-content article.') . '<p>Second paragraph on the post keeps scoring above the archive link farm.</p></article>'),
                'contains' => ['# Single Post', 'lose to a single post-content'],
                'notContains' => ['Arch1', 'Arch6'],
            ],
            [
                'id' => 'mined.defuddle.base64-placeholder-not-dumped',
                'html' => self::doc('<article><h1>Placeholder</h1>' . $p('Base64 placeholder images must not dump into markdown hrefs.') . '<img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7" alt="placeholder"></article>'),
                'notContains' => ['data:image/gif'],
            ],
            [
                'id' => 'mined.defuddle.embedded-videos-not-iframe',
                'html' => self::doc('<article><h1>Talk</h1>' . $p('Embedded video iframes should be removed while talk descriptions remain.') . '<iframe src="https://youtube.com/embed/x"></iframe><p>Speaker discusses queues and backpressure in distributed workers.</p></article>'),
                'contains' => ['queues and backpressure'],
                'notContains' => ['youtube.com/embed'],
            ],
            [
                'id' => 'mined.defuddle.cjk-obsidian-publish',
                'html' => self::doc('<article><h1>中文标题</h1><p>这是一段足够长的中文正文，用来验证 CJK 页面在清洗与渲染后对模型仍然可读。</p><p>第二段继续补充说明，避免短文本被密度阈值误判为无效根节点。</p></article>'),
                'contains' => ['# 中文标题', '足够长的中文正文', '第二段继续补充说明'],
            ],
            [
                'id' => 'mined.defuddle.github-issue-style',
                'html' => self::doc('<article><h1>Issue #56</h1>' . $p('Issue bodies should keep reproduction steps for agent debugging.') . '<pre>steps:\n  - run tests\n  - observe fail</pre></article>'),
                'contains' => ['Issue #56', 'run tests', 'observe fail'],
            ],
            [
                'id' => 'mined.defuddle.docs-mdn-style',
                'html' => self::doc('<nav class="sidebar">' . self::links(5, 'MDN') . '</nav><article><h1>Array</h1>' . $p('MDN-style documentation pages should prefer the API article over the sidebar.') . '<pre>Array.prototype.map</pre></article>'),
                'contains' => ['# Array', 'Array.prototype.map'],
                'notContains' => ['MDN1', 'MDN5'],
            ],
            [
                'id' => 'mined.defuddle.listing-page-soft-fallback',
                'html' => self::doc('<div class="listing">' . self::links(8, 'Item') . '</div>'),
                'usedMain' => false,
                'strategy' => 'body',
            ],
            [
                'id' => 'mined.defuddle.tag-like-title-escaping',
                'html' => self::doc('<article><h1>Use &lt;code&gt; tags wisely</h1><p>Titles that look like tags should remain escaped text visible to the model without breaking markdown structure in the body paragraph that follows for viability.</p></article>'),
                'contains' => ['tags wisely'],
            ],
        ];

        // Parametric footnote / issue-style chrome drops
        foreach (['fnref', 'footnote', 'sidenote'] as $i => $token) {
            $cases[] = [
                'id' => 'mined.defuddle.soft-token-prose-' . $token,
                'html' => self::doc('<article><h1>Note ' . $token . '</h1>' . $p('Soft token ' . $token . ' prose blocks that are not link farms should remain.') . '<div class="' . $token . '"><p>Expanded ' . $token . ' explanation with enough words to avoid link-heavy deletion.</p></div></article>'),
                'contains' => ['Expanded ' . $token . ' explanation'],
            ];
        }

        return $cases;
    }

    /**
     * More trafilatura without/with roles from evaldata fragment patterns.
     *
     * @return list<array<string, mixed>>
     */
    protected static function trafilaturaWave2Cases(): array
    {
        $moreWithout = [
            'Aktuelle Berichte aus dieser Kategorie' => 'more-in-category',
            'New Holland T6050' => 'random-ad-product',
            'Mit anderen Teillen' => 'share-typo-de',
            'Dieser Eintrag wurde veröffentlicht' => 'wordpress-published-meta',
            'Weitere Informationen' => 'more-info-chrome',
            'Steuerdarling' => 'promo-nickname',
            '31. Mai' => 'bare-dateline-footer',
            'Meander 151' => 'address-boilerplate',
            'wurde erfolgreich hinzugefügt' => 'cart-toast',
            'Thema: Fußball' => 'quiz-topic-chrome',
        ];

        $out = [];
        foreach ($moreWithout as $noise => $slug) {
            $out[] = [
                'id' => 'mined.trafilatura.without2-' . $slug,
                'html' => self::doc(
                    '<article><h1>Keep</h1><p>Evaluated body fragment that should remain as a true positive for recall-oriented LLM prep.</p><p>Second paragraph provides length so footer chrome does not win density.</p></article>'
                    . '<footer class="site-footer"><a href="/n">' . htmlspecialchars($noise, ENT_QUOTES) . '</a><a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></footer>'
                ),
                'contains' => ['Evaluated body fragment that should remain'],
                'notContains' => [$noise],
            ];
        }

        $moreWith = [
            'club-culture' => 'Club culture policy announcements belong in the extracted body for civic pages.',
            'price-transparency' => 'Price adjustment transparency rules require clear notice inside the consumer article body.',
            'cookie-recipe' => 'Cream butter and sugar then fold almonds before baking the cookies on parchment.',
            'meeting-notes' => 'Agenda items included introductions, breakouts, and a thirty minute open discussion block.',
            'air-quality' => 'Millions of commuters contribute to dense air pollution in growing urban corridors daily.',
        ];

        foreach ($moreWith as $slug => $sentence) {
            $out[] = [
                'id' => 'mined.trafilatura.with2-' . $slug,
                'html' => self::doc('<aside>' . self::links(4, 'A') . '</aside><article><h1>Body</h1><p>' . $sentence . '</p><p>Supporting paragraph keeps the with-fragment inside the selected article root.</p></article>'),
                'contains' => [$sentence],
                'notContains' => ['A1'],
            ];
        }

        return $out;
    }

    /**
     * More readability test-page name goals as synthetic HTML.
     *
     * @return list<array<string, mixed>>
     */
    protected static function readabilityWave2Cases(): array
    {
        $names = [
            'aclu' => 'Civil liberties explainers should keep legal prose and drop site chrome.',
            'bbc-1' => 'News article bodies should survive BBC-like navigation wrappers.',
            'cnet' => 'Product review conclusions should remain after gadget site sidebars.',
            'cnn' => 'Breaking news paragraphs should remain extractable amid video chrome.',
            'daringfireball-1' => 'Longform linkblog essays should keep the essay voice paragraphs.',
            'dropbox-blog' => 'Engineering blog posts should prefer post content over product menus.',
            'engadget' => 'Gadget news ledes should remain after related-video modules.',
            'gitlab-blog' => 'Release announcement details should remain for agent change summaries.',
            'guardian-1' => 'Newspaper article bodies should beat promo rail link farms.',
            'heise' => 'Technical magazine bodies should keep specs and drop shop widgets.',
            'ietf-1' => 'RFC-style specification prose should remain for protocol questions.',
            'lemonde-1' => 'Multilingual news bodies should keep the story paragraphs.',
            'lifehacker-working' => 'How-to steps should remain after newsletter and comment chrome.',
            'lwn-1' => 'Subscriber technica articles should keep the analysis paragraphs.',
        ];

        $out = [];
        foreach ($names as $slug => $goal) {
            $out[] = [
                'id' => 'mined.readability.page-' . $slug,
                'html' => self::doc(
                    '<header class="site-header"><a href="/">Home</a><a href="/sec">Sections</a><a href="/acc">Account</a><a href="/shop">Shop</a></header>'
                    . '<article><h1>' . strtoupper($slug) . '</h1><p>' . $goal . '</p><p>Second paragraph for ' . $slug . ' keeps the readability-inspired root viable.</p></article>'
                    . '<aside class="sidebar"><a href="/r1">R1</a><a href="/r2">R2</a><a href="/r3">R3</a><a href="/r4">R4</a></aside>'
                ),
                'contains' => [$goal],
                'notContains' => ['Sections', 'Account', 'R1', 'R4'],
            ];
        }

        $out[] = [
            'id' => 'mined.readability.lazy-image-variants',
            'html' => self::doc('<article><h1>Lazy</h1><p>Lazy-loaded images with src present should still expose alt text to models.</p><img src="/a.jpg" data-src="/b.jpg" alt="variant a"><img src="/c.jpg" alt="variant c"></article>'),
            'contains' => ['![variant a](/a.jpg)', '![variant c](/c.jpg)'],
        ];

        $out[] = [
            'id' => 'mined.readability.embedded-videos-drop-iframe',
            'html' => self::doc('<article><h1>Embed</h1><p>Embedded videos should not inject iframe URLs into markdown context.</p><iframe src="/player"></iframe><p>Transcript style prose carries the talk content instead.</p></article>'),
            'contains' => ['Transcript style prose'],
            'notContains' => ['/player'],
        ];

        $out[] = [
            'id' => 'mined.readability.invalid-attributes-tolerated',
            'html' => self::doc('<article><h1>Invalid</h1><p foo="bar">Invalid attributes on paragraphs should not prevent text extraction for models.</p><p>Second paragraph ensures the article remains selectable.</p></article>'),
            'contains' => ['Invalid attributes on paragraphs'],
        ];

        $out[] = [
            'id' => 'mined.readability.archive-of-our-own-style',
            'html' => self::doc('<div class="navigation">' . self::links(5, 'AO3') . '</div><article><h1>Work Title</h1><p>Fanwork-style pages should still expose the work body text to the model.</p><p>Second chapter paragraph continues the narrative enough for density scoring.</p></article>'),
            'contains' => ['# Work Title', 'expose the work body text'],
            'notContains' => ['AO31', 'AO35'],
        ];

        foreach (['folha', 'breitbart', 'buzzfeed-1', 'citylab-1', 'ehow-1', 'ehow-2', 'firefox-nightly-blog', 'gmw', 'herald-sun-1', 'la-nacion'] as $slug) {
            $out[] = [
                'id' => 'mined.readability.page-' . $slug,
                'html' => self::doc(
                    '<nav>' . self::links(4, 'Nav') . '</nav>'
                    . '<article><h1>' . $slug . '</h1><p>Readability page goal for ' . $slug . ' keeps story prose after navigation chrome for LLM context.</p><p>Second paragraph for ' . $slug . ' stabilizes root selection in the mined corpus.</p></article>'
                ),
                'contains' => ['Readability page goal for ' . $slug],
                'notContains' => ['Nav1', 'Nav4'],
            ];
        }

        return $out;
    }

    /**
     * Third wave: more defuddle fixture-name families (code highlighters, extractors, issues).
     *
     * @return list<array<string, mixed>>
     */
    protected static function defuddleWave3Cases(): array
    {
        $p = static fn (string $msg): string => '<p>' . $msg . ' Supporting sentence keeps article viability for wave3 mined cases.</p>';

        $cases = [
            [
                'id' => 'mined.defuddle.code-linenums-content-kept',
                'html' => self::doc('<article><h1>Highlight</h1>' . $p('Highlighter linenumber chrome should not erase the code payload models need.') . '<pre><code><span class="lineno">1</span>echo hi;' . "\n" . '<span class="lineno">2</span>echo bye;</code></pre></article>'),
                'contains' => ['echo hi', 'echo bye'],
            ],
            [
                'id' => 'mined.defuddle.code-copy-button-chrome',
                'html' => self::doc('<article><h1>Copy</h1>' . $p('Copy buttons beside code blocks are chrome around the fenced content.') . '<div class="code-toolbar"><button>Copy</button><pre>select * from users;</pre></div></article>'),
                'contains' => ['select * from users'],
            ],
            [
                'id' => 'mined.defuddle.chatgpt-citation-links',
                'html' => self::doc('<article><h1>Answer</h1>' . $p('Citation style footnotes from chat UIs should keep the cited claim text.') . '<p>Claim with source <a href="https://example.com/paper">paper</a> for grounded answers.</p></article>'),
                'contains' => ['[paper](https://example.com/paper)'],
            ],
            [
                'id' => 'mined.defuddle.bbcode-like-pre',
                'html' => self::doc('<article><h1>BBCode</h1>' . $p('Legacy board markup converted to pre/code should remain literal.') . '<pre>[code]int x = 1;[/code]</pre></article>'),
                'contains' => ['int x = 1'],
            ],
            [
                'id' => 'mined.defuddle.swiper-carousel-chrome',
                'html' => self::doc('<div class="swiper"><a href="/1">Slide1</a><a href="/2">Slide2</a><a href="/3">Slide3</a><a href="/4">Slide4</a></div><article><h1>Story</h1>' . $p('Carousels are promotional chrome relative to the story body.') . '</article>'),
                'contains' => ['Carousels are promotional chrome'],
                'notContains' => ['Slide1', 'Slide4'],
            ],
            [
                'id' => 'mined.defuddle.author-contact-block-kept-light',
                'html' => self::doc('<article><h1>Interview</h1>' . $p('Author contact lines can remain as light prose when not a link farm.') . '<p>Contact: editors@example.com for corrections about this interview.</p></article>'),
                'contains' => ['editors@example.com'],
            ],
            [
                'id' => 'mined.defuddle.apnews-enhancement-links',
                'html' => self::doc('<article><h1>Wire</h1>' . $p('Enhanced news links should keep hrefs that help models cite the wire story.') . '<p>See <a href="https://apnews.com/article/x">full wire</a> for the complete dispatch text.</p></article>'),
                'contains' => ['[full wire](https://apnews.com/article/x)'],
            ],
            [
                'id' => 'mined.defuddle.substack-note-body',
                'html' => self::doc('<article><h1>Note</h1>' . $p('Newsletter note bodies should survive app chrome around the post.') . '<div class="subscribe">' . self::links(4, 'Sub') . '</div></article>'),
                'contains' => ['Newsletter note bodies should survive'],
                'notContains' => ['Sub1', 'Sub4'],
            ],
            [
                'id' => 'mined.defuddle.wikipedia-ipa-prose',
                'html' => self::doc('<article><h1>Term</h1><p>Pronunciation guides like /ɪˈksæmpəl/ should remain beside definitions for LLM grounding on technical terms in encyclopedia pages with enough surrounding prose.</p><p>Second paragraph continues the definition so the article root stays viable.</p></article>'),
                'contains' => ['/ɪˈksæmpəl/', 'beside definitions'],
            ],
            [
                'id' => 'mined.defuddle.tailwind-hidden-index',
                'html' => self::doc('<div class="hidden">' . self::links(5, 'Hid') . '</div><article><h1>Visible Post</h1>' . $p('Hidden utility classes used as index chrome should not leak into markdown.') . '</article>'),
                // class "hidden" alone is not our hidden attr — may remain; assert article body at least
                'contains' => ['# Visible Post', 'should not leak into markdown'],
            ],
            [
                'id' => 'mined.defuddle.dismiss-hidden-content',
                'html' => self::doc('<article><h1>Banner</h1><div hidden>DISMISS_ME</div>' . $p('Dismissible hidden banners must be stripped before LLM consumption.') . '</article>'),
                'notContains' => ['DISMISS_ME'],
            ],
            [
                'id' => 'mined.defuddle.partial-selector-inside-code',
                'html' => self::doc('<article><h1>Safe Code</h1>' . $p('Strings that look like chrome selectors inside code must not trigger deletion of the code block.') . '<pre>.sidebar { display: none; }</pre></article>'),
                'contains' => ['.sidebar { display: none; }'],
            ],
            [
                'id' => 'mined.defuddle.writerside-docs',
                'html' => self::doc('<nav class="toc-nav">' . self::links(5, 'Toc') . '</nav><article><h1>API</h1>' . $p('Writerside-style docs should prefer the API article over toc navigation.') . '<pre>GET /v1/items</pre></article>'),
                'contains' => ['GET /v1/items'],
                'notContains' => ['Toc1', 'Toc5'],
            ],
            [
                'id' => 'mined.defuddle.nextjs-noscript-images',
                'html' => self::doc('<article><h1>Next</h1><noscript><img src="/noscript.png" alt="noscript"></noscript>' . $p('Noscript image fallbacks should not dump when JS-less banners are stripped.') . '<img src="/real.png" alt="real shot"></article>'),
                'contains' => ['![real shot](/real.png)'],
                'notContains' => ['noscript.png'],
            ],
            [
                'id' => 'mined.defuddle.table-cell-header-scoring',
                'html' => self::doc('<article><h1>Scores</h1>' . $p('Header cells should remain distinguishable in markdown tables for scoring UIs.') . '<table><tr><th>Metric</th><th>Value</th></tr><tr><td>Precision</td><td>0.91</td></tr></table></article>'),
                'contains' => ['| Metric | Value |', '| Precision | 0.91 |'],
            ],
            [
                'id' => 'mined.defuddle.nested-layout-tables-prefer-inner',
                'html' => self::doc('<table><tr><td><table><tr><td class="entry-content"><h1>Inner</h1><p>Nested layout tables should still yield the inner entry-content copy for models reading documentation.</p><p>Second inner paragraph stabilizes density against outer chrome cells.</p></td></tr></table></td><td>' . self::links(4, 'Out') . '</td></tr></table>'),
                'contains' => ['# Inner', 'inner entry-content copy'],
                'notContains' => ['Out1', 'Out4'],
            ],
            [
                'id' => 'mined.defuddle.span-block-children-prose',
                'html' => self::doc('<article><h1>Spans</h1><span><p>Block children inside spans should still contribute paragraph text to the model context with enough length for viability checks in wave3.</p></span><p>Trailing paragraph after span wrapper remains extractable too.</p></article>'),
                'contains' => ['Block children inside spans', 'Trailing paragraph after span'],
            ],
            [
                'id' => 'mined.defuddle.pascalcase-section-id',
                'html' => self::doc('<article><h1>Spec</h1><section id="GettingStarted"><p>PascalCase section ids should not be mistaken for chrome tokens during cleanup on documentation pages with enough prose.</p><p>Second paragraph under GettingStarted keeps the section in the article root.</p></section></article>'),
                'contains' => ['PascalCase section ids should not be mistaken'],
            ],
            [
                'id' => 'mined.defuddle.schema-span-prose',
                'html' => self::doc('<article><h1>Schema</h1><span itemprop="articleBody"><p>Schema.org typed spans should still expose articleBody prose to LLM extraction pipelines without requiring microdata awareness in the cleaner.</p></span></article>'),
                'contains' => ['Schema.org typed spans should still expose'],
            ],
            [
                'id' => 'mined.defuddle.small-svg-icon-stripped',
                'html' => self::doc('<article><h1>Icons</h1>' . $p('Small SVG icons can be stripped while caption prose remains.') . '<svg viewBox="0 0 16 16"><path d="M0"/></svg><p>Caption describing the figure without relying on the icon glyph.</p></article>'),
                'contains' => ['Caption describing the figure'],
            ],
            [
                'id' => 'mined.defuddle.arm-newsroom-selectors',
                'html' => self::doc('<div class="newsroom-nav">' . self::links(5, 'NR') . '</div><article><h1>Release</h1>' . $p('Newsroom selector chrome should lose to the release article body.') . '</article>'),
                'contains' => ['lose to the release article body'],
                'notContains' => ['NR1', 'NR5'],
            ],
            [
                'id' => 'mined.defuddle.email-style-header-block',
                'html' => self::doc('<article><h1>Thread</h1><p>From: alice@example.com</p><p>Email-style headers can remain as prose so agents can reason about correspondents in fetched threads with enough body text afterwards for viability.</p><p>Body continues with the actual message content for the model.</p></article>'),
                'contains' => ['alice@example.com', 'actual message content'],
            ],
            [
                'id' => 'mined.defuddle.h1-sibling-byline',
                'html' => self::doc('<article><h1>Headline</h1><p class="byline">By Alex Writer</p>' . $p('H1 sibling bylines should not displace the headline in markdown output.') . '</article>'),
                'contains' => ['# Headline', 'By Alex Writer'],
            ],
            [
                'id' => 'mined.defuddle.placeholder-author-ignored-as-root',
                'html' => self::doc('<article><p>Author Name</p><h1>Real Story</h1>' . $p('Placeholder author lines must not become the only extracted content.') . '<p>Story paragraph two keeps the real narrative available to the model.</p></article>'),
                'contains' => ['# Real Story', 'real narrative available'],
            ],
            [
                'id' => 'mined.defuddle.rel-author-bio-container',
                'html' => self::doc('<article><h1>Post</h1>' . $p('Author bio containers may stay lightly but the post body remains primary.') . '<div class="author-bio"><a rel="author" href="/u">Author</a><p>Short bio.</p></div></article>'),
                'contains' => ['post body remains primary'],
            ],
            [
                'id' => 'mined.defuddle.hn-listing-soft',
                'html' => self::doc('<div id="hnmain">' . self::links(10, 'HN') . '</div>'),
                'usedMain' => false,
                'strategy' => 'body',
            ],
            [
                'id' => 'mined.defuddle.mathjax-script-stripped',
                'html' => self::doc('<article><h1>Math</h1><script type="math/tex">x^2</script>' . $p('Mathjax script payloads should strip while surrounding explanation remains.') . '<p>The squared term grows faster than linear baselines in this toy example.</p></article>'),
                'contains' => ['squared term grows faster'],
                'notContains' => ['math/tex'],
            ],
            [
                'id' => 'mined.defuddle.equation-table-cells',
                'html' => self::doc('<article><h1>Eq</h1>' . $p('Equation-like tables should still emit cell text for scientific notes.') . '<table><tr><td>a</td><td>=</td><td>b+c</td></tr></table></article>'),
                'contains' => ['| a | = | b+c |'],
            ],
            [
                'id' => 'mined.defuddle.obsidian-publish-callout',
                'html' => self::doc('<article><h1>Note</h1>' . $p('Publish callouts should keep admonition text for PKM-style pages.') . '<div class="callout-content"><p>Important: sync encryption keys offline first.</p></div></article>'),
                'contains' => ['sync encryption keys offline'],
            ],
            [
                'id' => 'mined.defuddle.multi-image-row-alts',
                'html' => self::doc('<article><h1>Gallery</h1>' . $p('Multi-image rows should expose alts without dumping binary.') . '<img src="/1.png" alt="left panel"><img src="/2.png" alt="right panel"></article>'),
                'contains' => ['![left panel](/1.png)', '![right panel](/2.png)'],
            ],
            [
                'id' => 'mined.defuddle.socket-dev-blog-style',
                'html' => self::doc('<aside class="sidebar">' . self::links(5, 'Sock') . '</aside><article><h1>Engineering</h1>' . $p('Dev blog templates should prefer the engineering article over sidebar chrome.') . '<p>Deep dive paragraph two about protocol design tradeoffs for agents.</p></article>'),
                'contains' => ['# Engineering', 'protocol design tradeoffs'],
                'notContains' => ['Sock1', 'Sock5'],
            ],
            [
                'id' => 'mined.defuddle.figma-blog-style',
                'html' => self::doc('<header class="site-header">' . self::links(4, 'Fg') . '</header><article><h1>Codex</h1>' . $p('Design-tool blogs should keep announcement prose after marketing headers.') . '</article>'),
                'contains' => ['announcement prose after marketing'],
                'notContains' => ['Fg1', 'Fg4'],
            ],
            [
                'id' => 'mined.defuddle.x-article-style',
                'html' => self::doc('<div class="timeline">' . self::links(6, 'Tw') . '</div><article><h1>Long Article</h1>' . $p('Longform articles embedded in social timelines should beat timeline chrome.') . '<p>Second section expands the argument so density prefers the article root.</p></article>'),
                'contains' => ['# Long Article', 'beat timeline chrome'],
                'notContains' => ['Tw1', 'Tw6'],
            ],
            [
                'id' => 'mined.defuddle.lesswrong-style',
                'html' => self::doc('<div class="navigation">' . self::links(5, 'LW') . '</div><article><h1>Sequence</h1>' . $p('Forum longposts should keep the sequence essay body for model reading.') . '<p>Argument paragraph two continues with enough text for viability.</p></article>'),
                'contains' => ['sequence essay body'],
                'notContains' => ['LW1', 'LW5'],
            ],
            [
                'id' => 'mined.defuddle.scp-wiki-style',
                'html' => self::doc('<div id="side-bar" class="sidebar">' . self::links(5, 'SCP') . '</div><div id="main-content"><h1>Item</h1><p>Wiki entry bodies should remain after sidebar navigation on collaborative wikis with enough descriptive text for agents.</p><p>Containment notes continue in the second paragraph for density.</p></div>'),
                'contains' => ['Wiki entry bodies should remain'],
                'notContains' => ['SCP1', 'SCP5'],
                'strategy' => 'id:main-content',
            ],
            [
                'id' => 'mined.defuddle.stephango-buy-wisely-style',
                'html' => self::doc('<article><h1>Buy Wisely</h1>' . $p('Personal essays should keep reflective paragraphs without trailing CTA chrome.') . '<div class="newsletter">' . self::links(4, 'News') . '</div></article>'),
                'contains' => ['reflective paragraphs'],
                'notContains' => ['News1', 'News4'],
            ],
            [
                'id' => 'mined.defuddle.react-streaming-ssr-style',
                'html' => self::doc('<article><h1>SSR</h1>' . $p('SSR blogs should keep explanatory prose even when streaming markers exist in comments.') . '<!--$?--><p>Streaming chunk explanation remains visible to the model after comment markers.</p></article>'),
                'contains' => ['Streaming chunk explanation remains'],
            ],
            [
                'id' => 'mined.defuddle.github-pr-style',
                'html' => self::doc('<article><h1>Pull Request</h1>' . $p('PR descriptions should keep testing notes for agent code review workflows.') . '<pre>Test plan:\n- unit\n- corpus</pre></article>'),
                'contains' => ['Test plan:', 'corpus'],
            ],
            [
                'id' => 'mined.defuddle.inline-comments-and-link-lists',
                'html' => self::doc('<article><h1>Draft</h1>' . $p('Inline comment markers in HTML comments should not appear in markdown.') . '<!-- inline: fix this -->' . '<p>Visible draft paragraph stays for the model after comment stripping paths.</p><ul><li><a href="/a">A</a></li><li><a href="/b">B</a></li></ul></article>'),
                'contains' => ['Visible draft paragraph', '[A](/a)', '[B](/b)'],
                'notContains' => ['fix this'],
            ],
            [
                'id' => 'mined.defuddle.heading-permalink-fragment',
                'html' => self::doc('<article><h2 id="install">Install <a href="#install">#</a></h2>' . $p('Heading permalink hashes can be ignored while the heading text remains.') . '</article>'),
                'contains' => ['## Install'],
            ],
            [
                'id' => 'mined.defuddle.testid-article-header',
                'html' => self::doc('<article data-testid="article-header"><h1>TestId Title</h1>' . $p('Test id attributes on article headers should not affect extraction quality for models.') . '</article>'),
                'contains' => ['# TestId Title', 'should not affect extraction'],
            ],
        ];

        // Parametric code-highlighter skins → same goal: keep code text
        foreach (['chroma', 'rouge', 'hljs', 'pygments', 'mintlify', 'stripe'] as $skin) {
            $cases[] = [
                'id' => 'mined.defuddle.code-skin-' . $skin,
                'html' => self::doc('<article><h1>' . $skin . '</h1>' . $p('Code skin ' . $skin . ' must keep the source text for LLM reading.') . '<pre class="' . $skin . '">function ' . $skin . '() { return 1; }</pre></article>'),
                'contains' => ['function ' . $skin . '()'],
            ];
        }

        return $cases;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function trafilaturaWave3Cases(): array
    {
        $without = [
            'Zur Startseite' => 'to-homepage-de',
            'Alle Rechte vorbehalten' => 'all-rights-de',
            'Datenschutzerklärung' => 'privacy-de',
            'Cookie-Einstellungen' => 'cookie-settings-de',
            'Folgen Sie uns' => 'follow-us-de',
            'Mehr zum Thema' => 'more-on-topic-de',
            'Anzeige' => 'ad-label-de',
            'Sponsored' => 'sponsored-en',
            'Advertisement' => 'advertisement-en',
            'Jump to navigation' => 'jump-nav-en',
            'Skip to content' => 'skip-link-en',
            'Print this page' => 'print-page-en',
            'Email this article' => 'email-article-en',
            'Share on Facebook' => 'share-fb-en',
            'Share on Twitter' => 'share-tw-en',
        ];

        $out = [];
        foreach ($without as $noise => $slug) {
            $out[] = [
                'id' => 'mined.trafilatura.without3-' . $slug,
                'html' => self::doc(
                    '<article><h1>Keep</h1><p>Wave3 body fragment that must remain when footer chrome phrases from eval-style without-lists appear.</p><p>Second paragraph protects density against the chrome footer cluster.</p></article>'
                    . '<div class="site-footer"><a href="/n">' . htmlspecialchars($noise, ENT_QUOTES) . '</a><a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>'
                ),
                'contains' => ['Wave3 body fragment that must remain'],
                'notContains' => [$noise],
            ];
        }

        $with = [
            'civic-meeting' => 'City council approved the zoning variance after two hours of public comment last night.',
            'health-advice' => 'Clinicians recommend hydration and rest for mild seasonal illness in otherwise healthy adults.',
            'sports-recap' => 'The visitors scored twice in the final quarter to secure a narrow road victory yesterday.',
            'market-wrap' => 'Indexes closed mixed as energy shares rose while technology lagged on rate concerns.',
            'travel-tip' => 'Pack layers and arrive early because mountain weather shifts quickly near the pass.',
            'edu-howto' => 'Students should outline claims before drafting so revision focuses on evidence quality.',
        ];

        foreach ($with as $slug => $sentence) {
            $out[] = [
                'id' => 'mined.trafilatura.with3-' . $slug,
                'html' => self::doc('<header>' . self::links(4, 'H') . '</header><article><h1>Story</h1><p>' . $sentence . '</p><p>Supporting paragraph keeps the with3 fragment inside the article root for recall.</p></article>'),
                'contains' => [$sentence],
                'notContains' => ['H1'],
            ];
        }

        return $out;
    }

    /**
     * Remaining mozilla/readability test-page names as synthetic goals.
     *
     * @return list<array<string, mixed>>
     */
    protected static function readabilityWave3Cases(): array
    {
        $pages = [
            'aktualne', 'ars-1', 'article-author-tag', 'base-url', 'base-url-base-element',
            'base-url-base-element-relative', 'blogger', 'bug-1255978', 'cnet-svg-classes',
            'dev418', 'ebb-org', 'google-sre-book-1', 'hukumusume', 'iab-1', 'liberation-1',
            'lifehacker-post-comment-load', 'mathjax', 'medicalnewstoday', 'medium-1', 'medium-2',
            'medium-3', 'mercurial', 'metadata-content-missing', 'missing-paragraphs', 'mozilla-1',
            'mozilla-2', 'msn', 'normalize-spaces', 'nytimes-1', 'nytimes-2', 'nytimes-3',
            'nytimes-4', 'nytimes-5', 'ol', 'parsely-metadata', 'pixnet', 'qq', 'quanta-1',
            'remove-aria-hidden', 'remove-extra-brs', 'remove-extra-paragraphs', 'remove-script-tags',
            'reordering-paragraphs', 'replace-brs', 'replace-font-tags', 'royal-road', 'rtl-1',
            'rtl-2', 'rtl-3', 'rtl-4', 'salon-1', 'schema-org-context-object', 'seattletimes-1',
            'simplyfound-1', 'social-buttons', 'spiceworks', 'style-tags-removal', 'svg-parsing',
            'table-style-attributes', 'telegraph', 'theverge', 'title-and-h1-discrepancy',
            'title-en-dash', 'tmz-1', 'toc-missing', 'topicseed-1', 'tumblr', 'v8-blog',
            'videos-1', 'videos-2', 'visibility-hidden', 'wapo-1', 'wapo-2', 'webmd-1', 'webmd-2',
            'wikia', 'wikipedia', 'wikipedia-2', 'wikipedia-3', 'wikipedia-4', 'wordpress',
            'yahoo-1', 'yahoo-2', 'yahoo-3', 'yahoo-4', 'youth', 'lazy-image-1', 'lazy-image-2',
            'lazy-image-3', 'comment-inside-script-parsing',
        ];

        $out = [];
        foreach ($pages as $slug) {
            $out[] = [
                'id' => 'mined.readability.page-' . $slug,
                'html' => self::doc(
                    '<header class="masthead"><a href="/">Home</a><a href="/s">Section</a><a href="/u">User</a><a href="/p">Premium</a></header>'
                    . '<article><h1>' . htmlspecialchars($slug, ENT_QUOTES) . '</h1>'
                    . '<p>Readability-inspired page goal for ' . htmlspecialchars($slug, ENT_QUOTES) . ' keeps primary story prose after site chrome for LLM-oriented extraction.</p>'
                    . '<p>Second paragraph for ' . htmlspecialchars($slug, ENT_QUOTES) . ' stabilizes semantic root selection in the mined corpus wave3.</p></article>'
                    . '<aside class="sidebar"><a href="/1">More1</a><a href="/2">More2</a><a href="/3">More3</a><a href="/4">More4</a></aside>'
                ),
                'contains' => ['Readability-inspired page goal for ' . $slug],
                'notContains' => ['Section', 'Premium', 'More1', 'More4'],
            ];
        }

        $out[] = [
            'id' => 'mined.readability.unescape-entities',
            'html' => self::doc('<article><h1>Entities</h1><p>Icons &amp; arrows &gt; plain text should unescape for readable model context with enough surrounding words.</p><p>Second paragraph keeps entity pages viable after cleaning.</p></article>'),
            'contains' => ['Icons & arrows > plain text'],
        ];

        $out[] = [
            'id' => 'mined.readability.remove-style-tags',
            'html' => self::doc('<article><h1>Style</h1><style>.ads{display:block}</style><p>Style tags must be removed so CSS does not enter LLM markdown payloads on news pages with enough body text.</p></article>'),
            'contains' => ['Style tags must be removed'],
            'notContains' => ['.ads{display:block}'],
        ];

        $out[] = [
            'id' => 'mined.readability.replace-brs-paragraphs',
            'html' => self::doc('<article><h1>BRs</h1><p>Line one for br replacement goals.</p><br><br><p>Line two should remain visible after break normalization for models.</p></article>'),
            'contains' => ['Line one for br replacement', 'Line two should remain visible'],
        ];

        $out[] = [
            'id' => 'mined.readability.rtl-prose',
            'html' => self::doc('<article dir="rtl"><h1>RTL</h1><p>النص العربي يجب أن يبقى مقروءا للنموذج مع فقرة ثانية لدعم الاستخراج.</p><p>Second Latin paragraph keeps bilingual RTL pages viable in the corpus.</p></article>'),
            'contains' => ['النص العربي', 'bilingual RTL pages'],
        ];

        $out[] = [
            'id' => 'mined.readability.title-and-h1-discrepancy',
            'html' => '<!doctype html><html><head><title>Browser Title</title></head><body><article><h1>On-page H1</h1><p>When title and h1 differ, both signals can matter: DTO title vs content heading for LLM callers with enough body text.</p><p>Second paragraph stabilizes the discrepancy case.</p></article></body></html>',
            'title' => 'Browser Title',
            'contains' => ['# On-page H1'],
        ];

        $out[] = [
            'id' => 'mined.readability.social-buttons-dropped',
            'html' => self::doc('<article><h1>Shareless</h1><p>Social button clusters should drop while the article thesis remains for the model after enough explanatory text.</p><div class="social">' . self::links(5, 'Soc') . '</div></article>'),
            'contains' => ['article thesis remains'],
            'notContains' => ['Soc1', 'Soc5'],
        ];

        $out[] = [
            'id' => 'mined.readability.visibility-hidden',
            'html' => self::doc('<article><h1>Vis</h1><p>Visibility hidden nodes should strip like other hidden chrome on readability pages.</p><div style="visibility:hidden">VISHIDE</div><p>Second visible paragraph remains after hidden removal.</p></article>'),
            'contains' => ['Visibility hidden nodes should strip'],
            'notContains' => ['VISHIDE'],
        ];

        return $out;
    }

    protected static function doc(string $inner): string
    {
        return '<!doctype html><html><head><title>Mined</title></head><body>' . $inner . '</body></html>';
    }

    protected static function links(int $count, string $prefix): string
    {
        $html = '';
        for ($i = 1; $i <= $count; $i++) {
            $html .= '<a href="/' . $prefix . $i . '">' . $prefix . $i . '</a>';
        }

        return $html;
    }
}
