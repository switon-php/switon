<?php

declare(strict_types=1);

namespace Switon\LlmHtml\Tests\Corpus;

/**
 * Final bulk sweep: one synthetic goal per defuddle fixture name + extra trafilatura/edge cases.
 *
 * Does not vendor upstream HTML — only names → LLM-oriented expectations.
 */
class CorpusMinedWave5
{
    public static function cases(): array
    {
        return array_merge(
            self::defuddleFixtureSweep(),
            self::trafilaturaBulkWithout(),
            self::trafilaturaBulkWith(),
            self::agentFetchEdges(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function defuddleFixtureSweep(): array
    {
        /** @var list<string> $names */
        $names = require __DIR__ . '/defuddle-fixture-names.php';
        $out = [];
        foreach ($names as $name) {
            $out[] = self::synthesizeDefuddle($name);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function synthesizeDefuddle(string $name): array
    {
        $id = 'mined.defuddle.fx.' . preg_replace('/[^a-z0-9]+/i', '-', $name);
        $family = explode('--', $name, 2)[0];
        $token = substr(hash('sha256', $name), 0, 8);
        $body = "Fixture goal {$name} ({$token}): primary prose must remain for LLM context after chrome cleanup with enough length.";
        $body2 = "Second paragraph for {$name} stabilizes root selection in the defuddle fixture sweep.";

        return match (true) {
            str_starts_with($family, 'code') => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><p>{$body}</p><pre>code_{$token}() { return 1; }</pre><p>{$body2}</p></article>"),
                'contains' => ["code_{$token}()", $body],
            ],
            $family === 'comments' || str_contains($name, 'comments--') => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><p>{$body}</p><p>{$body2}</p></article><div class=\"comments\"><p>COMMENT_NOISE_{$token}</p><a href=\"/c1\">c1</a><a href=\"/c2\">c2</a></div>"),
                'contains' => [$body],
                'notContains' => ["COMMENT_NOISE_{$token}"],
            ],
            str_starts_with($family, 'footnotes') || str_contains($name, 'footnote') => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><p>{$body}</p><div class=\"footnote\"><p>NOTE_{$token} expands the claim for citations.</p></div><p>{$body2}</p></article>"),
                'contains' => ["NOTE_{$token}", $body],
            ],
            str_starts_with($family, 'math') => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><p>{$body}</p><img src=\"/eq-{$token}.png\" alt=\"EQ_{$token}\"><p>{$body2}</p></article>"),
                'contains' => ["![EQ_{$token}](/eq-{$token}.png)", $body],
            ],
            $family === 'gated-content' || str_contains($name, 'cookie') || str_contains($name, 'subscribe') || str_contains($name, 'newsletter') => [
                'id' => $id,
                'html' => self::doc("<div class=\"cookie-banner consent\"><a href=\"/a\">Accept_{$token}</a><a href=\"/b\">B</a><a href=\"/c\">C</a><a href=\"/d\">D</a></div><article><h1>{$name}</h1><p>{$body}</p><p>{$body2}</p></article>"),
                'contains' => [$body],
                'notContains' => ["Accept_{$token}"],
            ],
            $family === 'related' || str_contains($name, 'related') || str_contains($name, 'trailing-related') || str_contains($name, 'social') => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><p>{$body}</p><p>{$body2}</p><div class=\"related social\"><a href=\"/1\">R1_{$token}</a><a href=\"/2\">R2</a><a href=\"/3\">R3</a><a href=\"/4\">R4</a><a href=\"/5\">R5</a></div></article>"),
                'contains' => [$body],
                'notContains' => ["R1_{$token}"],
            ],
            $family === 'metadata' || str_starts_with($family, 'eyebrow') || str_starts_with($family, 'headings') => [
                'id' => $id,
                'html' => self::doc("<article><p class=\"eyebrow\">Eyebrow_{$token}</p><h1>Headline_{$token}</h1><p>{$body}</p><p>{$body2}</p></article>"),
                'contains' => ["# Headline_{$token}", $body],
            ],
            $family === 'hidden' || str_contains($name, 'hidden') || str_contains($name, 'aria-hidden') => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><p>{$body}</p><div hidden>HID_{$token}</div><div style=\"visibility:hidden\">VIS_{$token}</div><p>{$body2}</p></article>"),
                'contains' => [$body],
                'notContains' => ["HID_{$token}", "VIS_{$token}"],
            ],
            $family === 'table-layout' || str_contains($name, 'table') => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><p>{$body}</p><table><tr><th>K_{$token}</th><th>V</th></tr><tr><td>a</td><td>1</td></tr></table><p>{$body2}</p></article>"),
                'contains' => ["| K_{$token} | V |", '| a | 1 |', $body],
            ],
            $family === 'listing' => [
                'id' => $id,
                'html' => self::doc('<div class="listing">' . self::links(8, 'L' . $token) . '</div>'),
                'usedMain' => false,
                'strategy' => 'body',
            ],
            $family === 'selectors' || $family === 'scoring' || $family === 'small-images' || $family === 'custom-elements' => [
                'id' => $id,
                'html' => self::doc("<div class=\"widget sidebar\">" . self::links(5, 'W' . $token) . "</div><article><h1>{$name}</h1><p>{$body}</p><p>{$body2}</p></article>"),
                'contains' => [$body],
                'notContains' => ['W' . $token . '1'],
            ],
            $family === 'author-share-widget' || $family === 'author-contact-block' || str_starts_with($name, 'author-') => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><p>{$body}</p><div class=\"share\">" . self::links(4, 'S' . $token) . "</div><p>{$body2}</p></article>"),
                'contains' => [$body],
                'notContains' => ['S' . $token . '1'],
            ],
            $family === 'elements' && str_contains($name, 'javascript') => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><p>{$body}</p><p><a href=\"javascript:void(0)\">js_{$token}</a> and <a href=\"/ok\">ok_{$token}</a></p><p>{$body2}</p></article>"),
                'contains' => ["js_{$token}", "[ok_{$token}](/ok)"],
                'notContains' => ['javascript:'],
            ],
            $family === 'elements' && str_contains($name, 'image') => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><p>{$body}</p><img src=\"/img-{$token}.png\" alt=\"ALT_{$token}\"><p>{$body2}</p></article>"),
                'contains' => ["![ALT_{$token}](/img-{$token}.png)", $body],
            ],
            $family === 'content-patterns' && str_contains($name, 'breadcrumb') => [
                'id' => $id,
                'html' => self::doc("<div class=\"breadcrumb\"><a href=\"/\">Home_{$token}</a><a href=\"/b\">B</a></div><article><h1>{$name}</h1><p>{$body}</p><p>{$body2}</p></article>"),
                'contains' => [$body],
                'notContains' => ["Home_{$token}"],
            ],
            $family === 'content-patterns' && (str_contains($name, 'toc') || str_contains($name, 'table-of-contents')) => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><nav class=\"toc-nav\">" . self::links(4, 'Toc' . $token) . "</nav><p>{$body}</p><p>{$body2}</p></article>"),
                'contains' => [$body],
                'notContains' => ['Toc' . $token . '1'],
            ],
            $family === 'issues' && str_contains($name, 'menu') => [
                'id' => $id,
                'html' => self::doc("<div id=\"menu\">" . self::links(5, 'M' . $token) . "</div><article><h1>{$name}</h1><p>{$body}</p><p>{$body2}</p></article>"),
                'contains' => [$body],
                'notContains' => ['M' . $token . '1'],
            ],
            $family === 'issues' && str_contains($name, 'hero') => [
                'id' => $id,
                'html' => self::doc("<div class=\"hero\">" . self::links(5, 'H' . $token) . "</div><article><h1>{$name}</h1><p>{$body}</p><p>{$body2}</p></article>"),
                'contains' => [$body],
                'notContains' => ['H' . $token . '1'],
            ],
            $family === 'general' && str_contains($name, 'wikipedia') => [
                'id' => $id,
                'html' => self::doc("<div class=\"sidebar\">" . self::links(5, 'Wiki' . $token) . "</div><div id=\"content\"><h1>{$name}</h1><p>{$body}</p><p>{$body2}</p></div>"),
                'contains' => [$body],
                'notContains' => ['Wiki' . $token . '1'],
                'strategy' => 'id:content',
            ],
            $family === 'general' && (str_contains($name, 'github') || str_contains($name, 'mdn') || str_contains($name, 'developer.mozilla')) => [
                'id' => $id,
                'html' => self::doc("<nav class=\"sidebar\">" . self::links(5, 'Doc' . $token) . "</nav><article><h1>{$name}</h1><p>{$body}</p><pre>sig_{$token}()</pre><p>{$body2}</p></article>"),
                'contains' => ["sig_{$token}()", $body],
                'notContains' => ['Doc' . $token . '1'],
            ],
            $family === 'extractor' || $family === 'entry-point' || $family === 'callouts' || $family === 'elementor' || $family === 'standardize' || $family === 'thin-section-before-see-also' => [
                'id' => $id,
                'html' => self::doc("<article><h1>{$name}</h1><p>{$body}</p><p>{$body2}</p></article>"),
                'contains' => [$body, $body2],
            ],
            default => [
                'id' => $id,
                'html' => self::doc(
                    '<header class="site-header">' . self::links(4, 'Nav' . $token) . '</header>'
                    . "<article><h1>{$name}</h1><p>{$body}</p><p>{$body2}</p></article>"
                    . '<aside class="sidebar">' . self::links(4, 'Side' . $token) . '</aside>'
                ),
                'contains' => [$body],
                'notContains' => ['Nav' . $token . '1', 'Side' . $token . '1'],
            ],
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function trafilaturaBulkWithout(): array
    {
        $phrases = [
            'Cookie Policy', 'Privacy Policy', 'Terms of Service', 'All rights reserved',
            'Follow us on', 'Share this story', 'Related stories', 'More from this author',
            'Read next', 'Editor\'s picks', 'Partner content', 'Paid partnership',
            'Advertisement feature', 'Continue reading', 'Log in to continue',
            'Create a free account', 'Download our app', 'Get the newsletter',
            'Breaking news alerts', 'Live updates', 'Watch the video',
            'Listen on Spotify', 'Shop the story', 'Buy now', 'Limited time offer',
            'Customer reviews', 'Write a review', 'Rate this page', 'Was this helpful?',
            'Feedback', 'Contact support', 'Help center', 'Site map',
            'Careers', 'Press room', 'Investor relations', 'Affiliate disclosure',
            'Do not sell my data', 'Your privacy choices', 'Manage cookies',
            'Consent preferences', 'Ad choices', 'Why did I see this ad?',
            'Sponsored content', 'Presented by', 'In association with',
            'From our partners', 'Promoted stories', 'Trending videos',
            'Most popular', 'Most commented', 'Most shared', 'Today\'s paper',
            'E-edition', 'Crossword', 'Sudoku', 'Horoscope', 'Weather widget',
            'Stock ticker', 'Market data delayed', 'Quotes delayed',
            'Sign up for Premium', 'Unlock this article', 'Members only',
            'Subscriber exclusive', 'Gift article', 'Print edition',
            'Reprint rights', 'Licensing', 'Syndication', 'Corrections policy',
            'Ethics policy', 'Community guidelines', 'Report abuse',
            'Block user', 'Mute notifications', 'Turn on notifications',
            'Add to favorites', 'Save for later', 'Add to list',
            'Send tip', 'SecureDrop', 'Whistleblower hotline',
            'Accessibility help', 'Text size', 'Dark mode', 'App settings',
        ];

        $out = [];
        foreach ($phrases as $i => $noise) {
            $slug = 'bulk' . $i;
            $out[] = [
                'id' => 'mined.trafilatura.bulk-without-' . $slug,
                'html' => self::doc(
                    '<article><h1>Keep</h1><p>Bulk without case ' . $i . ' keeps body prose while chrome phrase clusters sit in the footer only for eval-style recall tests.</p><p>Second paragraph for bulk without ' . $i . ' protects density scoring.</p></article>'
                    . '<div class="site-footer"><a href="/n">' . htmlspecialchars($noise, ENT_QUOTES) . '</a><a href="/a">A</a><a href="/b">B</a><a href="/c">C</a></div>'
                ),
                'contains' => ['Bulk without case ' . $i . ' keeps body prose'],
                'notContains' => [$noise],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function trafilaturaBulkWith(): array
    {
        $sentences = [
            'Municipal planners approved wider sidewalks after residents documented overcrowding near the station.',
            'Farmers reported earlier harvest dates as spring temperatures rose across the valley network.',
            'Engineers reduced p95 latency by batching disk writes without changing the public API contract.',
            'Clinicians advised masking in crowded clinics during the seasonal respiratory virus peak.',
            'The orchestra premiered a new concerto that blends traditional motifs with electronic textures.',
            'Prosecutors argued the documents showed intent, while the defense emphasized missing context.',
            'Students built a low-cost sensor network to map air quality around the campus perimeter.',
            'Sailors waited for a weather window before attempting the crossing under reefed sails.',
            'Chefs rested the dough overnight so fermentation could develop deeper flavor compounds.',
            'Astronomers stacked exposures to reveal faint tidal streams around the dwarf galaxy.',
            'Negotiators drafted a ceasefire text that sequenced withdrawals with monitoring teams.',
            'Designers removed unused tokens from the theme to shrink the CSS payload substantially.',
            'Historians compared diaries to show how ordinary workers experienced the rail strike.',
            'Gardeners mulched beds early to retain moisture through the expected midsummer drought.',
            'Pilots briefed a go-around procedure after winds shifted across the shorter runway.',
            'Poets workshopped line breaks until the stanza carried the turn without explanation.',
            'Bankers stressed deposits under a severe rate-shock scenario for the quarterly exam.',
            'Rangers closed the trail overnight after detecting landslide debris above the switchbacks.',
            'Coders deleted the flaky sleep in CI and replaced it with a deterministic wait handle.',
            'Voters lined up early as poll workers opened additional stations in the civic center.',
            'Divers recorded warmer water temperatures at depth than the seasonal average suggested.',
            'Pharmacists flagged an interaction and suggested an alternative dosing schedule instead.',
            'Curators rehung the gallery so sightlines emphasized the smaller mixed-media works.',
            'Drivers diverted to the service road while crews repaired the collapsed culvert overnight.',
            'Teachers paced the unit so revision happened before the timed writing assessment.',
            'Analysts trimmed growth forecasts after export orders softened for a second month.',
            'Volunteers sorted donations by size so distribution could start before nightfall.',
            'Researchers open-sourced the dataset with licenses that allow commercial fine-tuning.',
            'Mechanics torqued the fasteners to spec after replacing the worn suspension bushings.',
            'Editors cut the lede so the conflict appeared before the background chronology.',
        ];

        $out = [];
        foreach ($sentences as $i => $sentence) {
            $out[] = [
                'id' => 'mined.trafilatura.bulk-with-' . $i,
                'html' => self::doc(
                    '<nav>' . self::links(4, 'N' . $i) . '</nav>'
                    . '<article><h1>Story ' . $i . '</h1><p>' . $sentence . '</p><p>Supporting paragraph for bulk with ' . $i . ' keeps the fragment inside the article root.</p></article>'
                ),
                'contains' => [$sentence],
                'notContains' => ['N' . $i . '1'],
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected static function agentFetchEdges(): array
    {
        $cases = [
            [
                'id' => 'edge.agent.json-ld-ignored-as-script',
                'html' => self::doc('<script type="application/ld+json">{"@type":"Article","headline":"LD_ONLY"}</script><article><h1>Real</h1><p>JSON-LD in script tags should not replace visible article prose for agent fetch pipelines with enough body text present here.</p><p>Second paragraph keeps the visible root preferred over structured data blobs.</p></article>'),
                'contains' => ['# Real', 'visible article prose'],
                'notContains' => ['LD_ONLY'],
            ],
            [
                'id' => 'edge.agent.amp-boilerplate-stripped',
                'html' => self::doc('<style amp-boilerplate>body{-webkit-animation:-amp-start 8s}</style><article><h1>AMP</h1><p>AMP boilerplate CSS must not enter markdown while the story body remains for LLM summarization with a second paragraph for viability.</p></article>'),
                'contains' => ['story body remains'],
                'notContains' => ['-amp-start'],
            ],
            [
                'id' => 'edge.agent.paywall-teaser-plus-body',
                'html' => self::doc('<article><h1>Paywall</h1><p>Public teaser paragraph available without login for agents that only see the free HTML snapshot today.</p><div class="subscribe">' . self::links(4, 'Pay') . '</div><p>If more body leaked below the CTA, agents should still prefer readable paragraphs over subscribe chrome.</p></article>'),
                'contains' => ['Public teaser paragraph', 'prefer readable paragraphs'],
                'notContains' => ['Pay1', 'Pay4'],
            ],
            [
                'id' => 'edge.agent.print-only-hidden',
                'html' => self::doc('<article><h1>Print</h1><p>Screen-visible prose should remain.</p><div style="display:none" class="print-only">PRINT_ONLY_' . 'X' . '</div><p>Second visible paragraph after print-only hidden blocks remains for models.</p></article>'),
                'contains' => ['Screen-visible prose should remain'],
                'notContains' => ['PRINT_ONLY_X'],
            ],
            [
                'id' => 'edge.agent.cookie-wall-above-fold',
                'html' => self::doc('<div class="cookieconsent">' . self::links(5, 'CK') . '</div><main><h1>Policy</h1><p>Content under cookie walls should still extract once the HTML includes the main landmark body for agent tools.</p><p>Second main paragraph stabilizes landmark selection.</p></main>'),
                'contains' => ['Content under cookie walls'],
                'notContains' => ['CK1', 'CK5'],
                'strategy' => 'main',
            ],
            [
                'id' => 'edge.agent.infinite-scroll-chunk',
                'html' => self::doc('<article><h1>Chunk</h1><p>First chunk of an infinite-scroll article should stand alone as useful LLM context even without later pages loaded by the fetcher.</p><p>Second chunk paragraph continues the argument within the same HTML response body.</p></article>'),
                'contains' => ['First chunk of an infinite-scroll', 'Second chunk paragraph'],
            ],
            [
                'id' => 'edge.agent.soft-404-message',
                'html' => self::doc('<article><h1>Not Found</h1><p>Soft 404 pages still return 200 with a not-found explanation that agents should see instead of empty chrome shells around the message.</p><p>Second paragraph may include search suggestions without becoming a link farm if copy dominates.</p></article>'),
                'contains' => ['not-found explanation that agents should see'],
            ],
            [
                'id' => 'edge.agent.multi-main-pick-best',
                'html' => self::doc('<main><p>Tiny main.</p></main><main class="entry-content"><h1>Better Main</h1><p>When multiple main landmarks exist, denser entry-content should win for agent reading quality on messy templates.</p><p>Second paragraph in the better main locks the ranking decision for this edge case.</p></main>'),
                'contains' => ['# Better Main', 'denser entry-content should win'],
                'notContains' => ['Tiny main'],
            ],
            [
                'id' => 'edge.agent.iframe-doc-description',
                'html' => self::doc('<article><h1>Embed</h1><p>Describe embedded dashboards in text because iframes are stripped from agent markdown context on purpose.</p><iframe src="/dashboard"></iframe><p>Textual description of metrics trends continues for the model after the iframe is removed.</p></article>'),
                'contains' => ['Describe embedded dashboards', 'metrics trends continues'],
                'notContains' => ['/dashboard'],
            ],
            [
                'id' => 'edge.agent.nav-in-article-exception',
                'html' => self::doc('<article><h1>Guide</h1><p>In-article step lists should remain even when they use list markup that resembles navigation for how-to content agents fetch often.</p><ol><li>Install the package with enough wording.</li><li>Run the migration with enough wording.</li></ol></article>'),
                'contains' => ['1. Install the package', '2. Run the migration'],
            ],
        ];

        // Parametric charset / entity edges
        foreach (['&copy;', '&mdash;', '&hellip;', '&nbsp;', '&#39;'] as $i => $ent) {
            $cases[] = [
                'id' => 'edge.agent.entity-' . $i,
                'html' => self::doc('<article><h1>Ent' . $i . '</h1><p>Entity ' . $ent . ' should decode or remain readable in prose for agents with a second paragraph ensuring viability on entity edge ' . $i . '.</p><p>Follow-up paragraph for entity edge ' . $i . '.</p></article>'),
                'contains' => ['Entity ', 'Follow-up paragraph for entity edge ' . $i],
            ];
        }

        // Parametric heading depth
        foreach ([1, 2, 3, 4, 5, 6] as $level) {
            $tag = 'h' . $level;
            $marks = str_repeat('#', min($level, 4));
            $cases[] = [
                'id' => 'edge.agent.heading-depth-' . $level,
                'html' => self::doc("<article><{$tag}>H{$level}Title</{$tag}><p>Heading depth {$level} must render for outline-aware agents with a second paragraph under the heading for viability.</p><p>Second paragraph under h{$level}.</p></article>"),
                'contains' => ["{$marks} H{$level}Title", "Heading depth {$level} must render"],
            ];
        }

        return $cases;
    }

    protected static function doc(string $inner): string
    {
        return '<!doctype html><html><head><title>Sweep</title></head><body>' . $inner . '</body></html>';
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
