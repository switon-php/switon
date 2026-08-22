<?php

declare(strict_types=1);

namespace Switon\Cdp;

/**
 * One open tab. Same role as Playwright's `Page`.
 *
 * Guidance: take this from {@see ChromeInterface::open()}. Call {@see PageInterface::close()} when the tab is done.
 */
interface PageInterface
{
    /** Document HTML of this tab. */
    public function content(): string;

    /** HTTP status of the last navigation. `0` when that navigation had no HTTP response. */
    public function status(): int;

    /**
     * Response headers of the last navigation. Empty when that navigation had no HTTP response.
     *
     * @return array<string, string>
     */
    public function headers(): array;

    /** Address of this tab. */
    public function url(): string;

    /**
     * Go to `$url` in this tab.
     */
    public function navigate(string $url): void;

    /**
     * Poll until `$selector` matches. The Chrome timeout still returns, so the caller can read the page.
     */
    public function wait(string $selector): void;

    /**
     * Click an element.
     *
     * `$selector` is a CSS selector, or `#123` for a `backendNodeId` from
     * {@see PageInterface::accessibilityTree()}. The node id reaches an element inside a shadow
     * root or a same-origin iframe, where a CSS selector cannot.
     *
     * The element must be present and have a visible box. An empty selector throws.
     *
     * Activates the tab inside its window, so `Input.*` reaches it. Does not raise the window.
     */
    public function click(string $selector): void;

    /**
     * Replace the text of a field.
     *
     * `$selector` is a CSS selector or `#<backendNodeId>`, as in {@see PageInterface::click()}.
     * Clicks the field, selects its contents, then inserts `$text`. Works on `input`, `textarea`,
     * and `contenteditable`. An empty selector throws.
     *
     * `$submit` presses a real Enter, so a form submits as it would for a person.
     *
     * Activates the tab inside its window. Does not raise the window.
     */
    public function fill(string $selector, string $text, bool $submit = false): void;

    /**
     * PNG bytes of this tab. `$fullPage` includes the scrollable page.
     *
     * Raises the visible tab first: this is the moment to look at it.
     */
    public function screenshot(bool $fullPage = false): string;

    /**
     * Accessibility tree of this tab.
     *
     * @return array<string, mixed>
     */
    public function accessibilityTree(): array;

    /**
     * Box of every node in the document, in viewport coordinates.
     *
     * Role: tells a snapshot which elements a person can actually see, in one call rather than a
     * per-node round trip.
     *
     * @return array<int, array{x: float, y: float, w: float, h: float}> backendNodeId => box
     */
    public function layout(): array;

    /**
     * Viewport size and scroll position of this tab.
     *
     * @return array{width: int, height: int, scrollY: int, scrollHeight: int}
     */
    public function viewport(): array;

    /**
     * Move the page to `$y` device pixels, or bring an element into view.
     *
     * `$selector` is a CSS selector or `#<backendNodeId>`, as in {@see PageInterface::click()}.
     * Empty scrolls the document to `$y`.
     */
    public function scrollTo(string $selector = '', int $y = 0): void;

    /**
     * CSS selector for a node from {@see PageInterface::accessibilityTree()}.
     *
     * Guidance: a label, not an address. A node inside a shadow root or another document yields a
     * selector that matches nothing there; pass `#<backendNodeId>` to
     * {@see PageInterface::click()} instead.
     */
    public function selector(int $backendNodeId): string;

    /**
     * Run JavaScript in this tab and return the value.
     *
     * The general-purpose probe: read storage, compute a style, assert a condition. An exception
     * in the page is raised here with its message.
     */
    public function evaluate(string $expression): mixed;

    /**
     * Console output, uncaught exceptions, and failed requests since the last navigation.
     *
     * @return list<array{type: string, level: string, text: string, url: string, line: int}>
     */
    public function diagnostics(): array;

    /**
     * Computed styles and box of one element, addressed like {@see PageInterface::click()}.
     *
     * @param list<string> $properties CSS property names; empty reads a common set
     *
     * @return array{target: string, tag: string, visible: bool, box: array<string, float>, styles: array<string, string>}
     */
    public function inspect(string $target, array $properties = []): array;

    /**
     * Check that an element satisfies a condition, retrying until it does or time runs out.
     *
     * Role: "did the click work" needs an answer. A page updates after an action, so the condition
     * is re-checked for a while rather than sampled once.
     *
     * @param string $path Addressed like {@see PageInterface::click()}
     * @param string $kind `visible`, `hidden`, `text`, `text_contains`, `value`, `enabled`, or `checked`
     * @param string $expected Compared against the text or value kinds
     *
     * @return array{ok: bool, actual: string, target: string, kind: string, expected: string, waitedMs: int}
     */
    public function assert(
        string $path,
        string $kind = 'visible',
        string $expected = '',
        float $timeout = 5.0,
    ): array;

    /**
     * Child frames of this page. An iframe's content is not in this document's snapshot.
     *
     * Guidance: a cross-origin frame carries `ownerBackendNodeId` and `sessionId`. A node inside it
     * is addressed as `#<ownerBackendNodeId>:<sessionId>/<nodeId>`, which `click()`, `fill()` and
     * `inspect()` accept.
     *
     * @return list<array{frameId: string, url: string, sessionId: string, sameOrigin: bool, ownerBackendNodeId: int, offset: array{x: float, y: float}}>
     */
    public function frames(): array;

    /**
     * Accessibility tree of a child frame, or of this page when `$frame` is null.
     *
     * Guidance: a snapshot of the top document will not contain iframe content. Pass the whole entry
     * from {@see PageInterface::frames()} — an out-of-process frame answers on its own session, so a
     * bare frameId is not enough there.
     *
     * @param array{frameId: string, sessionId: string}|null $frame An entry from {@see PageInterface::frames()}
     *
     * @return array<string, mixed>
     */
    public function frameSnapshot(?array $frame = null): array;

    /**
     * Run JavaScript inside a cross-origin frame, using its `sessionId` from
     * {@see PageInterface::frames()}.
     */
    public function frameEvaluate(string $sessionId, string $expression): mixed;

    /**
     * Send a request from this tab.
     *
     * A `Referer` header is applied by Chrome for this one call and cleared afterwards. Other
     * headers go to `fetch`.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function fetch(string $method, string $url, array $headers = [], ?string $body = null): array;

    /**
     * Bring this tab to the front.
     *
     * On a headed window, a minimized window is restored and raised above other applications.
     */
    public function activate(): void;

    /** Close this tab. */
    public function close(): void;
}
