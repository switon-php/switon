<?php

declare(strict_types=1);

namespace Switon\Browser;

/**
 * One visible Chrome tab: snapshot refs, then click and type on that page.
 *
 * Guidance: inject this for a single PHP caller. An agent with named tabs uses
 * {@see HeadedBrowsersInterface}. Silent fetch uses {@see \Switon\Cdp\HeadlessChromeInterface}.
 *
 * Every `$shouldAbort` argument is a `callable(): bool|null` polled before the step runs.
 */
interface HeadedBrowserInterface
{
    /** Open `$url` in the tab. `$ready` is a CSS selector polled before returning. */
    public function navigate(string $url, ?callable $shouldAbort = null, ?string $ready = null): void;

    /** URL plus the accessibility text. Interactive rows carry `eN` refs. */
    public function snapshot(?callable $shouldAbort = null): string;

    /** Click a ref from the last snapshot. */
    public function click(string $ref, ?callable $shouldAbort = null): void;

    /** Replace a ref's text. `$submit` presses Enter. */
    public function type(
        string $ref,
        string $text,
        bool $submit = false,
        ?callable $shouldAbort = null,
    ): void;

    /**
     * PNG file of the tab.
     *
     * @return array{path: string, mime: string, bytes: int, url: string}
     */
    public function takeScreenshot(bool $fullPage = false, ?callable $shouldAbort = null): array;

    /**
     * Console output, uncaught exceptions, and failed requests since the last navigation.
     *
     * Guidance: read this when the page did not behave. An uncaught exception and a 4xx/5xx
     * response appear here and nowhere else.
     *
     * @return list<array{type: string, level: string, text: string, url: string, line: int}>
     */
    public function diagnostics(?callable $shouldAbort = null): array;

    /**
     * Computed styles and box of a ref from the last snapshot.
     *
     * @param list<string> $properties CSS names; empty reads a common set
     *
     * @return array<string, mixed>
     */
    public function inspect(string $ref, array $properties = [], ?callable $shouldAbort = null): array;

    /**
     * Check a ref, or a CSS selector, against a condition and say whether it held.
     *
     * Guidance: read the result, not the exception — `ok` says whether it held, `actual` says what
     * was found. Only interactive elements get a ref, so pass a selector (`#out`) for text the page
     * shows back.
     *
     * @param string $target Snapshot ref (`e12`) or CSS selector (`#out`)
     * @param string $kind `visible`, `hidden`, `text`, `text_contains`, `value`, `enabled`, `checked`
     * @param string $expected Compared against the text or value kinds
     *
     * @return array{ok: bool, actual: string, target: string, kind: string, expected: string, waitedMs: int}
     */
    public function assert(
        string $target,
        string $kind = 'visible',
        string $expected = '',
        float $timeout = 5.0,
        ?callable $shouldAbort = null,
    ): array;

    /** Run JavaScript in the tab and return its value. */
    public function evaluate(string $expression, ?callable $shouldAbort = null): mixed;

    /**
     * Child frames of the current page.
     *
     * @return list<array{frameId: string, url: string, sessionId: string, sameOrigin: bool, ownerBackendNodeId: int, offset: array{x: float, y: float}}>
     */
    public function frames(?callable $shouldAbort = null): array;

    /**
     * Scroll the page, or bring one ref into view.
     *
     * Guidance: text below the fold is left out of a snapshot, and a control there is marked
     * `(offscreen)`. Pass a ref to centre it, or a pixel offset to move down.
     */
    public function scroll(string $ref = '', int $y = 0, ?callable $shouldAbort = null): void;

    /** Close the tab and drop its refs. */
    public function closePage(): void;
}
