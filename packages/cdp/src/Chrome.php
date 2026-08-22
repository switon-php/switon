<?php

declare(strict_types=1);

namespace Switon\Cdp;

use JsonException;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Throwable;

use function fclose;
use function feof;
use function fread;
use function fwrite;
use function intdiv;
use function is_array;
use function is_string;
use function json_decode;
use function max;
use function microtime;
use function min;
use function parse_url;
use function preg_match;
use function rawurlencode;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_client;
use function strlen;
use function strpos;
use function substr;
use function trim;

use const JSON_THROW_ON_ERROR;

/**
 * Shared Chrome process: one profile, one port, tabs opened with {@see Chrome::open()}.
 *
 * Role: DevTools HTTP, process ensure, and tab lifecycle. Mode-specific policy — launch flags,
 * the tab to focus after `open()`, and whether a foreign listener is reclaimed — is overridden
 * by {@see HeadlessChrome} and {@see HeadedChrome}; this class has no default opinion on it.
 *
 * Guidance: inject {@see HeadlessChromeInterface} or {@see HeadedChromeInterface}. Listening address is `127.0.0.1`.
 */
abstract class Chrome implements ChromeInterface
{
    protected const HOST = '127.0.0.1';

    #[Autowired] protected Launcher $launcher;
    #[Autowired] protected ClockInterface $clock;

    /** Profile directory. Empty uses `~/.switon/chrome/headless` or `headed`. */
    #[Autowired] protected string $userDataDir = '';
    /** Chrome binary. Empty uses the detected install. */
    #[Autowired] protected string $executable = '';
    /** Launch Chrome when this port is closed. */
    #[Autowired] protected bool $autoLaunch = true;
    /** Navigation and CDP call budget, in seconds. */
    #[Autowired] protected float $timeout = 45.0;
    /**
     * Extra Chrome flags appended at launch.
     *
     * Role: environment accommodations the package must not assume — `--no-sandbox` inside a
     * container, `--proxy-server`, `--disable-dev-shm-usage` on a small `/dev/shm`.
     *
     * @var list<string>
     */
    #[Autowired] protected array $args = [];

    /**
     * Launch mode passed to {@see Launcher}: `true` adds `--headless=new`, hides the window,
     * and selects the `headless` profile.
     *
     * Role: a launch parameter only. Focus and reclaim policy live in the hooks below, so this
     * class never branches on which subclass it is.
     */
    abstract protected function isHeadless(): bool;

    abstract protected function listenPort(): int;

    public function open(string $url, ?float $timeout = null): PageInterface
    {
        $url = trim($url);
        if ($url === '') {
            Exception::raise('URL must not be empty.');
        }

        $budget = $timeout !== null && $timeout > 0 ? $timeout : $this->timeout;
        $this->ensure();
        $opened = $this->connectNewTab();
        try {
            $opened['session']->navigateAndWait($url, $budget);
        } catch (Throwable $e) {
            $this->discard($opened['session'], $opened['id']);
            throw $e;
        }

        return new Page($opened['session'], $opened['id'], $this, $budget);
    }

    /**
     * Quit the browser, but only one this service started.
     *
     * A browser the service merely attached to — the visible window a human opened to sign in
     * — is left running, together with its tabs and login.
     */
    public function close(): void
    {
        $port = $this->listenPort();
        if (!$this->launcher->ownsProcess($port)) {
            return;
        }

        $this->quitBrowser();
        $this->launcher->stopOwnedProcess($port);
    }

    /**
     * Bring a tab to the front. Best effort: a target that already closed is ignored.
     *
     * Headed Chrome also restores a minimized window and raises it above other applications.
     */
    public function focusTarget(string $targetId): void
    {
        $targetId = trim($targetId);
        if ($targetId === '') {
            return;
        }

        $this->httpJson(
            'http://' . self::HOST . ':' . $this->listenPort() . '/json/activate/' . rawurlencode($targetId),
            min(3.0, $this->timeout),
            'GET',
        );
    }

    /**
     * Close a tab by target id. Best effort: an id the browser no longer knows is ignored.
     *
     * The id comes from {@see Chrome::connectNewTab()}, which refuses a target without one,
     * so an empty id here only means "nothing to close".
     */
    public function dropTarget(string $targetId): void
    {
        $targetId = trim($targetId);
        if ($targetId === '') {
            return;
        }

        $this->httpJson(
            'http://' . self::HOST . ':' . $this->listenPort() . '/json/close/' . rawurlencode($targetId),
            min(5.0, $this->timeout),
            'GET',
        );
    }

    public function timeout(): float
    {
        return $this->timeout;
    }

    protected function ensure(): void
    {
        $port = $this->listenPort();
        if ($this->launcher->portOpen($port)) {
            if (!$this->reclaimForeignListener()) {
                return;
            }
        }

        if (!$this->autoLaunch) {
            $hint = $this->isHeadless()
                ? 'Start Chrome on this port with the headless profile'
                : 'Run {script}';
            Exception::raise(
                'Chrome debugging port {port} is closed and autoLaunch is disabled. ' . $hint . '.',
                ['port' => $port, 'script' => Paths::displayPath(Paths::startScriptPath($this->profileDir()))],
            );
        }

        $this->launcher->ensure(
            $port,
            $this->profileDir(),
            $this->isHeadless(),
            trim($this->executable),
            min(20.0, max(5.0, $this->timeout)),
            $this->args,
        );
    }

    /**
     * Target to bring to the front after {@see Chrome::open()} created a tab.
     *
     * Empty leaves focus where Chrome put it. Each mode states its own policy; the base has
     * none, so no mode has to ask "am I the other one?".
     */
    protected function tabToFocusAfterOpen(string $previousId, string $targetId): string
    {
        return '';
    }

    /**
     * Quit a process already listening on this port so a fresh launch can proceed.
     *
     * `true` means the port is free again and {@see Chrome::ensure()} should launch. `false`
     * means attach to whatever is already there, which is the default for every mode.
     */
    protected function reclaimForeignListener(): bool
    {
        return false;
    }

    protected function profileDir(): string
    {
        if ($this->userDataDir !== '') {
            return $this->userDataDir;
        }

        return Paths::defaultUserDataDir($this->isHeadless());
    }

    /**
     * @return array{session: Session, id: string}
     */
    protected function connectNewTab(): array
    {
        $port = $this->listenPort();
        $host = self::HOST;
        $previousId = $this->mostRecentPageTargetId($host, $port, min(5.0, $this->timeout));
        $created = $this->httpJson("http://{$host}:{$port}/json/new?about:blank", $this->timeout, 'PUT');
        if (!is_array($created) || ($created['webSocketDebuggerUrl'] ?? '') === '') {
            $created = $this->httpJson("http://{$host}:{$port}/json/new?about:blank", $this->timeout, 'GET');
        }
        if (!is_array($created) || ($created['webSocketDebuggerUrl'] ?? '') === '') {
            Exception::raise('Chrome returned no page target on port {port}.', ['port' => $port]);
        }

        $targetId = (string) ($created['id'] ?? '');
        if ($targetId === '') {
            Exception::raise('Chrome returned a page target without an id on port {port}.', ['port' => $port]);
        }

        $focus = $this->tabToFocusAfterOpen($previousId, $targetId);
        if ($focus !== '') {
            $this->focusTarget($focus);
        }

        return [
            'session' => Session::connectToDebuggerUrl((string) $created['webSocketDebuggerUrl'], $this->timeout),
            'id' => $targetId,
        ];
    }

    protected function discard(Session $session, string $targetId): void
    {
        try {
            $session->close();
        } catch (Throwable) {
        }
        $this->dropTarget($targetId);
    }

    protected function quitBrowser(): void
    {
        $timeout = min(3.0, $this->timeout);
        $this->withDebuggerSession($this->browserDebuggerUrl($timeout), $timeout, static function (Session $session) use ($timeout): void {
            $session->call('Browser.close', [], $timeout);
        });
    }

    /**
     * WebSocket URL of the browser target. Empty when DevTools is not answering.
     */
    protected function browserDebuggerUrl(float $timeout): string
    {
        $version = $this->httpJson(
            'http://' . self::HOST . ':' . $this->listenPort() . '/json/version',
            $timeout,
            'GET',
        );

        return is_array($version) && is_string($version['webSocketDebuggerUrl'] ?? null)
            ? $version['webSocketDebuggerUrl']
            : '';
    }

    /**
     * Run `$use` on one DevTools connection. A missing endpoint or a rejected command is ignored.
     *
     * Focus and quit are best effort: the tab may already be gone, and the caller still finishes.
     *
     * @param callable(Session): void $use
     */
    protected function withDebuggerSession(string $wsUrl, float $timeout, callable $use): void
    {
        if ($wsUrl === '') {
            return;
        }

        try {
            $session = Session::connectToDebuggerUrl($wsUrl, $timeout);
        } catch (Throwable) {
            return;
        }

        try {
            $use($session);
        } catch (Throwable) {
        } finally {
            try {
                $session->close();
            } catch (Throwable) {
            }
        }
    }

    /**
     * Id of the page target that existed before this one.
     *
     * `/json/list` carries no "active" flag, so this reads the newest-first order Chrome
     * returns: the first `type=page` row is the most recently created tab, which is the tab
     * that was in front before {@see Chrome::connectNewTab()} created another.
     */
    protected function mostRecentPageTargetId(string $host, int $port, float $timeout): string
    {
        $list = $this->httpJson("http://{$host}:{$port}/json/list", $timeout, 'GET');
        if (!is_array($list)) {
            return '';
        }

        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            if (($row['type'] ?? '') === 'page' && ($row['id'] ?? '') !== '') {
                return (string) $row['id'];
            }
        }

        return '';
    }

    /**
     * @return array<mixed>|null
     */
    protected function httpJson(string $url, float $timeout, string $method = 'GET'): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'], $parts['path'])) {
            return null;
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 80;
        $path = $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
        $errno = 0;
        $errstr = '';
        $deadline = microtime(true) + max(1.0, $timeout);
        $fp = @stream_socket_client(
            "tcp://{$host}:{$port}",
            $errno,
            $errstr,
            max(1.0, $timeout),
        );
        if ($fp === false) {
            return null;
        }

        $request = "{$method} {$path} HTTP/1.1\r\n"
            . "Host: {$host}:{$port}\r\n"
            . "Connection: close\r\n"
            . "Accept: application/json\r\n"
            . "\r\n";
        if (fwrite($fp, $request) === false) {
            fclose($fp);

            return null;
        }

        $raw = '';
        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            // One clock for the whole read: the stream timeout is the remaining budget.
            $micros = (int) max(1_000, $remaining * 1_000_000);
            stream_set_timeout($fp, intdiv($micros, 1_000_000), $micros % 1_000_000);

            $chunk = fread($fp, 8192);
            if ($chunk === false) {
                break;
            }
            if ($chunk === '') {
                $meta = stream_get_meta_data($fp);
                if (($meta['timed_out'] ?? false) === true || feof($fp)) {
                    break;
                }
                continue;
            }
            $raw .= $chunk;
            if (strlen($raw) > 2_000_000) {
                break;
            }
            $headerEnd = strpos($raw, "\r\n\r\n");
            if ($headerEnd !== false) {
                $headers = substr($raw, 0, $headerEnd);
                $body = substr($raw, $headerEnd + 4);
                if (preg_match('/^Content-Length:\s*(\d+)/mi', $headers, $m) === 1
                    && strlen($body) >= (int) $m[1]
                ) {
                    break;
                }
            }
        }
        fclose($fp);
        if ($raw === '') {
            return null;
        }

        $pos = strpos($raw, "\r\n\r\n");
        if ($pos === false) {
            return null;
        }
        $body = substr($raw, $pos + 4);
        if (preg_match('/^Content-Length:\s*(\d+)/mi', substr($raw, 0, $pos), $m) === 1) {
            $body = substr($body, 0, (int) $m[1]);
        }
        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
