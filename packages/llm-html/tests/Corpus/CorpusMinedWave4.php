<?php

declare(strict_types=1);

namespace Switon\LlmHtml\Tests\Corpus;

/**
 * Wave4 + edge capability cases (kept separate so CorpusMinedCatalog stays editable).
 *
 * @return list<array{id: string, html: string, contains?: list<string>, notContains?: list<string>, title?: string, strategy?: string, usedMain?: bool}>
 */
class CorpusMinedWave4
{
    public static function cases(): array
    {
        return array_merge(
            self::defuddleWave4Cases(),
            self::trafilaturaWave4Cases(),
            self::edgeCapabilityCases(),
        );
    }

    /** @return list<array<string, mixed>> */
    protected static function defuddleWave4Cases(): array
    {
        $p = static fn (string $msg): string => '<p>' . $msg . ' Supporting sentence keeps article viability for wave4 mined cases.</p>';

        $cases = [
            [
                'id' => 'mined.defuddle.footnotes-hr-numbered',
                'html' => self::doc('<article><h1>Essay</h1>' . $p('Numbered footnotes after hr should keep reference text for citations.') . '<hr><p>1. First source about reliability engineering practices in production.</p><p>2. Second source about incident response timelines and handoffs.</p></article>'),
                'contains' => ['First source about reliability', 'Second source about incident'],
            ],
            [
                'id' => 'mined.defuddle.footnotes-named-anchor',
                'html' => self::doc('<article><h1>Notes</h1><p>See the note <a href="#note-a">here</a> inside a longer paragraph that stays extractable for models reading technical essays carefully today.</p><p id="note-a">Named anchor note body explains the cited claim in more detail for the model.</p></article>'),
                'contains' => ['Named anchor note body explains'],
            ],
            [
                'id' => 'mined.defuddle.wp-block-footnotes',
                'html' => self::doc('<article><h1>WP</h1>' . $p('WordPress footnote blocks should keep footnote prose.') . '<div class="wp-block-footnotes"><ol><li>WP footnote body with enough text for retention.</li></ol></div></article>'),
                'contains' => ['WP footnote body with enough text'],
            ],
            [
                'id' => 'mined.defuddle.hugo-admonitions',
                'html' => self::doc('<article><h1>Hugo</h1>' . $p('Hugo admonitions should keep tip text for docs.') . '<div class="admonition note"><p>Tip: pin dependency versions in CI.</p></div></article>'),
                'contains' => ['pin dependency versions in CI'],
            ],
            [
                'id' => 'mined.defuddle.obsidian-callouts-w4',
                'html' => self::doc('<article><h1>PKM</h1>' . $p('Obsidian callouts should keep callout body text.') . '<div class="callout"><div class="callout-title">Warning</div><div class="callout-content"><p>Back up the vault before migrating plugins.</p></div></div></article>'),
                'contains' => ['Back up the vault before migrating'],
            ],
            [
                'id' => 'mined.defuddle.lightbox-image-alt',
                'html' => self::doc('<article><h1>Lightbox</h1>' . $p('Lightbox wrappers should still expose image alts.') . '<a href="/full.png"><img src="/thumb.png" alt="thumb view"></a></article>'),
                'contains' => ['![thumb view](/thumb.png)'],
            ],
            [
                'id' => 'mined.defuddle.srcset-uses-src',
                'html' => self::doc('<article><h1>Srcset</h1>' . $p('Srcset images should prefer concrete src for markdown.') . '<img src="/a.png" srcset="/a.png 1x, /a2.png 2x" alt="responsive shot"></article>'),
                'contains' => ['![responsive shot](/a.png)'],
            ],
            [
                'id' => 'mined.defuddle.figure-content-wrapper',
                'html' => self::doc('<article><h1>Figure wrap</h1>' . $p('Figure wrappers should keep caption-adjacent alts.') . '<div class="figure-wrapper"><img src="/g.png" alt="graph"><p>Graph caption explaining the trend.</p></div></article>'),
                'contains' => ['![graph](/g.png)', 'Graph caption explaining'],
            ],
            [
                'id' => 'mined.defuddle.image-dedup-prefer-alt',
                'html' => self::doc('<article><h1>Dedup</h1>' . $p('Duplicate decorative images without alt can be ignored while informative alts stay.') . '<img src="/a.png" alt=""><img src="/a.png" alt="chart of growth"></article>'),
                'contains' => ['![chart of growth](/a.png)'],
                'notContains' => ['![](/a.png)'],
            ],
            [
                'id' => 'mined.defuddle.empty-p-br-tolerated',
                'html' => self::doc('<article><h1>Empty</h1><p></p><br><p>Meaningful paragraph after empty nodes should survive cleanup for LLM reading with enough words present here.</p><p>Second meaningful paragraph stabilizes the empty-node case.</p></article>'),
                'contains' => ['Meaningful paragraph after empty nodes'],
            ],
            [
                'id' => 'mined.defuddle.farsi-zwnj-kept',
                'html' => self::doc('<article><h1>FA</h1><p>متن فارسی با فاصله‌ی صفر باید برای مدل قابل خواندن بماند و پاراگراف دوم هم کافی باشد.</p><p>Latin follow-up paragraph keeps bilingual Farsi pages viable in the corpus wave4.</p></article>'),
                'contains' => ['متن فارسی', 'bilingual Farsi pages'],
            ],
            [
                'id' => 'mined.defuddle.entry-point-js-article-content',
                'html' => self::doc('<div id="js-article-content"><h1>JS Entry</h1><p>Some sites mark article roots with js article content ids that should still be selectable as content landmarks for agents fetching documentation pages today.</p><p>Second paragraph under the js entry point keeps viability above floors.</p></div>'),
                'contains' => ['js article content ids', 'Second paragraph under the js entry'],
            ],
            [
                'id' => 'mined.defuddle.chatgpt-post-thought',
                'html' => self::doc('<article><h1>Thought</h1>' . $p('Post-thought content after reasoning traces should remain for answers.') . '<p>Final answer paragraph states the conclusion clearly for the user and model.</p></article>'),
                'contains' => ['Final answer paragraph states the conclusion'],
            ],
            [
                'id' => 'mined.defuddle.extractor-citations-list',
                'html' => self::doc('<article><h1>Citations</h1>' . $p('Citation lists should keep titles and urls for grounded agent answers.') . '<ol><li><a href="https://ex.com/1">Source One</a></li><li><a href="https://ex.com/2">Source Two</a></li></ol></article>'),
                'contains' => ['[Source One](https://ex.com/1)', '[Source Two](https://ex.com/2)'],
            ],
            [
                'id' => 'mined.defuddle.gmail-thread-style',
                'html' => self::doc('<article><h1>Re: Schedule</h1><p>On Mon, Alice wrote enough quoted context for thread understanding in agent inboxes.</p><blockquote><p>Can we meet Tuesday afternoon to discuss the migration checklist items together?</p></blockquote><p>Reply body confirms Wednesday works better for the team sync.</p></article>'),
                'contains' => ['> Can we meet Tuesday', 'Wednesday works better'],
            ],
            [
                'id' => 'mined.defuddle.arxiv-cross-ref-prose',
                'html' => self::doc('<article><h1>Paper</h1>' . $p('Cross references like Section 3 should stay in scientific prose.') . '<p>As shown in Section 3.2, the bound improves when noise decreases for the estimator under study here.</p></article>'),
                'contains' => ['Section 3.2', 'bound improves when noise decreases'],
            ],
            [
                'id' => 'mined.defuddle.lean-code-blocks',
                'html' => self::doc('<article><h1>Lean</h1>' . $p('Proof assistant code blocks should keep source literally.') . '<pre>theorem id (a : α) : a = a := rfl</pre></article>'),
                'contains' => ['theorem id', 'rfl'],
            ],
            [
                'id' => 'mined.defuddle.paulgraham-table-layout',
                'html' => self::doc('<table><tr><td><img src="/x.gif" alt=""></td><td class="post-body"><h1>Maker Schedule</h1><p>Essay body inside classic table layouts should still be recoverable for longform reading by models that summarize essays.</p><p>Second paragraph continues the maker versus manager distinction clearly enough.</p></td></tr></table>'),
                'contains' => ['# Maker Schedule', 'maker versus manager'],
            ],
            [
                'id' => 'mined.defuddle.blogger-two-column',
                'html' => self::doc('<table><tr><td id="main-content"><h1>Blog</h1><p>Two-column blogger layouts should prefer the main content cell over the link column for LLM extraction quality on older templates.</p><p>Second paragraph in main content keeps density above the side column.</p></td><td class="sidebar">' . self::links(5, 'B') . '</td></tr></table>'),
                'contains' => ['prefer the main content cell'],
                'notContains' => ['B1', 'B5'],
                'strategy' => 'id:main-content',
            ],
            [
                'id' => 'mined.defuddle.single-column-table',
                'html' => self::doc('<table><tr><td><article><h1>Single</h1><p>Single-column table wrappers around articles should not prevent body extraction for models consuming older HTML email-ish layouts with enough text.</p><p>Second paragraph confirms the article inside the table cell wins.</p></article></td></tr></table>'),
                'contains' => ['# Single', 'inside the table cell wins'],
            ],
            [
                'id' => 'mined.defuddle.date-adjacent-category-link',
                'html' => self::doc('<article><p><a href="/cat">Engineering</a> · 2024-01-02</p><h1>Ship</h1>' . $p('Category links beside dates should not replace the headline.') . '</article>'),
                'contains' => ['# Ship', 'not replace the headline'],
            ],
            [
                'id' => 'mined.defuddle.header-date-above-title',
                'html' => self::doc('<article><header><p>2024-08-20</p><h1>Dated Title</h1></header>' . $p('Dates above titles inside article headers should keep both signals.') . '</article>'),
                'contains' => ['2024-08-20', '# Dated Title'],
            ],
            [
                'id' => 'mined.defuddle.multi-script-values',
                'html' => self::doc('<article><h1>バイリンガル</h1><p>日本語の本文はモデル向けに残すべきで、英語の第二段落も十分に長くする必要があります for bilingual extraction checks.</p><p>English paragraph two keeps multi-script pages viable in wave4 corpus coverage.</p></article>'),
                'contains' => ['日本語の本文', 'multi-script pages viable'],
            ],
            [
                'id' => 'mined.defuddle.standardize-span-as-paragraph',
                'html' => self::doc('<article><h1>Spans</h1><span>Span-only body text should still appear in markdown so models do not lose content when sites avoid p tags for styling reasons in modern CSS layouts.</span><span>Second span continues with enough characters for viability under span-as-paragraph goals.</span></article>'),
                'contains' => ['Span-only body text should still appear', 'Second span continues'],
            ],
            [
                'id' => 'mined.defuddle.katex-annotation-text',
                'html' => self::doc('<article><h1>KaTeX</h1>' . $p('If math renders to annotation text, keep that text for models.') . '<span class="katex"><annotation encoding="application/x-tex">a^2+b^2=c^2</annotation></span><p>Pythagorean relation is summarized in the annotation above for grounding.</p></article>'),
                'contains' => ['a^2+b^2=c^2', 'Pythagorean relation'],
            ],
            [
                'id' => 'mined.defuddle.mathml-text-kept',
                'html' => self::doc('<article><h1>MathML</h1>' . $p('MathML textual fallbacks should remain readable.') . '<math><mtext>x squared plus y</mtext></math><p>Explanation continues after the math element for viability.</p></article>'),
                'contains' => ['x squared plus y'],
            ],
            [
                'id' => 'mined.defuddle.mdn-array-style-w4',
                'html' => self::doc('<nav class="sidebar">' . self::links(6, 'Ref') . '</nav><main><h1>Array.prototype.flat</h1>' . $p('Reference pages should keep method prose and signatures.') . '<pre>array.flat()</pre></main>'),
                'contains' => ['Array.prototype.flat', 'array.flat()'],
                'notContains' => ['Ref1', 'Ref6'],
                'strategy' => 'main',
            ],
            [
                'id' => 'mined.defuddle.12grams-longform',
                'html' => self::doc('<article><h1>Papers</h1>' . $p('Long reading-list posts should keep list commentary.') . '<ol><li>Paper A — why it matters for systems design today in practice.</li><li>Paper B — follow-on work that tightens the bounds further.</li></ol></article>'),
                'contains' => ['1. Paper A', '2. Paper B'],
            ],
            [
                'id' => 'mined.defuddle.cp4space-math-essay',
                'html' => self::doc('<article><h1>Algebra</h1>' . $p('Math essays should keep explanatory paragraphs even when equations are images.') . '<img src="/eq.png" alt="Jordan identity"><p>Discussion of the identity continues with implications for the model.</p></article>'),
                'contains' => ['![Jordan identity](/eq.png)', 'Discussion of the identity'],
            ],
            [
                'id' => 'mined.defuddle.issues-header-subtitle-p',
                'html' => self::doc('<article><header><h1>Title</h1><p class="subtitle">Subtitle explaining the scope of the document for readers and models alike with clarity.</p></header>' . $p('Subtitle paragraphs under headers should remain.') . '</article>'),
                'contains' => ['Subtitle explaining the scope', 'Subtitle paragraphs under headers'],
            ],
        ];

        foreach (['easy-footnotes', 'sidenote', 'orgmode-css-sidenotes', 'pulldown-cmark'] as $flavor) {
            $cases[] = [
                'id' => 'mined.defuddle.footnote-flavor-' . $flavor,
                'html' => self::doc('<article><h1>' . $flavor . '</h1>' . $p('Footnote flavor ' . $flavor . ' should keep note prose for LLM citations.') . '<div class="footnote"><p>' . $flavor . ' note body with enough detail for retention goals.</p></div></article>'),
                'contains' => [$flavor . ' note body with enough detail'],
            ];
        }

        return $cases;
    }

    /** @return list<array<string, mixed>> */
    protected static function trafilaturaWave4Cases(): array
    {
        $without = [
            'Verwandte Artikel' => 'related-articles-de',
            'Kommentare schreiben' => 'write-comments-de',
            'Jetzt registrieren' => 'register-now-de',
            'Kostenlos testen' => 'free-trial-de',
            'Top-Artikel' => 'top-articles-de',
            'Meistgelesen' => 'most-read-de',
            'Podcast abonnieren' => 'podcast-subscribe-de',
            'App herunterladen' => 'download-app-de',
            'Recommended for you' => 'recommended-for-you',
            'Trending now' => 'trending-now',
            'Listen to this article' => 'listen-article',
            'Open in app' => 'open-in-app',
            'Sign in to comment' => 'sign-in-comment',
            'Join the conversation' => 'join-conversation',
            'Subscribe for unlimited access' => 'subscribe-unlimited',
            'Already a subscriber?' => 'already-subscriber',
            'Gift this article' => 'gift-article',
            'Reprint this' => 'reprint-this',
            'Report a typo' => 'report-typo',
            'Corrections' => 'corrections-link',
        ];

        $out = [];
        foreach ($without as $noise => $slug) {
            $out[] = [
                'id' => 'mined.trafilatura.without4-' . $slug,
                'html' => self::doc(
                    '<article><h1>Keep</h1><p>Wave4 body must remain while eval-style without phrases appear only in footer chrome clusters around the article.</p><p>Second paragraph protects density against the without4 chrome footer.</p></article>'
                    . '<div class="site-footer"><a href="/n">' . htmlspecialchars($noise, ENT_QUOTES) . '</a><a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>'
                ),
                'contains' => ['Wave4 body must remain'],
                'notContains' => [$noise],
            ];
        }

        $with = [
            'weather-plain' => 'Temperatures stay mild with light rain expected overnight across the coastal counties.',
            'obituary-plain' => 'She taught literature for three decades and mentored students who later became writers.',
            'recipe-stew' => 'Simmer the stew for forty minutes until the potatoes yield easily to a fork.',
            'howto-bike' => 'Adjust the derailleur until the chain moves cleanly across all rear cogs under load.',
            'opinion-city' => 'The city should fund libraries before another stadium expansion drains the budget.',
            'science-brief' => 'Researchers observed stable qubit coherence beyond the previous laboratory record.',
            'business-earnings' => 'Revenue grew twelve percent as cloud subscriptions offset weaker hardware sales.',
            'culture-review' => 'The novel balances intimate character work with a wider portrait of migration.',
        ];

        foreach ($with as $slug => $sentence) {
            $out[] = [
                'id' => 'mined.trafilatura.with4-' . $slug,
                'html' => self::doc('<div class="toolbar">' . self::links(4, 'T') . '</div><article><h1>Story</h1><p>' . $sentence . '</p><p>Supporting paragraph keeps the with4 fragment inside the selected article root.</p></article>'),
                'contains' => [$sentence],
                'notContains' => ['T1'],
            ];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    protected static function edgeCapabilityCases(): array
    {
        $cases = [
            [
                'id' => 'edge.dl-dt-dd',
                'html' => self::doc('<article><h1>Glossary</h1><p>Definition lists should expose terms and descriptions to the model somehow via text.</p><dl><dt>TTL</dt><dd>Time to live for cache entries in seconds.</dd><dt>SLA</dt><dd>Service level agreement targets for availability.</dd></dl></article>'),
                'contains' => ['TTL', 'Time to live', 'SLA', 'Service level agreement'],
            ],
            [
                'id' => 'edge.details-summary',
                'html' => self::doc('<article><h1>FAQ</h1><p>Details elements should keep summary and body text for FAQ pages.</p><details><summary>How to install?</summary><p>Run the setup script from the repository root directory carefully.</p></details></article>'),
                'contains' => ['How to install?', 'Run the setup script'],
            ],
            [
                'id' => 'edge.ol-start-attribute',
                'html' => self::doc('<article><h1>Steps</h1><p>Ordered lists may restart numbering; items should still appear for the model.</p><ol start="3"><li>Third step continues the procedure with enough wording.</li><li>Fourth step finishes the procedure for the corpus edge case.</li></ol></article>'),
                'contains' => ['Third step continues', 'Fourth step finishes'],
            ],
            [
                'id' => 'edge.pre-with-tabs',
                'html' => self::doc("<article><h1>Tabs</h1><p>Preformatted tabs should not collapse away inside fences.</p><pre>a\tb\ncd</pre></article>"),
                'contains' => ["a\tb", 'cd'],
            ],
            [
                'id' => 'edge.nested-blockquotes',
                'html' => self::doc('<article><h1>Quotes</h1><p>Nested quotes should remain nested enough for dialogue understanding.</p><blockquote><p>Outer claim about the system design tradeoff under discussion.</p><blockquote><p>Inner clarification from the reviewer about latency budgets.</p></blockquote></blockquote></article>'),
                'contains' => ['Outer claim about the system', 'Inner clarification from the reviewer'],
            ],
            [
                'id' => 'edge.mixed-ul-ol',
                'html' => self::doc('<article><h1>Mixed</h1><p>Mixed list types should both render.</p><ul><li>Bullet</li></ul><ol><li>Numbered</li></ol></article>'),
                'contains' => ['- Bullet', '1. Numbered'],
            ],
            [
                'id' => 'edge.table-pipe-escape',
                'html' => self::doc('<article><h1>Pipes</h1><p>Table cells with pipes should escape for markdown safety.</p><table><tr><th>A</th><th>B</th></tr><tr><td>x|y</td><td>z</td></tr></table></article>'),
                'contains' => ['| x\\|y | z |'],
            ],
            [
                'id' => 'edge.multiple-h1-keep-all',
                'html' => self::doc('<article><h1>First</h1><p>First section prose for multi-h1 documents that still belong to one article root for LLM reading sessions today.</p><h1>Second</h1><p>Second section prose continues under another h1 without dropping either heading from output.</p></article>'),
                'contains' => ['# First', '# Second'],
            ],
            [
                'id' => 'edge.deep-div-nesting',
                'html' => self::doc('<article><div><div><div><div><p>Deeply nested divs should not hide paragraph text from the renderer when the article root is selected for model context windows.</p><p>Second deep paragraph keeps viability high enough despite nesting depth.</p></div></div></div></div></article>'),
                'contains' => ['Deeply nested divs should not hide', 'Second deep paragraph'],
            ],
            [
                'id' => 'edge.comment-nodes-stripped',
                'html' => self::doc('<article><h1>Comments</h1><!-- SECRET_HTML_COMMENT --><p>HTML comments must not appear in markdown output for LLM safety and cleanliness with enough body text here.</p></article>'),
                'contains' => ['HTML comments must not appear'],
                'notContains' => ['SECRET_HTML_COMMENT'],
            ],
            [
                'id' => 'edge.font-tag-prose',
                'html' => self::doc('<article><h1>Font</h1><p><font color="red">Font tags should not prevent text extraction for models reading older HTML email clones with enough surrounding explanation.</font></p><p>Second paragraph after font tags remains.</p></article>'),
                'contains' => ['Font tags should not prevent'],
            ],
            [
                'id' => 'edge.strike-and-del',
                'html' => self::doc('<article><h1>Edits</h1><p>Keep <del>removed claim</del> and <s>old price</s> visible enough that models see edit history cues in documents with additional context sentences included here.</p></article>'),
                'contains' => ['removed claim', 'old price'],
            ],
            [
                'id' => 'edge.picture-img-fallback',
                'html' => self::doc('<article><h1>Picture</h1><p>Picture elements should expose the img fallback alt.</p><picture><source srcset="/a.webp"><img src="/a.jpg" alt="fallback shot"></picture><p>Second paragraph after picture keeps viability.</p></article>'),
                'contains' => ['![fallback shot](/a.jpg)'],
            ],
            [
                'id' => 'edge.protocol-relative-link',
                'html' => self::doc('<article><h1>Proto</h1><p>Protocol-relative links can remain as-is for callers to resolve.</p><p>See <a href="//example.com/a">cdn</a> for the asset reference used in this example paragraph.</p></article>'),
                'contains' => ['[cdn](//example.com/a)'],
            ],
            [
                'id' => 'edge.uppercase-tags',
                'html' => self::doc('<ARTICLE><H1>Upper</H1><P>Uppercase tags from legacy HTML should still extract for models with a second paragraph ensuring viability under uppercase parsing quirks.</P><P>Second uppercase paragraph remains visible after normalization.</P></ARTICLE>'),
                'contains' => ['# Upper', 'Uppercase tags from legacy'],
            ],
            [
                'id' => 'edge.long-url-kept',
                'html' => self::doc('<article><h1>URL</h1><p>Long query URLs should remain intact for citation.</p><p><a href="https://example.com/path?x=1&amp;y=2&amp;z=3">query link</a> inside a longer paragraph that establishes viability for long href retention checks in the corpus.</p></article>'),
                'contains' => ['[query link](https://example.com/path?x=1&y=2&z=3)'],
            ],
            [
                'id' => 'edge.center-tag-prose',
                'html' => self::doc('<article><h1>Center</h1><center><p>Centered legacy prose should still be readable to the model with a second centered sentence for viability on old marketing pages.</p></center></article>'),
                'contains' => ['Centered legacy prose should still be readable'],
            ],
            [
                'id' => 'edge.abbr-title-text',
                'html' => self::doc('<article><h1>Abbr</h1><p>The <abbr title="HyperText Markup Language">HTML</abbr> abbreviation should keep visible text for models even if title tooltips are ignored in markdown rendering today.</p><p>Second paragraph stabilizes abbreviation handling.</p></article>'),
                'contains' => ['HTML'],
            ],
            [
                'id' => 'edge.pre-with-tabs-retained',
                'html' => self::doc("<article><h1>Tabs2</h1><p>Another pre tab retention check for fence fidelity.</p><pre>\tindented</pre></article>"),
                'contains' => ["\tindented"],
            ],
            [
                'id' => 'edge.hr-between-sections',
                'html' => self::doc('<article><h1>A</h1><p>Section A prose for horizontal rule separation checks with enough length for viability in edge cases.</p><hr><h2>B</h2><p>Section B prose after the rule should remain for the model reading multi-section notes.</p></article>'),
                'contains' => ['---', '## B', 'Section B prose after the rule'],
            ],
        ];

        foreach (['zh', 'ja', 'ko', 'ar', 'ru'] as $lang) {
            $samples = [
                'zh' => '中文技术文档应当在清洗后仍保持完整段落，方便模型阅读第二段也要足够长。',
                'ja' => '日本語の技術文書は抽出後も段落が残り、第二段落で十分に長くする必要があります。',
                'ko' => '한국어 기술 문서는 추출 후에도 문단이 남아야 하며 두 번째 문단도 충분히 깁니다.',
                'ar' => 'يجب أن تبقى الفقرات العربية بعد التنظيف مع فقرة ثانية طويلة بما يكفي للنموذج.',
                'ru' => 'Русский технический текст должен сохраняться после очистки, а второй абзац делает корень устойчивым.',
            ];
            $cases[] = [
                'id' => 'edge.lang-' . $lang,
                'html' => self::doc('<article><h1>' . $lang . '</h1><p>' . $samples[$lang] . '</p><p>Latin anchor paragraph for ' . $lang . ' keeps bilingual extraction checks stable in wave4 edges.</p></article>'),
                'contains' => [mb_substr($samples[$lang], 0, 12), 'Latin anchor paragraph for ' . $lang],
            ];
        }

        return $cases;
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
