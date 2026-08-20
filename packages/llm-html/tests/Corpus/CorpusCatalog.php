<?php

declare(strict_types=1);

namespace Switon\LlmHtml\Tests\Corpus;

use LogicException;

/**
 * Behavioral corpus for LLM-oriented HTML→MD (goals, not upstream algorithms).
 *
 * @return list<array{
 *   id: string,
 *   html: string,
 *   contains?: list<string>,
 *   notContains?: list<string>,
 *   title?: string,
 *   description?: string,
 *   strategy?: string,
 *   usedMain?: bool
 * }>
 */
class CorpusCatalog
{
    public static function cases(): array
    {
        $cases = array_merge(
            self::landmarkCases(),
            self::cleanerHardTokenCases(),
            self::cleanerSoftAndHiddenCases(),
            self::rootSelectionCases(),
            self::markdownStructureCases(),
            self::linkSafetyCases(),
            self::metaCases(),
            self::fallbackCases(),
            self::matrixProseCases(),
            CorpusMinedCatalog::cases(),
        );

        $ids = [];
        foreach ($cases as $case) {
            if (isset($ids[$case['id']])) {
                throw new LogicException('Duplicate corpus id: ' . $case['id']);
            }
            $ids[$case['id']] = true;
        }

        return $cases;
    }

    /** @return list<array<string, mixed>> */
    protected static function landmarkCases(): array
    {
        $out = [];
        foreach (['nav', 'aside'] as $tag) {
            $out[] = [
                'id' => "landmark.drop-{$tag}",
                'html' => self::doc("<{$tag}><a href='/x'>Chrome{$tag}</a></{$tag}><article><p>Keep landmark body copy for the model with enough length here.</p></article>"),
                'contains' => ['Keep landmark body copy'],
                'notContains' => ["Chrome{$tag}"],
                'usedMain' => true,
            ];
        }

        $out[] = [
            'id' => 'landmark.drop-page-header',
            'html' => self::doc('<header><a href="/">Home</a><a href="/b">Blog</a></header><article><p>Article body after page header removal stays available to the LLM.</p></article>'),
            'contains' => ['Article body after page header'],
            'notContains' => ['Home', 'Blog'],
        ];

        $out[] = [
            'id' => 'landmark.keep-article-header',
            'html' => self::doc('<article><header><h1>Doc Title</h1></header><p>In-article header titles must survive for documentation pages and LLM reading.</p></article>'),
            'contains' => ['# Doc Title', 'In-article header titles'],
            'usedMain' => true,
            'strategy' => 'article',
        ];

        $out[] = [
            'id' => 'landmark.keep-article-footer-note',
            'html' => self::doc('<article><p>Body text for footer retention check with enough characters to stay viable.</p><footer><p>See also appendix notes inside the article footer.</p></footer></article>'),
            'contains' => ['appendix notes inside the article footer'],
        ];

        $out[] = [
            'id' => 'landmark.drop-page-footer',
            'html' => self::doc('<article><p>Main copy above the site footer should remain after chrome stripping for agents.</p></article><footer><a href="/law">Terms</a><a href="/privacy">Privacy</a></footer>'),
            'contains' => ['Main copy above the site footer'],
            'notContains' => ['Terms', 'Privacy'],
        ];

        return $out;
    }

    /** @return list<array<string, mixed>> */
    protected static function cleanerHardTokenCases(): array
    {
        $tokens = [
            'sidebar', 'ad', 'ads', 'advert', 'banner', 'breadcrumb',
            'cookie', 'navbar', 'navigation', 'menubar', 'widget',
            'masthead', 'newsletter', 'popup', 'modal', 'toolbar',
            'pagination', 'disqus', 'comments', 'site-header', 'site-footer',
            'disclaimer', 'legal-notice', 'risk-tip', 'risk-warning',
        ];

        $out = [];
        foreach ($tokens as $token) {
            $out[] = [
                'id' => "cleaner.hard-{$token}",
                'html' => self::doc(
                    '<article><p>Primary article prose remains after hard chrome token cleanup for LLM context windows.</p></article>'
                    . '<div class="' . $token . '"><a href="/c">Noise' . $token . '</a><a href="/d">More' . $token . '</a></div>'
                ),
                'contains' => ['Primary article prose remains'],
                'notContains' => ['Noise' . $token, 'More' . $token],
            ];
        }

        $out[] = [
            'id' => 'cleaner.iframe-form-svg',
            'html' => self::doc('<article><p>Readable body with embeds removed so models do not ingest chrome payloads.</p><iframe src="/e"></iframe><form><button>go</button></form><svg><text>x</text></svg></article>'),
            'contains' => ['Readable body with embeds removed'],
            'notContains' => ['go'],
        ];

        return $out;
    }

    /** @return list<array<string, mixed>> */
    protected static function cleanerSoftAndHiddenCases(): array
    {
        return [
            [
                'id' => 'cleaner.hidden-attr',
                'html' => self::doc('<article><p>Visible prose for hidden-attribute stripping checks in the corpus.</p><div hidden>HIDDENATTR</div></article>'),
                'contains' => ['Visible prose for hidden-attribute'],
                'notContains' => ['HIDDENATTR'],
            ],
            [
                'id' => 'cleaner.aria-hidden',
                'html' => self::doc('<article><p>Visible prose for aria-hidden stripping checks in the corpus suite.</p><div aria-hidden="true">ARIAHIDDEN</div></article>'),
                'contains' => ['Visible prose for aria-hidden'],
                'notContains' => ['ARIAHIDDEN'],
            ],
            [
                'id' => 'cleaner.display-none',
                'html' => self::doc('<article><p>Visible prose for display:none stripping checks in the corpus suite.</p><div style="display:none">DISPLAYNONE</div></article>'),
                'contains' => ['Visible prose for display:none'],
                'notContains' => ['DISPLAYNONE'],
            ],
            [
                'id' => 'cleaner.soft-related-prose-kept',
                'html' => self::doc('<article><p>Main article establishes the root for soft-token tolerance checks.</p><div class="related"><p>Related prose paragraph that is not a link farm should remain available to the model.</p></div></article>'),
                'contains' => ['Related prose paragraph that is not a link farm'],
            ],
            [
                'id' => 'cleaner.soft-related-links-dropped',
                'html' => self::doc('<article><p>Main article establishes the root for soft-token link-farm drops.</p><div class="related">' . self::links(6, 'Rel') . '</div></article>'),
                'contains' => ['Main article establishes the root for soft-token link-farm'],
                'notContains' => ['Rel1', 'Rel6'],
            ],
            [
                'id' => 'cleaner.soft-promo-links-dropped',
                'html' => self::doc('<article><p>Main article establishes the root for promo link-farm drops.</p><div class="promo">' . self::links(6, 'Pro') . '</div></article>'),
                'notContains' => ['Pro1', 'Pro6'],
            ],
            [
                'id' => 'cleaner.soft-share-links-dropped',
                'html' => self::doc('<article><p>Main article establishes the root for share link-farm drops.</p><div class="share">' . self::links(5, 'Sh') . '</div></article>'),
                'notContains' => ['Sh1', 'Sh5'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected static function rootSelectionCases(): array
    {
        $out = [
            [
                'id' => 'root.article',
                'html' => self::doc('<div>' . self::links(5, 'N') . '</div><article><p>Article landmark should win over a surrounding link farm for extraction.</p></article>'),
                'strategy' => 'article',
                'usedMain' => true,
                'contains' => ['Article landmark should win'],
                'notContains' => ['N1'],
            ],
            [
                'id' => 'root.main',
                'html' => self::doc('<div>' . self::links(5, 'N') . '</div><main><p>Main landmark should win over a surrounding link farm for extraction.</p></main>'),
                'strategy' => 'main',
                'usedMain' => true,
                'contains' => ['Main landmark should win'],
            ],
            [
                'id' => 'root.role-main',
                'html' => self::doc('<div class="menu">' . self::links(4, 'M') . '</div><div role="main"><p>Role main landmark should win for agent pages without article tags present.</p><p>Second paragraph keeps viability above the short-text floor.</p></div>'),
                'strategy' => 'role:main',
                'usedMain' => true,
                'contains' => ['Role main landmark should win'],
                'notContains' => ['M1'],
            ],
            [
                'id' => 'root.id-content',
                'html' => self::doc('<div id="content"><p>Id content landmark should be selected when article and main are absent from the page.</p><p>Second paragraph supports viability for id-based roots.</p></div>'),
                'strategy' => 'id:content',
                'usedMain' => true,
                'contains' => ['Id content landmark should be selected'],
            ],
            [
                'id' => 'root.id-main-content',
                'html' => self::doc('<div id="main-content"><p>Id main-content landmark should be selected for portal-style markup without semantic tags.</p><p>Second paragraph supports viability for this id variant.</p></div>'),
                'strategy' => 'id:main-content',
                'contains' => ['Id main-content landmark should be selected'],
            ],
            [
                'id' => 'root.entry-content-class',
                'html' => self::doc('<div class="entry-content"><p>Entry-content class should act as a semantic-ish root for blog templates common on the web.</p><p>Second paragraph supports scoring and viability thresholds.</p></div>'),
                'usedMain' => true,
                'contains' => ['Entry-content class should act'],
            ],
            [
                'id' => 'root.skip-linkfarm-article',
                'html' => self::doc('<article>' . self::links(8, 'L') . '</article><div class="entry-content"><p>Real denser copy should win when the article tag is only a link farm shell.</p><p>Second paragraph locks the density preference for this regression.</p></div>'),
                'contains' => ['Real denser copy should win'],
                'notContains' => ['L1', 'L8'],
            ],
            [
                'id' => 'root.best-of-two-articles',
                'html' => self::doc('<article><p>Tiny teaser only.</p></article><article class="entry-content"><h1>Winner</h1><p>Longer structured article should outrank a tiny teaser article for LLM extraction quality.</p><p>Second paragraph adds length and paragraph bonuses to the score.</p></article>'),
                'contains' => ['# Winner', 'Longer structured article'],
                'notContains' => ['Tiny teaser only'],
            ],
            [
                'id' => 'root.dense-main-copy',
                'html' => self::doc('<div>' . self::links(6, 'T') . '</div><div class="main-copy"><p>Density scoring should prefer main-copy blocks when landmarks are missing entirely from markup.</p><p>Second paragraph keeps the densest-block path stable in the corpus.</p></div>'),
                'usedMain' => true,
                'contains' => ['Density scoring should prefer main-copy'],
                'notContains' => ['T1'],
            ],
        ];

        foreach (['primary', 'mainContent'] as $id) {
            $out[] = [
                'id' => "root.id-{$id}",
                'html' => self::doc('<div id="' . $id . '"><p>Id ' . $id . ' landmark variant should be selectable as a content root for LLM prep.</p><p>Second paragraph supports viability for id ' . $id . '.</p></div>'),
                'strategy' => 'id:' . $id,
                'contains' => ['Id ' . $id . ' landmark variant'],
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    protected static function markdownStructureCases(): array
    {
        $out = [];
        foreach ([1, 2, 3, 4] as $level) {
            $tag = 'h' . $level;
            $marks = str_repeat('#', min($level, 4));
            $out[] = [
                'id' => "md.heading-{$tag}",
                'html' => self::doc("<article><{$tag}>Heading{$level}</{$tag}><p>Paragraph under heading level {$level} for markdown fidelity checks.</p></article>"),
                'contains' => ["{$marks} Heading{$level}", "Paragraph under heading level {$level}"],
            ];
        }

        $out[] = [
            'id' => 'md.ul-ol-blockquote-hr',
            'html' => self::doc('<article><p>Intro for list and quote rendering.</p><ul><li>U1</li><li>U2</li></ul><ol><li>O1</li><li>O2</li></ol><blockquote><p>Quoted</p></blockquote><hr><p>After hr</p></article>'),
            'contains' => ['- U1', '- U2', '1. O1', '2. O2', '> Quoted', '---', 'After hr'],
        ];

        $out[] = [
            'id' => 'md.nested-ul',
            'html' => self::doc('<article><p>Nested list intro paragraph for structure checks.</p><ul><li>Parent<ul><li>Child</li></ul></li><li>Sibling</li></ul></article>'),
            'contains' => ['- Parent', 'Child', '- Sibling'],
        ];

        $out[] = [
            'id' => 'md.table-with-th',
            'html' => self::doc('<article><p>Table intro paragraph for markdown table checks.</p><table><tr><th>A</th><th>B</th></tr><tr><td>1</td><td>2</td></tr></table></article>'),
            'contains' => ['| A | B |', '| 1 | 2 |'],
        ];

        $out[] = [
            'id' => 'md.table-without-thead',
            'html' => self::doc('<article><p>Table intro paragraph for headerless table checks.</p><table><tr><td>Name</td><td>Val</td></tr><tr><td>x</td><td>9</td></tr></table></article>'),
            'contains' => ['| Name | Val |', '| x | 9 |'],
        ];

        $out[] = [
            'id' => 'md.pre-code-inline',
            'html' => self::doc("<article><p>Use <code>foo()</code> inline.</p><pre>line1;\nline2</pre></article>"),
            'contains' => ['`foo()`', "```\nline1;\nline2\n```"],
        ];

        $out[] = [
            'id' => 'md.strong-em',
            'html' => self::doc('<article><p>Text with <strong>bold</strong> and <em>italic</em> markers for models.</p></article>'),
            'contains' => ['**bold**', '*italic*'],
        ];

        $out[] = [
            'id' => 'md.img-with-alt',
            'html' => self::doc('<article><p>Image with alt text should be preserved as markdown for grounded reading.</p><img src="/a.png" alt="diagram"></article>'),
            'contains' => ['![diagram](/a.png)'],
        ];

        $out[] = [
            'id' => 'md.img-without-alt-dropped',
            'html' => self::doc('<article><p>Image without alt should not emit empty markdown image syntax into the context.</p><img src="/a.png" alt=""></article>'),
            'notContains' => ['![](/a.png)'],
        ];

        return $out;
    }

    /** @return list<array<string, mixed>> */
    protected static function linkSafetyCases(): array
    {
        $schemes = [
            'javascript:void(0)' => 'jsvoid',
            'javascript:alert(1)' => 'jsalert',
            'data:text/html,x' => 'datahtml',
            'mailto:a@b.c' => 'mail',
            '#top' => 'hash',
        ];

        $out = [];
        foreach ($schemes as $href => $label) {
            $out[] = [
                'id' => 'link.skip-' . $label,
                'html' => self::doc('<article><p>Safe link filtering keeps dangerous schemes out of markdown hrefs for the model.</p><a href="' . $href . '">' . $label . '</a><a href="/ok">ok</a></article>'),
                'contains' => [$label, '[ok](/ok)'],
                'notContains' => ['](' . $href . ')'],
            ];
        }

        $out[] = [
            'id' => 'link.keep-relative',
            'html' => self::doc('<article><p>Relative hrefs should be kept as-is so callers can resolve against the fetch URL.</p><a href="/docs/a">docs</a></article>'),
            'contains' => ['[docs](/docs/a)'],
        ];

        $out[] = [
            'id' => 'link.keep-absolute-https',
            'html' => self::doc('<article><p>Absolute https links should remain clickable references inside model context.</p><a href="https://example.com/a">abs</a></article>'),
            'contains' => ['[abs](https://example.com/a)'],
        ];

        return $out;
    }

    /** @return list<array<string, mixed>> */
    protected static function metaCases(): array
    {
        return [
            [
                'id' => 'meta.title-tag',
                'html' => '<!doctype html><html><head><title>Title Tag</title></head><body><article><p>Body for title tag meta extraction checks with enough text length.</p></article></body></html>',
                'title' => 'Title Tag',
            ],
            [
                'id' => 'meta.og-title-fallback',
                'html' => '<!doctype html><html><head><meta property="og:title" content="OG Title"></head><body><article><p>Body for og title fallback checks with enough text length present.</p></article></body></html>',
                'title' => 'OG Title',
            ],
            [
                'id' => 'meta.description',
                'html' => '<!doctype html><html><head><title>T</title><meta name="description" content="Desc A"></head><body><article><p>Body for description meta extraction checks with enough text length.</p></article></body></html>',
                'description' => 'Desc A',
            ],
            [
                'id' => 'meta.og-description-fallback',
                'html' => '<!doctype html><html><head><meta property="og:description" content="OG Desc"></head><body><article><p>Body for og description fallback checks with enough text length present.</p></article></body></html>',
                'description' => 'OG Desc',
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    protected static function fallbackCases(): array
    {
        return [
            [
                'id' => 'fallback.chrome-only-body',
                'html' => self::doc('<div>' . self::links(6, 'A') . '</div><div>' . self::links(5, 'B') . '</div>'),
                'usedMain' => false,
                'strategy' => 'body',
                'notContains' => ['[A1](', '[B1]('],
            ],
            [
                'id' => 'fallback.emptyish-html',
                'html' => '<html><body></body></html>',
                'usedMain' => false,
            ],
            [
                'id' => 'fallback.script-only-noise',
                'html' => self::doc('<script>var x=1;</script><style>.x{}</style><article><p>After script style removal the article prose should still be extractable cleanly.</p></article>'),
                'contains' => ['After script style removal'],
                'notContains' => ['var x=1'],
            ],
        ];
    }

    /**
     * Parametric prose × chrome matrix to thicken coverage without hand-written novels.
     *
     * @return list<array<string, mixed>>
     */
    protected static function matrixProseCases(): array
    {
        $topics = [
            'API overview', 'Install guide', 'Config reference', 'Error codes', 'Auth flow',
            'Rate limits', 'Webhook events', 'Migration notes', 'Changelog entry', 'FAQ item',
            'Security model', 'Privacy policy excerpt', 'Pricing footnote', 'SDK quickstart', 'CLI usage',
            'Docker deploy', 'K8s probe', 'Cache semantics', 'Queue retry', 'Schema evolution',
        ];
        $chromes = [
            'nav' => static fn (string $noise): string => '<nav><a href="/n">' . $noise . '</a></nav>',
            'aside' => static fn (string $noise): string => '<aside><a href="/n">' . $noise . '</a></aside>',
            'sidebar' => static fn (string $noise): string => '<div class="sidebar"><a href="/n">' . $noise . '</a></div>',
            'widget' => static fn (string $noise): string => '<div class="widget"><a href="/n">' . $noise . '</a></div>',
        ];

        $out = [];
        $i = 0;
        foreach ($topics as $topic) {
            foreach ($chromes as $chromeName => $buildChrome) {
                $i++;
                $noise = 'Noise' . $chromeName . $i;
                $body = "Corpus matrix case about {$topic} needs enough paragraph text so the article root stays viable for LLM extraction quality gates.";
                $out[] = [
                    'id' => 'matrix.' . $i . '-' . $chromeName . '-' . preg_replace('/\W+/', '-', strtolower($topic)),
                    'html' => self::doc($buildChrome($noise) . '<article><h1>' . $topic . '</h1><p>' . $body . '</p><p>Second paragraph for ' . $topic . ' matrix stability.</p></article>'),
                    'contains' => ['# ' . $topic, $body],
                    'notContains' => [$noise],
                    'usedMain' => true,
                ];
            }
        }

        // Heading × list × table small grid
        foreach (['alpha', 'beta', 'gamma', 'delta', 'epsilon'] as $idx => $name) {
            $n = $idx + 1;
            $out[] = [
                'id' => "matrix.md-grid-{$name}",
                'html' => self::doc("<article><h2>Grid {$name}</h2><p>Grid prose {$name} for combined markdown structure coverage in the corpus.</p><ul><li>{$name}-a</li><li>{$name}-b</li></ul><ol><li>{$name}-1</li></ol><table><tr><th>K</th><th>V</th></tr><tr><td>{$name}</td><td>{$n}</td></tr></table></article>"),
                'contains' => ["## Grid {$name}", "- {$name}-a", "1. {$name}-1", "| K | V |", '| ' . $name . ' | ' . $n . ' |'],
            ];
        }

        return $out;
    }

    protected static function doc(string $bodyInner): string
    {
        return '<!doctype html><html><head><title>Corpus</title></head><body>' . $bodyInner . '</body></html>';
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
