<?php

declare(strict_types=1);

namespace Switon\Browser;

use Switon\Cdp\Exception as CdpException;
use Switon\Cdp\HeadedChromeInterface;
use Switon\Cdp\PageInterface;
use Switon\Cdp\Paths;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Throwable;

use function bin2hex;
use function is_string;
use function parse_url;
use function preg_match;
use function random_bytes;
use function sprintf;
use function strlen;
use function trim;

use const DIRECTORY_SEPARATOR;

/**
 * One headed tab and the latest snapshot ref map.
 *
 * A ref is a `backendNodeId`. That address reaches a node inside a shadow root or a same-origin
 * iframe, where a CSS selector built from the same node matches nothing.
 */
class HeadedBrowser implements HeadedBrowserInterface
{
    #[Autowired] protected HeadedChromeInterface $chrome;
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected PathAliasInterface $pathAlias;

    /** Empty uses `@runtime/browser-screenshots`. */
    #[Autowired] protected string $screenshotDir = '';

    /** Character budget for page prose in a snapshot; controls never fold. */
    #[Autowired] protected int $maxTextChars = SnapshotBuilder::MAX_TEXT_CHARS;

    protected ?PageInterface $page = null;

    /** @var array<string, string> Refs of the latest snapshot, as page targets (`#<id>`). */
    protected array $refs = [];

    /** Highest `eN` number handed out by the current snapshot, across the page and its frames. */
    protected int $refSeq = 0;

    public function navigate(string $url, ?callable $shouldAbort = null, ?string $ready = null): void
    {
        $url = trim($url);
        if ($url === '') {
            Exception::raise('URL must not be empty.');
        }
        $this->abort($shouldAbort, $url);

        $ready = trim((string) $ready);
        try {
            if ($this->page === null) {
                $this->page = $this->chrome->open($url);
            } else {
                $this->page->navigate($url);
            }
            if ($ready !== '') {
                $this->page->wait($ready);
            }
        } catch (CdpException $e) {
            Exception::raise($e->getMessage());
        }
        $this->refs = [];
    }

    public function snapshot(?callable $shouldAbort = null): string
    {
        $this->abort($shouldAbort);

        return $this->onPage(function (PageInterface $page): string {
            // Geometry tells the builder which elements a person can see, so a long page shows its
            // visible half first instead of being cut off at an arbitrary line.
            $layout = [];
            $viewportHeight = 0;
            try {
                $layout = $page->layout();
                $viewportHeight = $page->viewport()['height'];
            } catch (CdpException) {
                // Ordering falls back to document order; the snapshot is still usable.
            }

            $built = SnapshotBuilder::build($page->accessibilityTree(), $layout, $viewportHeight, maxTextChars: $this->maxTextChars);
            $refs = [];
            // Frame refs are numbered after the top document's, so one snapshot never reuses an id.
            $this->refSeq = count($built['refs']);
            foreach ($built['refs'] as $ref => $backendId) {
                // Address by node id, not by a selector: a node inside a shadow root or a frame
                // has no selector that reaches it from the top document.
                $refs[$ref] = '#' . $backendId;
            }

            // A cross-origin frame is a separate document; its content would otherwise be missing
            // from a snapshot that claims to describe the page.
            $frames = $this->frameLines($page);
            foreach ($frames['refs'] as $ref => $target) {
                $refs[$ref] = $target;
            }

            $this->refs = $refs;
            $url = $page->url();
            $text = $built['text'] . $frames['text'];

            $header = $url === '' ? '' : $url . "\n";
            if ($built['offscreen'] > 0) {
                // The count is the cue that more exists. Text below the fold is not in the body.
                $header .= sprintf(
                    "(%d more below the fold; scroll to bring them into view)\n",
                    $built['offscreen'],
                );
            }

            return $header . $text;
        });
    }

    /**
     * Snapshot of every child frame, appended to the main snapshot.
     *
     * @return array{text: string, refs: array<string, string>}
     */
    protected function frameLines(PageInterface $page): array
    {
        try {
            $frames = $page->frames();
        } catch (CdpException) {
            return ['text' => '', 'refs' => []];
        }

        $text = '';
        $refs = [];
        foreach ($frames as $frame) {
            $built = $this->frameTree($page, $frame);
            $label = $frame['sameOrigin'] ? 'iframe' : 'iframe (cross-origin)';
            $text .= "\n[" . $label . ' ' . self::shortUrl($frame['url']) . ']';

            if ($built === null) {
                $text .= ' content not readable';
                continue;
            }

            // Refs are renumbered so they cannot collide with the top document's, and each one
            // carries the address that reaches this document. A same-process frame resolves the
            // node id from the page session, so it keeps the plain `#<id>` form.
            $prefix = $frame['sameOrigin']
                ? '#'
                : '#' . $frame['ownerBackendNodeId'] . ':' . $frame['sessionId'] . '/';
            foreach ($built['refs'] as $backendId) {
                $ref = 'e' . (++$this->refSeq);
                $refs[$ref] = $prefix . $backendId;
            }
            $text .= "\n" . $built['text'];
        }

        return ['text' => $text, 'refs' => $refs];
    }

    /**
     * Accessibility tree of one frame, in whichever session owns it.
     *
     * @param array{frameId: string, url: string, sessionId: string, sameOrigin: bool} $frame
     *
     * @return array{text: string, refs: list<int>}|null
     */
    protected function frameTree(PageInterface $page, array $frame): ?array
    {
        try {
            $tree = $page->frameSnapshot($frame);
        } catch (CdpException) {
            return null;
        }
        if ($tree === []) {
            return null;
        }

        $built = SnapshotBuilder::build($tree);

        return ['text' => $built['text'], 'refs' => array_values($built['refs'])];
    }

    /**
     * Retries until the condition holds or `$timeout` runs out.
     *
     * @return array{ok: bool, actual: string, target: string, kind: string, expected: string, waitedMs: int}
     */
    public function assert(
        string $target,
        string $kind = 'visible',
        string $expected = '',
        float $timeout = 5.0,
        ?callable $shouldAbort = null,
    ): array {
        $this->abort($shouldAbort);
        $selector = $this->targetOf($target);

        return $this->onPage(static function (PageInterface $page) use ($selector, $kind, $expected, $timeout): array {
            return $page->assert($selector, $kind, $expected, $timeout);
        });
    }

    /**
     * Console output, uncaught exceptions, and failed requests since the last navigation.
     *
     * @return list<array{type: string, level: string, text: string, url: string, line: int}>
     */
    public function diagnostics(?callable $shouldAbort = null): array
    {
        $this->abort($shouldAbort);

        return $this->onPage(static function (PageInterface $page): array {
            return $page->diagnostics();
        });
    }

    /**
     * Computed styles and box of an element.
     *
     * @param list<string> $properties
     *
     * @return array<string, mixed>
     */
    public function inspect(string $ref, array $properties = [], ?callable $shouldAbort = null): array
    {
        $this->abort($shouldAbort);
        $selector = $this->selector($ref);

        return $this->onPage(static function (PageInterface $page) use ($selector, $properties): array {
            return $page->inspect($selector, $properties);
        });
    }

    /**
     * Run JavaScript in the agent tab.
     */
    public function evaluate(string $expression, ?callable $shouldAbort = null): mixed
    {
        $this->abort($shouldAbort);
        if (trim($expression) === '') {
            Exception::raise('Expression must not be empty.');
        }

        return $this->onPage(static function (PageInterface $page) use ($expression): mixed {
            return $page->evaluate($expression);
        });
    }

    /**
     * Child frames of the current page.
     *
     * @return list<array{frameId: string, url: string, sessionId: string, sameOrigin: bool, ownerBackendNodeId: int, offset: array{x: float, y: float}}>
     */
    public function frames(?callable $shouldAbort = null): array
    {
        $this->abort($shouldAbort);

        return $this->onPage(static function (PageInterface $page): array {
            return $page->frames();
        });
    }

    /** Last path segment of a URL, so a frame label stays short. */
    protected static function shortUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : '';

        return $path === '' || $path === '/' ? (string) parse_url($url, PHP_URL_HOST) : $path;
    }

    public function click(string $ref, ?callable $shouldAbort = null): void
    {
        $this->abort($shouldAbort);
        $selector = $this->selector($ref);
        $this->onPage(function (PageInterface $page) use ($selector): void {
            $page->click($selector);
        });
        $this->refs = [];
    }

    public function type(string $ref, string $text, bool $submit = false, ?callable $shouldAbort = null): void
    {
        $this->abort($shouldAbort);
        $selector = $this->selector($ref);
        $this->onPage(function (PageInterface $page) use ($selector, $text, $submit): void {
            $page->fill($selector, $text, $submit);
        });
        $this->refs = [];
    }

    /**
     * Scroll the page, or bring one snapshot ref into view.
     *
     * Role: a snapshot marks elements below the fold. Scrolling is how an agent reaches them — and
     * how it makes a screenshot show the part it wants to look at.
     */
    public function scroll(string $ref = '', int $y = 0, ?callable $shouldAbort = null): void
    {
        $this->abort($shouldAbort);
        $ref = trim($ref);

        $this->onPage(function (PageInterface $page) use ($ref, $y): void {
            if ($ref === '') {
                $page->scrollTo('', $y);

                return;
            }

            if (!isset($this->refs[$ref])) {
                Exception::raise('Unknown element ref "{ref}". Call snapshot first, then use a listed eN id.', [
                    'ref' => $ref,
                ]);
            }

            // The stored ref is already an address `scrollTo` understands (`#id` or a frame target).
            $page->scrollTo($this->refs[$ref]);
        });
    }

    public function takeScreenshot(bool $fullPage = false, ?callable $shouldAbort = null): array
    {
        $this->abort($shouldAbort);

        return $this->onPage(function (PageInterface $page) use ($fullPage): array {
            $png = $page->screenshot($fullPage);
            $path = $this->writePng($png);

            return [
                'path' => $path,
                'mime' => 'image/png',
                'bytes' => strlen($png),
                'url' => $page->url(),
            ];
        });
    }

    public function closePage(): void
    {
        $page = $this->page;
        $this->page = null;
        $this->refs = [];
        if ($page === null) {
            return;
        }

        try {
            $page->close();
        } catch (Throwable) {
        }
    }

    public function __destruct()
    {
        $this->closePage();
    }

    /**
     * Resolve an assert target: a snapshot ref when it is one, otherwise a CSS selector.
     *
     * A snapshot only refs interactive elements, so text a page shows back has no ref. Letting the
     * caller pass a selector keeps that verifiable without inflating every text line with an `eN`.
     */
    protected function targetOf(string $target): string
    {
        $target = trim($target);
        if ($target === '') {
            Exception::raise('Assertion target must not be empty (e.g. e12 or "#result").');
        }
        if (isset($this->refs[$target])) {
            return $this->refs[$target];
        }
        // A ref that was never listed is a stale-ref bug, not a selector.
        if (preg_match('/^e\d+$/', $target) === 1) {
            Exception::raise('Unknown element ref "{ref}". Call snapshot first, then use a listed eN id.', [
                'ref' => $target,
            ]);
        }

        return $target;
    }

    /**
     * Map a snapshot ref to a page target.
     *
     * A ref carries its own address (`#<backendNodeId>`) rather than a bare integer, so callers
     * and logs see something that cannot be mistaken for a selector.
     */
    protected function selector(string $ref): string
    {
        $ref = trim($ref);
        if ($ref === '') {
            Exception::raise('Element ref must not be empty (e.g. e12).');
        }
        if (!isset($this->refs[$ref])) {
            Exception::raise('Unknown element ref "{ref}". Call snapshot first, then use a listed eN id.', [
                'ref' => $ref,
            ]);
        }

        return $this->refs[$ref];
    }

    /**
     * @template T
     *
     * @param callable(PageInterface): T $action
     *
     * @return T
     */
    protected function onPage(callable $action): mixed
    {
        if ($this->page === null) {
            Exception::raise('Open a page with navigate() before using it.');
        }

        try {
            return $action($this->page);
        } catch (CdpException $e) {
            Exception::raise($e->getMessage());
        }
    }

    /** @param callable(): bool|null $shouldAbort */
    protected function abort(?callable $shouldAbort, string $url = ''): void
    {
        if ($shouldAbort === null || !$shouldAbort()) {
            return;
        }

        Exception::raise($url === '' ? 'Browser operation aborted.' : 'Browser operation aborted for "{url}".', [
            'url' => $url,
        ]);
    }

    protected function writePng(string $png): string
    {
        $dir = $this->screenshotDir !== ''
            ? $this->screenshotDir
            : $this->pathAlias->resolve('@runtime/browser-screenshots');
        try {
            if (!$this->filesystem->isDir($dir)) {
                $this->filesystem->mkdir($dir, 0700);
            }
            $path = $dir . DIRECTORY_SEPARATOR . 'shot-' . bin2hex(random_bytes(8)) . '.png';
            $this->filesystem->write($path, $png);

            return $path;
        } catch (Throwable) {
            Exception::raise('Failed to write screenshot "{path}".', ['path' => Paths::displayPath($dir)]);
        }
    }
}
