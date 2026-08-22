<?php

declare(strict_types=1);

namespace Switon\Cdp;

use JsonException;
use stdClass;
use Throwable;

use function array_splice;
use function count;
use function ctype_digit;
use function is_array;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function max;
use function microtime;
use function min;
use function parse_url;
use function str_contains;
use function strtolower;

use const JSON_THROW_ON_ERROR;

/**
 * One CDP connection to a Chrome page target.
 *
 * Role: send Domain.method commands and wait for matching id / load events.
 */
class Session
{
    /**
     * Events retained before the oldest are dropped.
     *
     * A long-lived session pumps every `Network.*` / `Page.*` event into the buffer, and only
     * `navigateAndWait()` and `waitForEvent()` drain it. The cap keeps an idle page from growing
     * the array without bound.
     */
    protected const MAX_EVENTS = 1000;

    /**
     * Diagnostics kept for a page: console lines, uncaught errors, failed requests.
     *
     * Role: the same budget as {@see Session::MAX_EVENTS}. Call sites read them after the fact
     * through {@see Session::diagnostics()}, so a page that broke while nobody watched still
     * reports why.
     */
    protected const MAX_DIAGNOSTICS = 200;

    /**
     * How long {@see Session::diagnostics()} keeps reading events before it answers.
     *
     * Role: a request that fired moments ago finishes its round trip during the read. Long enough
     * for a local response, short enough that a quiet page answers immediately.
     */
    protected const DIAGNOSTIC_DRAIN = 0.5;

    protected WebSocket $socket;
    protected int $nextId = 1;

    /** @var array<int, array<string, mixed>> */
    protected array $pending = [];

    /**
     * Ids whose caller is still waiting inside {@see Session::call()}.
     *
     * Role: tells `pump()` which replies to keep. A reply is worth storing only while someone
     * waits for it; `settleDialog()` calls back into `call()` from inside `pump()`, so "the id in
     * flight is nextId - 1" is no longer true.
     *
     * @var array<int, true>
     */
    protected array $awaiting = [];

    /** @var list<array{method: string, params: array<string, mixed>}> */
    protected array $events = [];

    /**
     * Console output, uncaught exceptions, browser log entries, and failed requests.
     *
     * @var list<array{type: string, level: string, text: string, url: string, line: int}>
     */
    protected array $diagnostics = [];

    /** Whether `Runtime.*` / `Log.*` / `Network.*` are already switched on for diagnostics. */
    protected bool $diagnosticsEnabled = false;

    /** Accept `alert` / `confirm` / `prompt` without asking a human. */
    protected bool $autoAcceptDialogs = true;

    /** How `prompt()` answers when it is auto-accepted. */
    protected string $autoAcceptPromptText = '';

    /** HTTP status of the document response for the last navigation. `0` when none arrived. */
    protected int $navigationStatus = 0;

    /** @var array<string, string> Response headers of the document captured with {@see}. */
    protected array $navigationHeaders = [];

    /**
     * Child browsing contexts found on the page, keyed by their id.
     *
     * Role: an iframe is invisible to `Runtime.evaluate` in the parent document. Same-origin
     * frames are reachable through a `frameId`; a cross-origin frame runs in its own process and
     * needs the `sessionId` Chrome hands out when it attaches.
     *
     * @var array<string, array{frameId: string, url: string, sessionId: string, sameOrigin: bool, ownerBackendNodeId: int, offset: array{x: float, y: float}}>
     */
    protected array $frames = [];

    /**
     * Backend node id of an `<iframe>` element to the session of the document it hosts.
     *
     * Role: a click target can be addressed as `#<backendNodeId>` only in the session that owns
     * that document. This map says which session a parent-scoped owner id belongs to, so
     * {@see Page::click()} knows where to resolve and where to dispatch.
     *
     * @var array<int, string>
     */
    protected array $frameByOwner = [];

    /**
     * Viewport origin of each cross-origin frame's document, keyed by its owner backend node id.
     *
     * @var array<int, array{x: float, y: float}>
     */
    protected array $frameOffsets = [];

    /**
     * URL to flattened session id for out-of-process iframes seen so far.
     *
     * @var array<string, string>
     */
    protected array $frameSessions = [];

    public function __construct(WebSocket $socket)
    {
        $this->socket = $socket;
    }

    public static function connectToDebuggerUrl(string $wsUrl, float $timeout = 30.0): self
    {
        return new self(new WebSocket($wsUrl, $timeout));
    }

    /** HTTP status of the document response for the last navigation. `0` when none arrived. */
    public function navigationStatus(): int
    {
        return $this->navigationStatus;
    }

    /**
     * Response headers of the document captured with {@see Session::navigationStatus()}.
     *
     * @return array<string, string>
     */
    public function navigationHeaders(): array
    {
        return $this->navigationHeaders;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    public function call(string $method, array $params = [], float $timeout = 30.0, string $sessionId = ''): array
    {
        $id = $this->nextId++;
        $message = [
            'id' => $id,
            'method' => $method,
            'params' => $params === [] ? new stdClass() : $params,
        ];
        if ($sessionId !== '') {
            // Flattened child target (an out-of-process iframe) answers on its own session.
            $message['sessionId'] = $sessionId;
        }
        $payload = json_encode($message, JSON_THROW_ON_ERROR);
        $this->socket->sendText($payload);

        $this->awaiting[$id] = true;
        $deadline = microtime(true) + max(0.5, $timeout);
        try {
            while (microtime(true) < $deadline) {
                if (isset($this->pending[$id])) {
                    $result = $this->pending[$id];
                    if (isset($result['error'])) {
                        $message = is_array($result['error'])
                            ? (string) ($result['error']['message'] ?? json_encode($result['error']))
                            : (string) $result['error'];
                        Exception::raise('CDP {method} failed: {error}', [
                            'method' => $method,
                            'error' => $message,
                        ]);
                    }

                    /** @var array<string, mixed> $body */
                    $body = is_array($result['result'] ?? null) ? $result['result'] : [];

                    return $body;
                }

                $remaining = $deadline - microtime(true);
                $this->pump(max(0.05, $remaining));
            }
        } finally {
            // A reply that arrives after this call gave up must not stay in the buffer.
            unset($this->pending[$id], $this->awaiting[$id]);
        }

        Exception::raise('CDP {method} timed out after {seconds}s.', [
            'method' => $method,
            'seconds' => $timeout,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function waitForEvent(string $method, float $timeout = 30.0): array
    {
        $deadline = microtime(true) + max(0.5, $timeout);
        while (microtime(true) < $deadline) {
            foreach ($this->events as $i => $event) {
                if ($event['method'] === $method) {
                    array_splice($this->events, $i, 1);

                    return $event['params'];
                }
            }
            $this->pump(max(0.05, $deadline - microtime(true)));
        }

        Exception::raise('Timed out waiting for CDP event {method}.', ['method' => $method]);
    }

    public function navigateAndWait(string $url, float $timeout = 30.0): void
    {
        $this->call('Page.enable', [], min(10.0, $timeout));
        // Lifecycle events are the authoritative "this document is ready" signal. `load` fires when
        // the HTML arrives, which on a single-page app is before anything is rendered; `networkIdle`
        // fires when the app has fetched what it needs, and the DOM measured at that moment is the
        // mounted page.
        $this->call('Page.setLifecycleEventsEnabled', ['enabled' => true], min(10.0, $timeout));
        $this->call('Runtime.enable', [], min(10.0, $timeout));
        $this->call('Network.enable', [], min(10.0, $timeout));
        $this->call('Log.enable', [], min(10.0, $timeout));
        $this->diagnosticsEnabled = true;
        // A new document starts a new diagnostic story; keeping the old lines would blame the
        // wrong page. Frame sessions belong to the old document too. Stale events would also let a
        // lifecycle event from the previous document end this navigation before it starts.
        $this->events = [];
        $this->diagnostics = [];
        $this->frames = [];
        $this->frameSessions = [];
        $this->frameByOwner = [];
        $this->frameOffsets = [];
        $this->navigationStatus = 0;
        $this->navigationHeaders = [];
        $navigated = $this->call('Page.navigate', ['url' => $url], min(15.0, $timeout));
        // Chrome still resolves with a loader id when the navigation itself failed, and the tab
        // holds an error page. Reading that error page as the document hides the real cause.
        $errorText = $navigated['errorText'] ?? null;
        if (is_string($errorText) && $errorText !== '') {
            Exception::raise('Navigation to "{url}" failed: {error}', [
                'url' => $url,
                'error' => $errorText,
            ]);
        }
        $loaderId = is_string($navigated['loaderId'] ?? null) ? $navigated['loaderId'] : '';
        $this->captureDocumentStatus($loaderId);

        $targetHost = parse_url($url, PHP_URL_HOST);
        $targetHost = is_string($targetHost) ? strtolower($targetHost) : '';

        $deadline = microtime(true) + max(0.5, $timeout);
        while (microtime(true) < $deadline) {
            if ($this->lifecycleSettled($loaderId)) {
                $this->captureDocumentStatus($loaderId);

                return;
            }

            $this->pump(min(0.25, max(0.05, $deadline - microtime(true))));
        }

        // A page that never goes idle — a long poll, a socket, an animation — still has a document
        // worth reading. Report the partial state instead of throwing away what did load.
        if ($this->documentLoaded()) {
            $this->captureDocumentStatus($loaderId);

            return;
        }

        Exception::raise('Timed out waiting for navigation to "{url}".', ['url' => $url]);
    }

    /**
     * Whether this navigation reached a lifecycle point where its content is rendered.
     *
     * Role: one place that knows which CDP signals mean "readable". `networkIdle` fires when the
     * app has fetched what it needs — measured on a real SPA, the DOM at that instant already holds
     * the mounted page — so it is the signal to stop on. `networkAlmostIdle` is the same state with
     * a slightly looser tolerance.
     *
     * Guidance: matched against this navigation's `loaderId`, so an event from the previous document
     * cannot end this one.
     */
    protected function lifecycleSettled(string $loaderId): bool
    {
        foreach ($this->events as $event) {
            if ($event['method'] !== 'Page.lifecycleEvent') {
                continue;
            }
            $params = $event['params'];
            if ($loaderId !== '' && ($params['loaderId'] ?? '') !== $loaderId) {
                continue;
            }
            $name = (string) ($params['name'] ?? '');
            if ($name === 'networkIdle' || $name === 'networkAlmostIdle') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the document itself finished loading.
     *
     * Role: a page with an open socket never reports `networkIdle`. `load` says the document is
     * there even then, which is enough to hand a partial but readable page back to the caller.
     */
    protected function documentLoaded(): bool
    {
        foreach ($this->events as $event) {
            if ($event['method'] === 'Page.loadEventFired' || $event['method'] === 'Page.domContentEventFired') {
                return true;
            }
        }

        return false;
    }

    /**
     * Block until the page has no request in flight for `$quietFor` seconds.
     *
     * Role: an action can start work — a click that submits a form, a route change that fetches —
     * and the answer is only readable once that work lands. Counting started-minus-finished requests
     * is the page's own report of "busy", so this waits on it instead of on a guessed delay.
     *
     * Guidance: returns when the page went quiet, and also when `$timeout` runs out — a page with a
     * permanent connection is still worth reading.
     */
    public function waitForNetworkIdle(float $timeout = 10.0, float $quietFor = 0.4): bool
    {
        $deadline = microtime(true) + max(0.1, $timeout);
        $quietSince = 0.0;
        while (microtime(true) < $deadline) {
            $this->pump(min(0.1, max(0.02, $deadline - microtime(true))));
            $inflight = $this->inflightRequests();
            if ($inflight > 0) {
                $quietSince = 0.0;
                continue;
            }
            if ($quietSince === 0.0) {
                $quietSince = microtime(true);
                continue;
            }
            if (microtime(true) - $quietSince >= $quietFor) {
                return true;
            }
        }

        return $this->inflightRequests() === 0;
    }

    /** Requests that started and have neither finished nor failed. */
    protected function inflightRequests(): int
    {
        $sent = 0;
        $done = 0;
        foreach ($this->events as $event) {
            if ($event['method'] === 'Network.requestWillBeSent') {
                $sent++;
            } elseif ($event['method'] === 'Network.loadingFinished' || $event['method'] === 'Network.loadingFailed') {
                $done++;
            }
        }

        return max(0, $sent - $done);
    }

    public function documentHtml(float $timeout = 15.0): string
    {
        $result = $this->call('Runtime.evaluate', [
            'expression' => 'document.documentElement ? document.documentElement.outerHTML : document.body.outerHTML',
            'returnByValue' => true,
            'awaitPromise' => false,
        ], $timeout);

        $value = $result['result']['value'] ?? null;
        if (!is_string($value) || $value === '') {
            Exception::raise('CDP Runtime.evaluate returned empty HTML.');
        }

        return $value;
    }

    protected function captureDocumentStatus(string $loaderId): void
    {
        foreach ($this->events as $event) {
            if ($event['method'] !== 'Network.responseReceived') {
                continue;
            }
            $params = $event['params'];
            if (($params['type'] ?? '') !== 'Document') {
                continue;
            }
            if ($loaderId !== '' && ($params['loaderId'] ?? '') !== $loaderId) {
                continue;
            }
            $response = $params['response'] ?? null;
            if (!is_array($response)) {
                continue;
            }
            $status = $response['status'] ?? null;
            if (is_int($status) || (is_string($status) && ctype_digit($status))) {
                $this->navigationStatus = (int) $status;
                $this->navigationHeaders = $this->headerMap($response['headers'] ?? null);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    protected function headerMap(mixed $headers): array
    {
        if (!is_array($headers)) {
            return [];
        }

        $map = [];
        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)) {
                continue;
            }
            $map[$name] = $value;
        }

        return $map;
    }

    /** Whether `document.querySelector` matches `$selector`. */
    public function selectorExists(string $selector, float $timeout = 5.0): bool
    {
        $encoded = json_encode($selector, JSON_THROW_ON_ERROR);
        $result = $this->call('Runtime.evaluate', [
            'expression' => "document.querySelector({$encoded}) !== null",
            'returnByValue' => true,
            'awaitPromise' => false,
        ], $timeout);

        if (isset($result['exceptionDetails'])) {
            Exception::raise('CSS selector {selector} failed in the page.', ['selector' => $selector]);
        }

        return ($result['result']['value'] ?? null) === true;
    }

    /**
     * @return array{ready: string, href: string}
     */
    protected function documentNavigationSnapshot(float $timeout): array
    {
        try {
            $result = $this->call('Runtime.evaluate', [
                'expression' => 'JSON.stringify({ready: document.readyState, href: String(location.href)})',
                'returnByValue' => true,
            ], max(0.5, $timeout));
        } catch (Exception) {
            return ['ready' => '', 'href' => ''];
        }

        $raw = $result['result']['value'] ?? '';
        if (!is_string($raw) || $raw === '') {
            return ['ready' => '', 'href' => ''];
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return ['ready' => '', 'href' => ''];
        }

        return [
            'ready' => is_string($decoded['ready'] ?? null) ? $decoded['ready'] : '',
            'href' => is_string($decoded['href'] ?? null) ? $decoded['href'] : '',
        ];
    }

    public function close(): void
    {
        $this->socket->close();
    }

    /**
     * Child frames of this page, including cross-origin ones.
     *
     * Role: `Page.getFrameTree` lists same-process frames; a cross-origin iframe runs in its own
     * process and appears only as an attached target. Both are reported here, because a caller
     * reading a page must not be told "no iframes" when there is one.
     *
     * Guidance: read this before snapshotting iframes; an empty list means the page is one document.
     *
     * @return list<array{frameId: string, url: string, sessionId: string, sameOrigin: bool, ownerBackendNodeId: int, offset: array{x: float, y: float}}>
     */
    public function frames(float $timeout = 5.0): array
    {
        $sessions = $this->attachChildTargets($timeout);
        $frames = [];

        $tree = $this->call('Page.getFrameTree', [], $timeout);
        $root = $tree['frameTree'] ?? null;
        if (is_array($root)) {
            foreach (($root['childFrames'] ?? []) as $child) {
                if (is_array($child)) {
                    $this->collectFrame($child, $frames);
                }
            }
        }

        // A cross-origin frame is absent from the tree above; it is known only by its target.
        // `offset` is where its document starts in this tab's viewport, so a point inside the frame
        // can be turned into a point this tab's input pipeline accepts.
        foreach ($sessions as $url => $sessionId) {
            [$ownerId, $offset] = $this->frameOwner($sessionId, $timeout);
            $frames['session:' . $sessionId] = [
                'frameId' => '',
                'url' => $url,
                'sessionId' => $sessionId,
                'sameOrigin' => false,
                'ownerBackendNodeId' => $ownerId,
                'offset' => $offset,
            ];
            if ($ownerId > 0) {
                $this->frameByOwner[$ownerId] = $sessionId;
                $this->frameOffsets[$ownerId] = $offset;
            }
        }

        $this->frames = $frames;

        return array_values($frames);
    }

    /**
     * Session that owns the document an `<iframe>` element hosts, when that frame is out of process.
     *
     * Empty when the node is not a known cross-origin frame element, in which case the node lives in
     * this session's own document.
     */
    public function frameSessionForOwner(int $ownerBackendNodeId): string
    {
        return $this->frameByOwner[$ownerBackendNodeId] ?? '';
    }

    /**
     * Where a cross-origin frame's document starts in this tab's viewport.
     *
     * @return array{x: float, y: float}
     */
    public function frameOffsetForOwner(int $ownerBackendNodeId): array
    {
        return $this->frameOffsets[$ownerBackendNodeId] ?? ['x' => 0.0, 'y' => 0.0];
    }

    /**
     * The `<iframe>` element of an out-of-process frame and where its document starts.
     *
     * Role: two facts the parent needs. `ownerBackendNodeId` lets this tab click inside that frame —
     * a backend node id is only meaningful in the session that owns the document. `offset` is the
     * content box origin in this tab's viewport: the element's border offset from the frame's own
     * coordinate system.
     *
     * @return array{0: int, 1: array{x: float, y: float}}
     */
    protected function frameOwner(string $sessionId, float $timeout): array
    {
        try {
            // The frame id is available inside the frame's own session.
            $tree = $this->call('Page.getFrameTree', [], $timeout, $sessionId);
            $frameId = (string) ($tree['frameTree']['frame']['id'] ?? '');
            if ($frameId === '') {
                return [0, ['x' => 0.0, 'y' => 0.0]];
            }

            // `DOM.getFrameOwner` answers in the parent session, so the id it returns is parent-scoped.
            $owner = $this->call('DOM.getFrameOwner', ['frameId' => $frameId], $timeout);
            $ownerId = is_int($owner['backendNodeId'] ?? null) ? $owner['backendNodeId'] : 0;
            if ($ownerId <= 0) {
                return [0, ['x' => 0.0, 'y' => 0.0]];
            }

            $origin = $this->frameContentOrigin($ownerId, $timeout);

            return [$ownerId, $origin];
        } catch (Exception) {
            return [0, ['x' => 0.0, 'y' => 0.0]];
        }
    }

    /**
     * Viewport position of an `<iframe>` element's content box.
     *
     * @return array{x: float, y: float}
     */
    protected function frameContentOrigin(int $ownerBackendNodeId, float $timeout): array
    {
        $resolved = $this->call('DOM.resolveNode', ['backendNodeId' => $ownerBackendNodeId], $timeout);
        $objectId = is_string($resolved['object']['objectId'] ?? null) ? $resolved['object']['objectId'] : '';
        if ($objectId === '') {
            return ['x' => 0.0, 'y' => 0.0];
        }

        $result = $this->call('Runtime.callFunctionOn', [
            'objectId' => $objectId,
            'functionDeclaration' => 'function () {'
                . ' const b = this.getBoundingClientRect();'
                . ' return { x: b.x + this.clientLeft, y: b.y + this.clientTop }; }',
            'returnByValue' => true,
        ], $timeout);
        $value = $result['result']['value'] ?? null;
        if (!is_array($value) || !is_numeric($value['x'] ?? null) || !is_numeric($value['y'] ?? null)) {
            return ['x' => 0.0, 'y' => 0.0];
        }

        return ['x' => (float) $value['x'], 'y' => (float) $value['y']];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, array{frameId: string, url: string, sessionId: string, sameOrigin: bool, ownerBackendNodeId: int, offset: array{x: float, y: float}}> $frames
     */
    protected function collectFrame(array $node, array &$frames): void
    {
        $frame = $node['frame'] ?? null;
        if (!is_array($frame)) {
            return;
        }
        $frameId = (string) ($frame['id'] ?? '');
        if ($frameId !== '') {
            // Frames in this tree share the parent's process, so a frameId reaches their DOM.
            $frames[$frameId] = [
                'frameId' => $frameId,
                'url' => (string) ($frame['url'] ?? ''),
                'sessionId' => '',
                'sameOrigin' => true,
                // A same-origin frame resolves through its frameId; its own coordinates already
                // compose, so no owner id or offset is needed.
                'ownerBackendNodeId' => 0,
                'offset' => ['x' => 0.0, 'y' => 0.0],
            ];
        }

        foreach (($node['childFrames'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectFrame($child, $frames);
            }
        }
    }

    /**
     * Map each out-of-process iframe URL to its flattened session id.
     *
     * Role: Chrome only creates a session for a cross-origin frame. Asking once is cheap; asking
     * again on a page with no iframes does not happen, because the target count stays at one.
     *
     * @return array<string, string>
     */
    protected function attachChildTargets(float $timeout): array
    {
        try {
            $this->call('Target.setAutoAttach', [
                'autoAttach' => true,
                'waitForDebuggerOnStart' => false,
                'flatten' => true,
            ], $timeout);
        } catch (Exception) {
            return $this->frameSessions;
        }

        // `Target.attachedToTarget` is an event, so it may arrive after the reply above. Pump for
        // a moment; `pump()` records sessions as they land.
        $deadline = microtime(true) + min(2.0, max(0.3, $timeout));
        while (microtime(true) < $deadline) {
            $this->pump(0.05);
        }

        return $this->frameSessions;
    }

    /**
     * Remember the flattened session of an out-of-process iframe.
     *
     * @param array<string, mixed> $params
     */
    protected function recordFrameSession(string $method, array $params): void
    {
        if ($method !== 'Target.attachedToTarget') {
            return;
        }
        $info = $params['targetInfo'] ?? null;
        $sessionId = $params['sessionId'] ?? null;
        if (!is_array($info) || !is_string($sessionId) || $sessionId === '') {
            return;
        }
        if (($info['type'] ?? '') !== 'iframe') {
            return;
        }
        $url = (string) ($info['url'] ?? '');
        if ($url !== '') {
            $this->frameSessions[$url] = $sessionId;
        }
    }

    protected function pump(float $timeout): void
    {
        $raw = $this->socket->tryReceiveText($timeout);
        if ($raw === null) {
            return;
        }
        try {
            $message = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            Exception::raise('Invalid CDP JSON: {error}', ['error' => $e->getMessage()]);
        }

        if (!is_array($message)) {
            return;
        }

        if (isset($message['id'])) {
            $id = (int) $message['id'];
            // Only a reply someone is still waiting for is worth keeping. A reply to a call that
            // already timed out would otherwise sit in the buffer until the next call.
            if (isset($this->awaiting[$id])) {
                $this->pending[$id] = $message;
            }

            return;
        }

        if (isset($message['method'])) {
            $method = (string) $message['method'];
            $params = is_array($message['params'] ?? null) ? $message['params'] : [];

            $this->collectDiagnostic($method, $params);
            $this->settleDialog($method, $params);
            $this->recordFrameSession($method, $params);

            $this->events[] = [
                'method' => $method,
                'params' => $params,
                'sessionId' => is_string($message['sessionId'] ?? null) ? $message['sessionId'] : '',
            ];
            $overflow = count($this->events) - self::MAX_EVENTS;
            if ($overflow > 0) {
                array_splice($this->events, 0, $overflow);
            }
        }
    }

    /**
     * Turn one CDP event into a diagnostic line, when it is one.
     *
     * Role: keep the three things a broken page hides — console output, uncaught exceptions, and
     * failed requests — so a caller can ask "why is this page wrong" without a DevTools window.
     *
     * @param array<string, mixed> $params
     */
    protected function collectDiagnostic(string $method, array $params): void
    {
        $entry = match ($method) {
            'Runtime.consoleAPICalled' => $this->consoleEntry($params),
            'Runtime.exceptionThrown' => $this->exceptionEntry($params),
            'Log.entryAdded' => $this->logEntry($params),
            'Network.loadingFailed' => $this->failedRequestEntry($params),
            'Network.responseReceived' => $this->badResponseEntry($params),
            default => null,
        };
        if ($entry === null) {
            return;
        }

        $this->diagnostics[] = $entry;
        $overflow = count($this->diagnostics) - self::MAX_DIAGNOSTICS;
        if ($overflow > 0) {
            array_splice($this->diagnostics, 0, $overflow);
        }
    }

    /**
     * Console lines, uncaught errors, and failed requests seen so far, oldest first.
     *
     * Guidance: read this after an action to explain a page that did not do what it should. Only
     * `error` lines and failures matter most of the time; `log`/`debug` are noisy by design.
     *
     * A page throws and fetches on its own schedule. This first drains whatever already arrived,
     * then keeps reading only while events are still coming: a request that fired moments ago is
     * still in flight. A quiet page answers on the first empty read, so repeated polling is cheap.
     *
     * @return list<array{type: string, level: string, text: string, url: string, line: int}>
     */
    public function diagnostics(): array
    {
        $this->pump(0.01);
        $deadline = microtime(true) + self::DIAGNOSTIC_DRAIN;
        while (microtime(true) < $deadline) {
            $before = count($this->diagnostics);
            $this->pump(0.05);
            if (count($this->diagnostics) === $before) {
                // Nothing new arrived in this slice: the page has gone quiet.
                break;
            }
        }

        return $this->diagnostics;
    }

    /** Switch on the domains diagnostics are built from. Cheap and idempotent. */
    public function enableDiagnostics(float $timeout = 10.0): void
    {
        if ($this->diagnosticsEnabled) {
            return;
        }
        $this->call('Runtime.enable', [], $timeout);
        $this->call('Log.enable', [], $timeout);
        $this->call('Network.enable', [], $timeout);
        $this->diagnosticsEnabled = true;
    }

    /**
     * Answer a `alert` / `confirm` / `prompt` as it opens.
     * Role: an open dialog blocks every later command on this session until it is dismissed — the
     * call in flight times out, and so does the next one. Handling it here keeps one bad page from
     * costing the caller its whole budget.
     *
     * @param array<string, mixed> $params
     */
    protected function settleDialog(string $method, array $params): void
    {
        if ($method !== 'Page.javascriptDialogOpening' || !$this->autoAcceptDialogs) {
            return;
        }

        $type = (string) ($params['type'] ?? '');
        $payload = [
            'accept' => true,
            'promptText' => $type === 'prompt' ? $this->autoAcceptPromptText : '',
        ];
        try {
            // A dialog can close between the event and this reply; that is not an error.
            $this->call('Page.handleJavaScriptDialog', $payload, 5.0);
        } catch (Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{type: string, level: string, text: string, url: string, line: int}
     */
    protected function consoleEntry(array $params): array
    {
        $parts = [];
        $args = $params['args'] ?? null;
        if (is_array($args)) {
            foreach ($args as $arg) {
                if (!is_array($arg)) {
                    continue;
                }
                // `value` covers primitives; `description` covers objects, errors, and functions.
                $value = $arg['value'] ?? null;
                if (is_scalar($value)) {
                    $parts[] = (string) $value;
                } elseif (is_string($arg['description'] ?? null)) {
                    $parts[] = $arg['description'];
                } elseif (is_string($arg['unserializableValue'] ?? null)) {
                    $parts[] = $arg['unserializableValue'];
                }
            }
        }

        $stack = $params['stackTrace']['callFrames'][0] ?? null;
        $url = is_array($stack) && is_string($stack['url'] ?? null) ? $stack['url'] : '';
        $line = is_array($stack) && is_int($stack['lineNumber'] ?? null) ? $stack['lineNumber'] + 1 : 0;

        return [
            'type' => 'console',
            'level' => (string) ($params['type'] ?? 'log'),
            'text' => implode(' ', $parts),
            'url' => $url,
            'line' => $line,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{type: string, level: string, text: string, url: string, line: int}
     */
    protected function exceptionEntry(array $params): array
    {
        $details = $params['exceptionDetails'] ?? null;
        $text = 'Uncaught exception';
        $url = '';
        $line = 0;
        if (is_array($details)) {
            $exception = $details['exception'] ?? null;
            if (is_array($exception) && is_string($exception['description'] ?? null)) {
                $text = $exception['description'];
            } elseif (is_string($details['text'] ?? null)) {
                $text = $details['text'];
            }
            $url = is_string($details['url'] ?? null) ? $details['url'] : '';
            $line = is_int($details['lineNumber'] ?? null) ? $details['lineNumber'] + 1 : 0;
        }

        return ['type' => 'exception', 'level' => 'error', 'text' => $text, 'url' => $url, 'line' => $line];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{type: string, level: string, text: string, url: string, line: int}|null
     */
    protected function logEntry(array $params): ?array
    {
        $entry = $params['entry'] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $level = (string) ($entry['level'] ?? 'info');
        // `verbose` is Chrome's own noise; only actionable levels are worth keeping.
        if ($level === 'verbose') {
            return null;
        }

        return [
            'type' => 'log',
            'level' => $level,
            'text' => (string) ($entry['text'] ?? ''),
            'url' => (string) ($entry['url'] ?? ''),
            'line' => is_int($entry['lineNumber'] ?? null) ? $entry['lineNumber'] + 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array{type: string, level: string, text: string, url: string, line: int}
     */
    protected function failedRequestEntry(array $params): array
    {
        $reason = (string) ($params['errorText'] ?? 'request failed');
        // An aborted request is what `close()` and navigation do to in-flight calls.
        $level = str_contains($reason, 'ERR_ABORTED') ? 'debug' : 'error';

        return [
            'type' => 'request',
            'level' => $level,
            'text' => $reason,
            'url' => (string) ($params['documentURL'] ?? ''),
            'line' => 0,
        ];
    }

    /**
     * An HTTP error response (`>= 400`). Rescues the `404`/`500` that `status()` never sees.
     *
     * @param array<string, mixed> $params
     *
     * @return array{type: string, level: string, text: string, url: string, line: int}|null
     */
    protected function badResponseEntry(array $params): ?array
    {
        $response = $params['response'] ?? null;
        if (!is_array($response)) {
            return null;
        }
        $status = $response['status'] ?? null;
        if (!is_int($status) && !(is_string($status) && ctype_digit($status))) {
            return null;
        }
        $status = (int) $status;
        if ($status < 400) {
            return null;
        }

        return [
            'type' => 'response',
            'level' => 'error',
            'text' => $status . ' ' . (string) ($response['statusText'] ?? ''),
            'url' => (string) ($response['url'] ?? ''),
            'line' => 0,
        ];
    }
}
