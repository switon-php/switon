<?php

declare(strict_types=1);

namespace Switon\Cdp;

use Switon\Core\Attribute\Autowired;
use Switon\Core\WebFetcherInterface;

use function is_array;
use function is_callable;
use function is_float;
use function is_int;
use function is_string;
use function str_starts_with;
use function strtoupper;
use function trim;

/**
 * Headless Chrome on port 59222. Search and other silent fetches use this.
 */
class HeadlessChrome extends Chrome implements HeadlessChromeInterface, WebFetcherInterface
{
    #[Autowired] protected int $port = Paths::PORT_HEADLESS;

    protected function isHeadless(): bool
    {
        return true;
    }

    protected function listenPort(): int
    {
        return $this->port;
    }

    /**
     * Keep the previous tab in front.
     *
     * A visible window may be sharing this port for a login, and opening a tab must not pull
     * the human away from what they were doing.
     *
     * Guidance: this covers `open()` only. Chrome delivers `Input.*` to the active tab alone, so
     * a later `click()` or `fill()` on this tab activates it and the human's tab goes behind.
     */
    protected function tabToFocusAfterOpen(string $previousId, string $targetId): string
    {
        return $previousId === $targetId ? '' : $previousId;
    }

    /**
     * Take over the port from a visible window that nobody is using.
     *
     * A freshly launched window always holds one `about:blank` tab, so counting tabs never
     * reports "empty". What matters is whether a human opened something: a window on a real
     * page is the login-reuse path and is left alone.
     */
    protected function reclaimForeignListener(): bool
    {
        $port = $this->listenPort();
        $headless = $this->launcher->listenerIsHeadless($port);
        if ($headless === null || $headless) {
            return false;
        }
        if ($this->holdsPageInUse($port)) {
            return false;
        }

        $this->quitBrowser();
        $deadline = $this->clock->microtime() + 3.0;
        while ($this->clock->microtime() < $deadline) {
            if (!$this->launcher->portOpen($port)) {
                return true;
            }
            $this->clock->sleep(0.05);
        }

        return !$this->launcher->portOpen($port);
    }

    /**
     * Whether the window on `$port` has a page a human opened.
     *
     * An unreadable target list counts as "in use", so an unknown state never costs someone
     * their window.
     */
    private function holdsPageInUse(int $port): bool
    {
        $list = $this->httpJson('http://' . self::HOST . ':' . $port . '/json/list', 1.0, 'GET');
        if (!is_array($list)) {
            return true;
        }

        foreach ($list as $row) {
            if (!is_array($row) || ($row['type'] ?? '') !== 'page') {
                continue;
            }
            $url = (string) ($row['url'] ?? '');
            if ($url !== '' && !str_starts_with($url, 'about:') && !str_starts_with($url, 'chrome://')) {
                return true;
            }
        }

        return false;
    }

    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        ?string $origin = null,
    ): array {
        $method = trim($method);
        $url = trim($url);
        $origin = $origin !== null ? trim($origin) : '';
        if ($method === '' || $url === '') {
            Exception::raise('Request method and URL must not be empty.');
        }

        $document = $origin === '' && strtoupper($method) === 'GET' && $body === null && $headers === [];
        if ($document) {
            $page = $this->open($url);
            try {
                return ['status' => $page->status(), 'headers' => $page->headers(), 'body' => $page->content()];
            } finally {
                $page->close();
            }
        }

        $page = $this->open($origin !== '' ? $origin : 'about:blank');
        try {
            return $page->fetch($method, $url, $headers, $body);
        } finally {
            $page->close();
        }
    }

    /**
     * {@see WebFetcherInterface}: the fetches a page reader needs, with method and body.
     *
     * `origin` opens that document first so the call carries its cookies; `ready` waits for a
     * selector so a rendered page is readable. A bare `GET` with no headers, body, or `origin`
     * is a plain document read.
     */
    public function fetch(
        string $url,
        string $method = 'GET',
        array $headers = [],
        ?string $body = null,
        array $options = [],
    ): array {
        $url = trim($url);
        $method = trim($method);
        if ($url === '' || $method === '') {
            Exception::raise('Request method and URL must not be empty.');
        }

        $origin = is_string($options[WebFetcherInterface::ORIGIN] ?? null)
            ? trim((string) $options[WebFetcherInterface::ORIGIN])
            : '';
        $ready = is_string($options[WebFetcherInterface::READY] ?? null)
            ? trim((string) $options[WebFetcherInterface::READY])
            : '';
        $timeout = $this->budget($options[WebFetcherInterface::TIMEOUT] ?? null);
        $shouldAbort = $options[WebFetcherInterface::SHOULD_ABORT] ?? null;
        $shouldAbort = is_callable($shouldAbort) ? $shouldAbort : null;

        $document = $origin === ''
            && strtoupper($method) === 'GET'
            && $body === null
            && $headers === [];
        if ($document) {
            $page = $this->open($url, $timeout);
            try {
                $this->abortIf($shouldAbort, $url);
                if ($ready !== '') {
                    // The selector describes this document, so wait on it before capturing.
                    $page->wait($ready);
                }

                return ['status' => $page->status(), 'headers' => $page->headers(), 'body' => $page->content()];
            } finally {
                $page->close();
            }
        }

        $page = $this->open($origin !== '' ? $origin : 'about:blank', $timeout);
        try {
            $this->abortIf($shouldAbort, $url);

            return $page->fetch($method, $url, $headers, $body);
        } finally {
            $page->close();
        }
    }

    /** `$timeout` from options when it is a positive number, else the configured budget. */
    protected function budget(mixed $timeout): float
    {
        if (is_int($timeout) || is_float($timeout)) {
            return $timeout > 0 ? (float) $timeout : $this->timeout;
        }

        return $this->timeout;
    }

    /** @param null|callable(): bool $shouldAbort */
    protected function abortIf(?callable $shouldAbort, string $url): void
    {
        if ($shouldAbort !== null && $shouldAbort()) {
            Exception::raise('Fetch aborted for "{url}".', ['url' => $url]);
        }
    }
}
