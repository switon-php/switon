<?php

declare(strict_types=1);

namespace Switon\Cdp;

use stdClass;
use Throwable;

use function array_map;
use function array_values;
use function base64_decode;
use function count;
use function ctype_digit;
use function is_array;
use function is_numeric;
use function is_scalar;
use function is_string;
use function json_encode;
use function max;
use function microtime;
use function min;
use function str_starts_with;
use function strtolower;
use function substr;
use function trim;
use function usleep;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;

/**
 * One open tab and its connection. Same role as Playwright's `Page`.
 *
 * Guidance: callers use {@see PageInterface}. This class holds the connection.
 *
 * Road-signs:
 * - element addressing: {@see Page::elementPoint()} resolves a `backendNodeId`, which reaches
 *   nodes a CSS selector cannot — a shadow root, or a same-origin iframe
 * - diagnostics: {@see Page::diagnostics()} reports console output, uncaught errors, and failure
 * - iframes: {@see Page::frames()} and {@see Page::frameSnapshot()}
 */
class Page implements PageInterface
{
    /**
     * Builds a CSS selector for the element bound to `this`.
     *
     * Role: a human-readable label for a node. Addressing uses `backendNodeId` instead, because a
     * selector built this way cannot reach a node inside a shadow root or another document.
     */
    protected const SELECTOR_JS = <<<'JS'
function () {
  const el = this;
  if (!el || el.nodeType !== 1) return '';
  if (el.id) return '#' + CSS.escape(el.id);
  const parts = [];
  let node = el;
  while (node && node.nodeType === 1 && node.tagName !== 'HTML') {
    let part = node.tagName.toLowerCase();
    if (node.id) {
      parts.unshift('#' + CSS.escape(node.id));
      break;
    }
    const parent = node.parentElement;
    if (parent) {
      const same = Array.from(parent.children).filter((child) => child.tagName === node.tagName);
      if (same.length > 1) part += ':nth-of-type(' + (same.indexOf(node) + 1) + ')';
    }
    parts.unshift(part);
    node = parent;
  }
  return parts.join(' > ');
}
JS;

    /**
     * Reads tag, box, and computed styles for the bound element.
     *
     * Role: one implementation for both addressing modes — `callFunctionOn` on a resolved node,
     * or `document.querySelector` in the page — so a shadow node and a plain node report the same
     * shape.
     */
    protected const INSPECT_JS = <<<'JS'
function (properties) {
  const el = this;
  if (!el || el.nodeType !== 1) return null;
  const r = el.getBoundingClientRect();
  const cs = getComputedStyle(el);
  const styles = {};
  properties.forEach(function (p) { styles[p] = cs.getPropertyValue(p); });
  return {
    tag: el.tagName.toLowerCase(),
    visible: r.width > 0 && r.height > 0,
    box: { x: r.x, y: r.y, w: r.width, h: r.height },
    styles: styles
  };
}
JS;

    /**
     * Focuses the bound element and selects its contents.
     *
     * Role: one implementation for every addressing mode — a resolved node in a shadow root, a node
     * in a cross-origin frame, or a plain element found by selector.
     */
    protected const SELECT_CONTENT_JS = <<<'JS'
function () {
  const el = this;
  if (!el) return false;
  el.focus();
  if (el.tagName === 'SELECT') return 'select';
  if (typeof el.select === 'function') { el.select(); return true; }
  const range = document.createRange();
  range.selectNodeContents(el);
  const selection = window.getSelection();
  if (!selection) return false;
  selection.removeAllRanges();
  selection.addRange(range);
  return true;
}
JS;

    /**
     * Node count at or below which a tree is treated as an unrendered shell.
     *
     * Role: `RootWebArea` plus a `generic` wrapper is what an empty document reports. A tree that
     * small means the page has nothing to describe, so {@see Page::accessibilityTree()} asks the
     * session to wait for the page to go quiet and reads once more.
     */
    protected const TREE_SHELL_NODES = 6;

    /**
     * Pause between two {@see Page::assert()} checks.
     *
     * Role: an assertion retries the real condition, so the pause only bounds how often the page is
     * asked. Short enough to feel immediate, long enough not to flood the connection.
     */
    protected const ASSERT_POLL_MS = 100;

    /**
     * Element state an assertion can read.
     *
     * Role: what a person can check without knowing CSS — is it there, is it visible, does it say
     * this text, is it enabled, is it checked.
     */
    protected const CONDITION_JS = <<<'JS'
function (kind, expected) {
  const el = this;
  if (!el || el.nodeType !== 1) return { ok: false, actual: '' };
  const box = el.getBoundingClientRect();
  const style = getComputedStyle(el);
  const shown = box.width > 0 && box.height > 0
    && style.visibility !== 'hidden' && style.display !== 'none' && style.opacity !== '0';
  const text = (el.innerText || el.value || el.textContent || '').trim();
  switch (kind) {
    case 'visible':    return { ok: shown, actual: shown ? 'visible' : 'hidden' };
    case 'hidden':     return { ok: !shown, actual: shown ? 'visible' : 'hidden' };
    case 'text':       return { ok: text === String(expected), actual: text };
    case 'text_contains': return { ok: text.includes(String(expected)), actual: text };
    case 'value':      return { ok: String(el.value ?? '') === String(expected), actual: String(el.value ?? '') };
    case 'enabled':    return { ok: !el.disabled, actual: el.disabled ? 'disabled' : 'enabled' };
    case 'checked':    return { ok: !!el.checked, actual: el.checked ? 'checked' : 'unchecked' };
    case 'count':      return { ok: true, actual: '1' };
    default:           return { ok: false, actual: 'unknown condition: ' + kind };
  }
}
JS;

    protected bool $closed = false;

    public function __construct(
        protected readonly Session $session,
        protected readonly string $targetId,
        protected readonly Chrome $chrome,
        /** Per-call budget, fixed when the tab opens so one caller cannot retune another's tab. */
        protected readonly float $timeout,
    ) {
    }

    /** Document HTML of this tab. */
    public function content(): string
    {
        return $this->session->documentHtml($this->timeout);
    }

    public function status(): int
    {
        return $this->session->navigationStatus();
    }

    /**
     * Response headers of the last navigation.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->session->navigationHeaders();
    }

    public function url(): string
    {
        $this->guardOpen();
        $value = $this->evaluate('location.href');

        return is_string($value) ? $value : '';
    }

    public function navigate(string $url): void
    {
        $this->guardOpen();
        $url = trim($url);
        if ($url === '') {
            Exception::raise('URL must not be empty.');
        }

        $this->session->navigateAndWait($url, $this->timeout);
    }

    public function wait(string $selector): void
    {
        $selector = trim($selector);
        if ($selector === '') {
            Exception::raise('Selector must not be empty.');
        }

        $deadline = microtime(true) + $this->timeout;
        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return;
            }
            if ($this->session->selectorExists($selector, min(5.0, $remaining))) {
                return;
            }
            usleep((int) min(200_000, $remaining * 1_000_000));
        }
    }

    public function click(string $selector): void
    {
        $this->guardOpen();
        $selector = $this->checkedSelector($selector);
        $this->mouseClick($this->locate($selector));
    }

    public function fill(string $selector, string $text, bool $submit = false): void
    {
        $this->guardOpen();
        $selector = $this->checkedSelector($selector);
        $location = $this->locate($selector);
        $this->mouseClick($location);

        // Select the whole field so the typed text replaces it. `select()` works on input and
        // textarea only, so a contenteditable node gets an explicit range over its contents.
        $focused = $this->selectElementContent($location);
        if ($focused === 'select') {
            Exception::raise(
                'Selector "{selector}" is a <select>, which takes no typed text. Click the element to open it, then click the option you want.',
                ['selector' => $selector],
            );
        }
        if ($focused !== true) {
            Exception::raise('Selector "{selector}" is not a field that can take text.', ['selector' => $selector]);
        }

        if ($text === '') {
            $this->pressKey('Backspace', 8);
        } else {
            $this->session->call('Input.insertText', ['text' => $text], min(15.0, $this->timeout));
        }
        if ($submit) {
            $this->pressKey('Enter', 13, "\r");
        }
    }

    /**
     * Focus the located element and select its contents, in the document that owns it.
     *
     * Role: `Runtime.evaluate` in the parent document cannot see a node inside a cross-origin frame,
     * so the same gesture runs in the node's own session there.
     */
    protected function selectElementContent(ElementLocation $location): mixed
    {
        $timeout = min(10.0, $this->timeout);

        if ($location->isCrossOriginFrame()) {
            $nodeId = $this->frameNodeId($location->target);
            $resolved = $this->session->call('DOM.resolveNode', ['backendNodeId' => $nodeId], $timeout, $location->sessionId);
            $objectId = is_string($resolved['object']['objectId'] ?? null) ? $resolved['object']['objectId'] : '';
            if ($objectId === '') {
                return false;
            }

            return $this->callOnObject($objectId, self::SELECT_CONTENT_JS, [], $timeout, $location->sessionId);
        }

        $backendNodeId = $this->backendNodeIdOf($this->stripFrameTarget($location->target));
        if ($backendNodeId !== null) {
            $resolved = $this->session->call('DOM.resolveNode', ['backendNodeId' => $backendNodeId], $timeout);
            $objectId = is_string($resolved['object']['objectId'] ?? null) ? $resolved['object']['objectId'] : '';
            if ($objectId === '') {
                return false;
            }

            return $this->callOnObject($objectId, self::SELECT_CONTENT_JS, [], $timeout);
        }

        $encoded = json_encode($location->target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return $this->evaluate(
            "(function(){const el=document.querySelector({$encoded});"
            . " if(!el) return false; return (" . self::SELECT_CONTENT_JS . ").call(el);})()",
        );
    }

    public function screenshot(bool $fullPage = false): string
    {
        $this->guardOpen();
        // Observation is the moment the human wants to see the tab, so this keeps the documented
        // raise. Click, fill, and key presses only activate the tab inside the window.
        $this->chrome->focusTarget($this->targetId);
        $timeout = $this->timeout;
        $params = [
            'format' => 'png',
            'fromSurface' => true,
        ];
        if ($fullPage) {
            $params['captureBeyondViewport'] = true;
        }
        $result = $this->session->call('Page.captureScreenshot', $params, min(30.0, max(10.0, $timeout)));

        $data = $result['data'] ?? null;
        if (!is_string($data) || $data === '') {
            Exception::raise('Page screenshot returned an empty image.');
        }

        $png = base64_decode($data, true);
        if ($png === false || $png === '') {
            Exception::raise('Page screenshot decode failed.');
        }

        return $png;
    }

    /**
     * Accessibility tree of this tab.
     *
     * Guidance: {@see Chrome::open()} and {@see Page::navigate()} return once the document reports
     * `networkIdle`, which is after a framework has mounted. Reading the tree here is therefore a
     * single call: waiting again would be guessing at a delay the navigation already waited out.
     *
     * @return array<string, mixed>
     */
    public function accessibilityTree(): array
    {
        $this->guardOpen();
        $timeout = min(10.0, $this->timeout);
        $this->session->call('Accessibility.enable', [], $timeout);
        $this->session->call('DOM.enable', [], $timeout);

        $tree = $this->session->call('Accessibility.getFullAXTree', [], min(20.0, $this->timeout));

        // A tree holding only its root means the document is still an empty shell. That can happen
        // when the page was rendered before the accessibility tree was switched on, so give it one
        // more read rather than reporting a blank page.
        $nodes = is_array($tree['nodes'] ?? null) ? $tree['nodes'] : [];
        if (count($nodes) <= self::TREE_SHELL_NODES) {
            $this->session->waitForNetworkIdle(2.0);

            return $this->session->call('Accessibility.getFullAXTree', [], min(20.0, $this->timeout));
        }

        return $tree;
    }

    /**
     * Viewport box of every node on the page, keyed by backend node id.
     *
     * Role: tells a snapshot which elements a person can actually see. `DOMSnapshot.captureSnapshot`
     * reports layout for the whole document in one call, so ordering a snapshot by visibility costs
     * no per-node round trip.
     *
     * Guidance: boxes are CSS viewport coordinates, so compare `y` with `viewport()` height.
     *
     * @return array<int, array{x: float, y: float, w: float, h: float}> backendNodeId => box
     */
    public function layout(): array
    {
        $this->guardOpen();
        $timeout = min(15.0, $this->timeout);
        $snapshot = $this->session->call('DOMSnapshot.captureSnapshot', [
            'computedStyles' => [],
            'includePaintOrder' => false,
            'includeDOMRects' => true,
        ], $timeout);

        $document = $snapshot['documents'][0] ?? null;
        if (!is_array($document)) {
            return [];
        }
        $layout = $document['layout'] ?? null;
        if (!is_array($layout)) {
            return [];
        }

        $nodeIndexes = $layout['nodeIndex'] ?? [];
        $bounds = $layout['bounds'] ?? [];
        $backendIds = $document['nodes']['backendNodeId'] ?? [];
        if (!is_array($nodeIndexes) || !is_array($bounds) || !is_array($backendIds)) {
            return [];
        }

        $boxes = [];
        // Bounds and the document scroll are device pixels. `viewport()` is CSS pixels, so divide
        // by the screen scale after removing the scroll. A fixed composer otherwise sits below a
        // CSS viewport that is only half as tall.
        $scrollX = is_numeric($document['scrollOffsetX'] ?? null) ? (float) $document['scrollOffsetX'] : 0.0;
        $scrollY = is_numeric($document['scrollOffsetY'] ?? null) ? (float) $document['scrollOffsetY'] : 0.0;
        $scale = $this->deviceScale();
        foreach (array_values($nodeIndexes) as $i => $index) {
            $rect = $bounds[$i] ?? null;
            if (!is_int($index) || !is_array($rect) || count($rect) < 4) {
                continue;
            }
            $backendId = $backendIds[$index] ?? null;
            if (!is_int($backendId) || $backendId <= 0) {
                continue;
            }
            $boxes[$backendId] = [
                'x' => ((float) $rect[0] - $scrollX) / $scale,
                'y' => ((float) $rect[1] - $scrollY) / $scale,
                'w' => (float) $rect[2] / $scale,
                'h' => (float) $rect[3] / $scale,
            ];
        }

        return $boxes;
    }

    /**
     * CSS pixels per device pixel. Snapshot bounds are device pixels; the viewport is not.
     */
    protected function deviceScale(): float
    {
        try {
            $value = $this->evaluate('window.devicePixelRatio');
        } catch (Throwable) {
            return 1.0;
        }
        if (!is_numeric($value) || (float) $value <= 0.0) {
            return 1.0;
        }

        return (float) $value;
    }

    /**
     * Viewport size and scroll position of this tab.
     *
     * @return array{width: int, height: int, scrollY: int, scrollHeight: int}
     */
    public function viewport(): array
    {
        $this->guardOpen();
        $value = $this->evaluate(
            'JSON.stringify({width: innerWidth, height: innerHeight,'
            . ' scrollY: Math.round(scrollY), scrollHeight: document.documentElement.scrollHeight})',
        );
        if (!is_string($value)) {
            return ['width' => 0, 'height' => 0, 'scrollY' => 0, 'scrollHeight' => 0];
        }
        $decoded = json_decode($value, true);

        return [
            'width' => is_int($decoded['width'] ?? null) ? $decoded['width'] : 0,
            'height' => is_int($decoded['height'] ?? null) ? $decoded['height'] : 0,
            'scrollY' => is_int($decoded['scrollY'] ?? null) ? $decoded['scrollY'] : 0,
            'scrollHeight' => is_int($decoded['scrollHeight'] ?? null) ? $decoded['scrollHeight'] : 0,
        ];
    }

    /**
     * Move the page to `$y` device pixels, or bring `$selector` into view.
     *
     * Guidance: a snapshot describes the whole document, so scrolling changes which part of it a
     * screenshot shows. `$selector` also accepts `#<backendNodeId>`, as `click()` does.
     */
    public function scrollTo(string $selector = '', int $y = 0): void
    {
        $this->guardOpen();
        $selector = trim($selector);
        if ($selector === '') {
            $this->evaluate('window.scrollTo(0, ' . max(0, $y) . ')');

            return;
        }

        $location = $this->locate($selector);
        if ($location->isCrossOriginFrame()) {
            $nodeId = $this->frameNodeId($location->target);
            $this->session->call(
                'DOM.scrollIntoViewIfNeeded',
                ['backendNodeId' => $nodeId],
                min(10.0, $this->timeout),
                $location->sessionId,
            );

            return;
        }

        $backendNodeId = $this->backendNodeIdOf($location->target);
        if ($backendNodeId !== null) {
            $timeout = min(10.0, $this->timeout);
            $this->session->call('DOM.enable', [], $timeout);
            $this->session->call('DOM.scrollIntoViewIfNeeded', ['backendNodeId' => $backendNodeId], $timeout);

            return;
        }

        // `scrollIntoView` is the browser's own answer for "make this visible".
        $encoded = json_encode($selector, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->evaluate(
            "(function(){const el=document.querySelector({$encoded});"
            . " if(el) el.scrollIntoView({block:'center', inline:'center'});})()",
        );
    }

    public function selector(int $backendNodeId): string
    {
        $this->guardOpen();
        if ($backendNodeId <= 0) {
            Exception::raise('Element id must be positive.');
        }

        $timeout = min(10.0, $this->timeout);
        $this->session->call('DOM.enable', [], $timeout);
        $resolved = $this->session->call('DOM.resolveNode', [
            'backendNodeId' => $backendNodeId,
        ], $timeout);
        $objectId = $resolved['object']['objectId'] ?? null;
        if (!is_string($objectId) || $objectId === '') {
            Exception::raise('Element {id} is not on this page.', ['id' => (string) $backendNodeId]);
        }

        $result = $this->session->call('Runtime.callFunctionOn', [
            'objectId' => $objectId,
            'functionDeclaration' => self::SELECTOR_JS,
            'returnByValue' => true,
        ], $timeout);
        $value = $result['result']['value'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            Exception::raise('Element {id} has no selector.', ['id' => (string) $backendNodeId]);
        }

        return $value;
    }

    /**
     * Send a request from this tab. A `Referer` header is applied by Chrome; other headers go to `fetch`.
     *
     * @param array<string, string> $headers
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function fetch(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        $method = trim($method);
        $url = trim($url);
        if ($method === '' || $url === '') {
            Exception::raise('Request method and URL must not be empty.');
        }

        $referer = null;
        $fetchHeaders = [];
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) === 'referer') {
                $referer = $value;
                continue;
            }
            $fetchHeaders[(string) $name] = $value;
        }

        $timeout = $this->timeout;
        $refererApplied = $referer !== null && trim($referer) !== '';
        if ($refererApplied) {
            $this->session->call('Network.enable', [], min(10.0, $timeout));
            $this->session->call('Network.setExtraHTTPHeaders', [
                'headers' => ['Referer' => (string) $referer],
            ], min(10.0, $timeout));
        }

        $init = [
            'method' => $method,
            'credentials' => 'include',
            'headers' => $fetchHeaders === [] ? new stdClass() : $fetchHeaders,
        ];
        if ($body !== null) {
            $init['body'] = $body;
        }

        $expression = 'fetch(' . json_encode($url, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . ',' . json_encode($init, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
            . ').then(async(r)=>{const headers={};r.headers.forEach((v,k)=>{headers[k]=v});return {status:r.status,body:await r.text(),headers}})';
        try {
            $result = $this->session->call('Runtime.evaluate', [
                'expression' => $expression,
                'awaitPromise' => true,
                'returnByValue' => true,
            ], $timeout);
        } finally {
            // Extra headers are sticky on the tab: they would ride along on the next navigation
            // and on every later request from this page. One `fetch` owns its `Referer`.
            if ($refererApplied) {
                try {
                    $this->session->call('Network.setExtraHTTPHeaders', [
                        'headers' => new stdClass(),
                    ], min(10.0, $timeout));
                } catch (Throwable) {
                }
            }
        }

        if (isset($result['exceptionDetails'])) {
            $message = is_string($result['exceptionDetails']['text'] ?? null)
                ? $result['exceptionDetails']['text']
                : 'page fetch failed';
            Exception::raise('Page fetch failed for "{url}": {error}', ['url' => $url, 'error' => $message]);
        }

        $value = $result['result']['value'] ?? null;
        if (!is_array($value) || !is_numeric($value['status'] ?? null) || !is_string($value['body'] ?? null)) {
            Exception::raise('Page fetch failed for "{url}".', ['url' => $url]);
        }

        return [
            'status' => (int) $value['status'],
            'headers' => $this->headerMap($value['headers'] ?? null),
            'body' => $value['body'],
        ];
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

    /** Bring this tab to the front. */
    public function activate(): void
    {
        if ($this->closed) {
            return;
        }

        $this->chrome->focusTarget($this->targetId);
    }

    /** Close this tab. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        try {
            $this->session->close();
        } catch (Throwable) {
        }
        $this->chrome->dropTarget($this->targetId);
    }

    protected function guardOpen(): void
    {
        if ($this->closed) {
            Exception::raise('Page is closed.');
        }
    }

    protected function checkedSelector(string $selector): string
    {
        $selector = trim($selector);
        if ($selector === '') {
            Exception::raise('Selector must not be empty.');
        }

        return $selector;
    }

    /**
     * Everything needed to click or type in an element: the point to click in this tab's viewport,
     * and the document that must hold focus.
     *
     * Role: a node inside an out-of-process iframe lives in another session. Its coordinates are
     * frame-local, so the frame's `offset` composes them into this tab's viewport, and `sessionId`
     * says where to resolve the node and select its text.
     */
    protected function locate(string $target): ElementLocation
    {
        $frame = $this->frameTarget($target);
        if ($frame !== null) {
            return $this->locateInFrame($target, $frame['sessionId'], $frame['offset']);
        }

        $backendNodeId = $this->backendNodeIdOf($target);
        if ($backendNodeId !== null) {
            [$x, $y] = $this->nodePoint($backendNodeId, $target);

            return new ElementLocation($x, $y, '', ['x' => 0.0, 'y' => 0.0], $target);
        }

        [$x, $y] = $this->selectorPoint($target);

        return new ElementLocation($x, $y, '', ['x' => 0.0, 'y' => 0.0], $target);
    }

    /**
     * The session and offset of the out-of-process frame an element lives in.
     *
     * Grammar: `#<ownerId>:<sessionId>/<nodeId>` is a node inside a cross-origin frame — the owner
     * is the `<iframe>` element in this document, so this tab can find where the frame starts, and
     * the session is where the node id is meaningful.
     *
     * @return array{sessionId: string, nodeId: int, offset: array{x: float, y: float}}|null
     */
    protected function frameTarget(string $target): ?array
    {
        if (!str_contains($target, ':')) {
            return null;
        }
        [$ownerPart, $rest] = explode(':', $target, 2);
        $ownerId = $this->backendNodeIdOf($ownerPart);
        if ($ownerId === null || !str_contains($rest, '/')) {
            return null;
        }
        [$sessionId, $nodePart] = explode('/', $rest, 2);
        if ($sessionId === '' || !ctype_digit($nodePart)) {
            return null;
        }

        return [
            'sessionId' => $sessionId,
            'nodeId' => (int) $nodePart,
            'offset' => $this->session->frameOffsetForOwner($ownerId),
        ];
    }

    /**
     * Centre of a node inside an out-of-process frame, composed into this tab's viewport.
     *
     * @param array{x: float, y: float} $offset
     */
    protected function locateInFrame(string $target, string $sessionId, array $offset): ElementLocation
    {
        if (!str_contains($target, '/')) {
            Exception::raise('Frame target {target} is malformed.', ['target' => $target]);
        }
        $nodeId = (int) substr($target, (int) strrpos($target, '/') + 1);
        if ($nodeId <= 0) {
            Exception::raise('Frame target {target} is malformed.', ['target' => $target]);
        }

        $timeout = min(10.0, $this->timeout);
        $this->session->call('DOM.enable', [], $timeout, $sessionId);
        $this->session->call('DOM.scrollIntoViewIfNeeded', ['backendNodeId' => $nodeId], $timeout, $sessionId);
        $quads = $this->session->call('DOM.getContentQuads', ['backendNodeId' => $nodeId], $timeout, $sessionId);
        $quad = $quads['quads'][0] ?? null;
        if (!is_array($quad) || count($quad) < 8) {
            Exception::raise('Element {target} has no visible box in its frame.', ['target' => $target]);
        }

        $points = array_map(static fn (mixed $v): float => (float) $v, array_values($quad));
        $x = (min([$points[0], $points[2], $points[4], $points[6]]) + max([$points[0], $points[2], $points[4], $points[6]])) / 2;
        $y = (min([$points[1], $points[3], $points[5], $points[7]]) + max([$points[1], $points[3], $points[5], $points[7]])) / 2;

        return new ElementLocation($x + $offset['x'], $y + $offset['y'], $sessionId, $offset, $target);
    }

    /**
     * Centre of a CSS-selected element in this document, checked against what is painted there.
     *
     * @return array{0: float, 1: float}
     */
    protected function selectorPoint(string $target): array
    {
        $encoded = json_encode($target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $value = $this->evaluate(
            "(() => { const el = document.querySelector({$encoded}); if (!el) return null;"
            . " el.scrollIntoView({block:'center', inline:'center'});"
            . " const r = el.getBoundingClientRect();"
            . " const x = r.x + r.width / 2, y = r.y + r.height / 2;"
            // Input lands on whatever is painted at (x, y). Say so instead of clicking a veil.
            . " const hit = document.elementFromPoint(x, y);"
            . " return {x: x, y: y, w: r.width, h: r.height,"
            . " blocked: hit !== null && hit !== el && !el.contains(hit) && !hit.contains(el),"
            . " by: hit === null ? '' : (hit.id ? '#' + hit.id : hit.tagName.toLowerCase())}; })()",
        );
        if (!is_array($value) || !is_numeric($value['x'] ?? null) || !is_numeric($value['y'] ?? null)) {
            Exception::raise('Selector "{selector}" matched nothing.', ['selector' => $target]);
        }

        $width = (float) ($value['w'] ?? 0);
        $height = (float) ($value['h'] ?? 0);
        if ($width <= 0.0 || $height <= 0.0) {
            Exception::raise('Selector "{selector}" has no visible box.', ['selector' => $target]);
        }
        if (($value['blocked'] ?? false) === true) {
            Exception::raise('Selector "{selector}" is covered by {by}, so a click would miss it.', [
                'selector' => $target,
                'by' => (string) ($value['by'] ?? ''),
            ]);
        }

        return [(float) $value['x'], (float) $value['y']];
    }

    /**
     * Centre of a node addressed by `backendNodeId`.
     *
     * `DOM.getContentQuads` reports viewport coordinates, and it already accounts for the offset
     * of the document the node lives in, so a node inside a same-origin iframe comes back at the
     * point a person would click.
     *
     * @return array{0: float, 1: float}
     */
    protected function nodePoint(int $backendNodeId, string $target): array
    {
        $timeout = min(10.0, $this->timeout);
        $this->session->call('DOM.enable', [], $timeout);
        $this->session->call('DOM.scrollIntoViewIfNeeded', ['backendNodeId' => $backendNodeId], $timeout);

        $quads = $this->session->call('DOM.getContentQuads', ['backendNodeId' => $backendNodeId], $timeout);
        $quad = $quads['quads'][0] ?? null;
        if (!is_array($quad) || count($quad) < 8) {
            Exception::raise('Element {target} has no visible box.', ['target' => $target]);
        }

        $points = array_map(static fn (mixed $v): float => (float) $v, array_values($quad));
        $xs = [$points[0], $points[2], $points[4], $points[6]];
        $ys = [$points[1], $points[3], $points[5], $points[7]];
        $x = (min($xs) + max($xs)) / 2;
        $y = (min($ys) + max($ys)) / 2;

        return [$x, $y];
    }

    /**
     * The node id behind a target, when the target is one.
     *
     * Grammar: `#123` is a backend node id. Anything else is a CSS selector, which is also what a
     * plain number would be — hence the `#`.
     */
    protected function backendNodeIdOf(string $target): ?int
    {
        if (!str_starts_with($target, '#') || !ctype_digit(substr($target, 1))) {
            return null;
        }
        $id = (int) substr($target, 1);
        if ($id <= 0) {
            // `#0` is never a node. Treating it as a selector would report a confusing
            // "no such element" for what is a caller bug.
            Exception::raise('Node id must be positive, got "{target}".', ['target' => $target]);
        }

        return $id;
    }

    /**
     * Backend node id at the end of a frame target (`#<owner>:<session>/<nodeId>`).
     */
    protected function frameNodeId(string $target): int
    {
        $slash = strrpos($target, '/');

        return $slash === false ? 0 : (int) substr($target, $slash + 1);
    }

    /** Address without its frame prefix, so the plain forms keep working. */
    protected function stripFrameTarget(string $target): string
    {
        $colon = strpos($target, ':');

        return $colon === false ? $target : substr($target, 0, $colon);
    }

    /**
     * Run JavaScript in this tab and return its value.
     *
     * Guidance: the general-purpose probe — read `localStorage`, compute a style, assert a
     * condition, call an app hook. An exception in the page is re-raised here, so a broken probe
     * says so instead of returning `null`.
     */
    public function evaluate(string $expression): mixed
    {
        $this->guardOpen();
        $result = $this->session->call('Runtime.evaluate', [
            'expression' => $expression,
            'returnByValue' => true,
            'awaitPromise' => true,
        ], $this->timeout);

        if (isset($result['exceptionDetails'])) {
            $details = $result['exceptionDetails'];
            $exception = $details['exception'] ?? null;
            $message = is_array($exception) && is_string($exception['description'] ?? null)
                ? $exception['description']
                : (is_string($details['text'] ?? null) ? $details['text'] : 'page script failed');
            Exception::raise('Page script failed: {error}', ['error' => $message]);
        }

        return $result['result']['value'] ?? null;
    }

    /**
     * Console output, uncaught exceptions, and failed requests seen since the last navigation.
     *
     * Guidance: after an action that did not do what you expected, read this before guessing.
     *
     * @return list<array{type: string, level: string, text: string, url: string, line: int}>
     */
    public function diagnostics(): array
    {
        $this->guardOpen();

        return $this->session->diagnostics();
    }

    /**
     * Computed styles and box of one element.
     *
     * Role: the values behind "why does this look wrong" — `display`, `visibility`, `color`,
     * `z-index`, and the box a person would click. Works for a CSS selector and for `#<id>`.
     *
     * @param list<string> $properties CSS property names; empty reads a common set
     *
     * @return array{target: string, tag: string, visible: bool, box: array<string, float>, styles: array<string, string>}
     */
    public function inspect(string $target, array $properties = []): array
    {
        $this->guardOpen();
        $target = trim($target);
        if ($target === '') {
            Exception::raise('Selector must not be empty.');
        }

        $wanted = $properties === [] ? [
            'display', 'visibility', 'opacity', 'color', 'background-color',
            'font-size', 'position', 'z-index', 'overflow',
        ] : $properties;

        $timeout = min(10.0, $this->timeout);
        $frame = $this->frameTarget($target);
        if ($frame !== null) {
            $this->session->call('DOM.enable', [], $timeout, $frame['sessionId']);
            $resolved = $this->session->call(
                'DOM.resolveNode',
                ['backendNodeId' => $frame['nodeId']],
                $timeout,
                $frame['sessionId'],
            );
            $objectId = is_string($resolved['object']['objectId'] ?? null) ? $resolved['object']['objectId'] : '';
            if ($objectId === '') {
                Exception::raise('Element {target} is not in that frame.', ['target' => $target]);
            }
            $value = $this->callOnObject($objectId, self::INSPECT_JS, [$wanted], $timeout, $frame['sessionId']);
            if (!is_array($value)) {
                Exception::raise('Element {target} could not be inspected.', ['target' => $target]);
            }

            /** @var array{tag: string, visible: bool, box: array<string, float>, styles: array<string, string>} $value */
            return ['target' => $target] + $value;
        }

        $backendNodeId = $this->backendNodeIdOf($target);

        if ($backendNodeId !== null) {
            $this->session->call('DOM.enable', [], $timeout);
            $resolved = $this->session->call('DOM.resolveNode', ['backendNodeId' => $backendNodeId], $timeout);
            $objectId = $resolved['object']['objectId'] ?? null;
            if (!is_string($objectId) || $objectId === '') {
                Exception::raise('Element {target} is not on this page.', ['target' => $target]);
            }
            $value = $this->callOnObject($objectId, self::INSPECT_JS, [$wanted], $timeout);
        } else {
            $encoded = json_encode($target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $props = json_encode($wanted, JSON_THROW_ON_ERROR);
            $value = $this->evaluate(
                "(function(){const el=document.querySelector({$encoded});if(!el)return null;"
                . "return (" . self::INSPECT_JS . ").call(el,{$props});})()",
            );
            if (!is_array($value)) {
                Exception::raise('Selector "{selector}" matched nothing.', ['selector' => $target]);
            }
        }

        if (!is_array($value)) {
            Exception::raise('Element {target} could not be inspected.', ['target' => $target]);
        }

        /** @var array{tag: string, visible: bool, box: array<string, float>, styles: array<string, string>} $value */
        return ['target' => $target] + $value;
    }

    /**
     * Child frames of this page, so an iframe is not a blind spot.
     *
     * Guidance: a cross-origin frame carries `ownerBackendNodeId` and `sessionId`. A node inside it
     * is addressed as `#<ownerBackendNodeId>:<sessionId>/<nodeId>`, which `click()`, `fill()` and
     * `inspect()` accept.
     *
     * @return list<array{frameId: string, url: string, sessionId: string, sameOrigin: bool, ownerBackendNodeId: int, offset: array{x: float, y: float}}>
     */
    public function frames(): array
    {
        $this->guardOpen();

        return $this->session->frames(min(10.0, $this->timeout));
    }

    /**
     * Whether an element on this page satisfies a condition, retried until it does or time runs out.
     *
     * Role: the difference between "I clicked" and "the click worked". A page updates after an
     * action — a request lands, a framework re-renders — so the condition is checked again for a
     * while instead of once. What is retried is the real condition, not a guessed delay.
     *
     * Guidance: `$path` addresses an element like {@see Page::click()} does: a CSS selector,
     * `#<backendNodeId>`, or a cross-origin frame target. `$kind` is `visible`, `hidden`, `text`,
     * `text_contains`, `value`, `enabled`, or `checked`; `$expected` applies to the value kinds.
     *
     * @param string $kind One of the condition names above
     *
     * @return array{ok: bool, actual: string, target: string, kind: string, expected: string, waitedMs: int}
     */
    public function assert(string $path, string $kind = 'visible', string $expected = '', float $timeout = 5.0): array
    {
        $this->guardOpen();
        $path = trim($path);
        if ($path === '') {
            Exception::raise('Assertion target must not be empty.');
        }
        $kind = trim($kind);
        if ($kind === '') {
            Exception::raise('Assertion kind must not be empty.');
        }

        $deadline = microtime(true) + max(0.1, $timeout);
        $started = microtime(true);
        $actual = '';
        $ok = false;
        do {
            [$ok, $actual] = $this->readCondition($path, $kind, $expected);
            if ($ok) {
                break;
            }
            // The page needs a moment to react to whatever the caller just did.
            usleep(self::ASSERT_POLL_MS * 1000);
        } while (microtime(true) < $deadline);

        return [
            'ok' => $ok,
            'actual' => $actual,
            'target' => $path,
            'kind' => $kind,
            'expected' => $expected,
            'waitedMs' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    /**
     * Read one condition for `$path`, in whichever document owns it.
     *
     * @return array{0: bool, 1: string} [satisfied, actual value for the message]
     */
    protected function readCondition(string $path, string $kind, string $expected): array
    {
        $timeout = min(10.0, $this->timeout);
        $frame = $this->frameTarget($path);
        if ($frame !== null) {
            $this->session->call('DOM.enable', [], $timeout, $frame['sessionId']);
            $resolved = $this->session->call(
                'DOM.resolveNode',
                ['backendNodeId' => $frame['nodeId']],
                $timeout,
                $frame['sessionId'],
            );
            $objectId = is_string($resolved['object']['objectId'] ?? null) ? $resolved['object']['objectId'] : '';
            if ($objectId === '') {
                return [false, 'element not in that frame'];
            }

            return $this->conditionResult($objectId, $kind, $expected, $timeout, $frame['sessionId']);
        }

        $backendNodeId = $this->backendNodeIdOf($path);
        if ($backendNodeId !== null) {
            try {
                $this->session->call('DOM.enable', [], $timeout);
                $resolved = $this->session->call('DOM.resolveNode', ['backendNodeId' => $backendNodeId], $timeout);
            } catch (Exception) {
                return [false, 'element not on this page'];
            }
            $objectId = is_string($resolved['object']['objectId'] ?? null) ? $resolved['object']['objectId'] : '';
            if ($objectId === '') {
                return [false, 'element not on this page'];
            }

            return $this->conditionResult($objectId, $kind, $expected, $timeout);
        }

        $encoded = json_encode($path, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $kindJson = json_encode($kind, JSON_THROW_ON_ERROR);
        $expectedJson = json_encode($expected, JSON_THROW_ON_ERROR);
        $value = $this->evaluate(
            "(function(){const el=document.querySelector({$encoded});"
            . " if(!el) return {ok:false, actual:'no element matches ' + " . $encoded . "};"
            . " return (" . self::CONDITION_JS . ").call(el,{$kindJson},{$expectedJson});})()",
        );
        if (!is_array($value)) {
            return [false, 'no element matches'];
        }

        return [(bool) ($value['ok'] ?? false), (string) ($value['actual'] ?? '')];
    }

    /**
     * @return array{0: bool, 1: string}
     */
    protected function conditionResult(
        string $objectId,
        string $kind,
        string $expected,
        float $timeout,
        string $sessionId = '',
    ): array {
        $value = $this->callOnObject($objectId, self::CONDITION_JS, [$kind, $expected], $timeout, $sessionId);
        if (!is_array($value)) {
            return [false, 'condition could not be read'];
        }

        return [(bool) ($value['ok'] ?? false), (string) ($value['actual'] ?? '')];
    }

    /**
     * Accessibility tree of a child frame.
     *
     * Guidance: a snapshot of the top document will not contain iframe content. Pass the whole entry
     * from {@see Page::frames()} — an out-of-process frame answers on its own session.
     *
     * @param array{frameId: string, sessionId: string}|null $frame An entry from {@see Page::frames()}
     *
     * @return array<string, mixed>
     */
    public function frameSnapshot(?array $frame = null): array
    {
        $this->guardOpen();
        $timeout = min(10.0, $this->timeout);
        $sessionId = is_string($frame['sessionId'] ?? null) ? $frame['sessionId'] : '';
        $this->session->call('Accessibility.enable', [], $timeout, $sessionId);

        $frameId = is_string($frame['frameId'] ?? null) ? $frame['frameId'] : '';
        $params = $frameId === '' ? [] : ['frameId' => $frameId];

        return $this->session->call(
            'Accessibility.getFullAXTree',
            $params,
            min(20.0, $this->timeout),
            $sessionId,
        );
    }

    /**
     * Run JavaScript inside a cross-origin frame.
     *
     * Role: an out-of-process iframe has its own session; its document is unreachable from the
     * parent. Same-origin frames need no sessionId — their document is reachable through
     * `contentDocument`, or use {@see Page::frameSnapshot()} with the `frameId`.
     */
    public function frameEvaluate(string $sessionId, string $expression): mixed
    {
        $this->guardOpen();
        $sessionId = trim($sessionId);
        if ($sessionId === '') {
            Exception::raise('Session id must not be empty; call frames() first.');
        }

        $result = $this->session->call('Runtime.evaluate', [
            'expression' => $expression,
            'returnByValue' => true,
            'awaitPromise' => true,
        ], $this->timeout, $sessionId);

        if (isset($result['exceptionDetails'])) {
            $details = $result['exceptionDetails'];
            $exception = $details['exception'] ?? null;
            $message = is_array($exception) && is_string($exception['description'] ?? null)
                ? $exception['description']
                : 'frame script failed';
            Exception::raise('Frame script failed: {error}', ['error' => $message]);
        }

        return $result['result']['value'] ?? null;
    }

    /**
     * @param list<mixed> $arguments Positional arguments, passed by value
     */
    protected function callOnObject(
        string $objectId,
        string $function,
        array $arguments,
        float $timeout,
        string $sessionId = '',
    ): mixed {
        $result = $this->session->call('Runtime.callFunctionOn', [
            'objectId' => $objectId,
            'functionDeclaration' => $function,
            'arguments' => array_map(
                static fn (mixed $value): array => ['value' => $value],
                $arguments,
            ),
            'returnByValue' => true,
        ], $timeout, $sessionId);

        if (isset($result['exceptionDetails'])) {
            $details = $result['exceptionDetails'];
            $message = is_string($details['text'] ?? null) ? $details['text'] : 'page script failed';
            Exception::raise('Page script failed: {error}', ['error' => $message]);
        }

        return $result['result']['value'] ?? null;
    }

    /**
     * Click at a located point.
     *
     * Input is dispatched on the tab's main session even when the element lives in a cross-origin
     * frame: the point is already composed into this tab's viewport, and the browser routes it to
     * whichever frame is painted there.
     */
    protected function mouseClick(ElementLocation $location): void
    {
        $this->bringToFront();
        $timeout = min(5.0, $this->timeout);
        $x = $location->x;
        $y = $location->y;
        $this->session->call('Input.dispatchMouseEvent', [
            'type' => 'mouseMoved',
            'x' => $x,
            'y' => $y,
            'button' => 'none',
            'buttons' => 0,
            'clickCount' => 0,
            'pointerType' => 'mouse',
        ], $timeout);
        $this->session->call('Input.dispatchMouseEvent', [
            'type' => 'mousePressed',
            'x' => $x,
            'y' => $y,
            'button' => 'left',
            'buttons' => 1,
            'clickCount' => 1,
            'pointerType' => 'mouse',
        ], $timeout);
        $this->session->call('Input.dispatchMouseEvent', [
            'type' => 'mouseReleased',
            'x' => $x,
            'y' => $y,
            'button' => 'left',
            'buttons' => 0,
            'clickCount' => 1,
            'pointerType' => 'mouse',
        ], $timeout);
    }

    /**
     * Press `$key` once. `$text` is what the key inserts into a focused field: without it Chrome
     * reports the key but performs no default action, so Enter never submits a form.
     */
    protected function pressKey(string $key, int $code, string $text = ''): void
    {
        $this->bringToFront();
        $timeout = min(5.0, $this->timeout);
        $down = [
            'type' => 'keyDown',
            'key' => $key,
            'code' => $key,
            'windowsVirtualKeyCode' => $code,
            'nativeVirtualKeyCode' => $code,
        ];
        if ($text !== '') {
            $down['text'] = $text;
            $down['unmodifiedText'] = $text;
        }
        $this->session->call('Input.dispatchKeyEvent', $down, $timeout);
        $this->session->call('Input.dispatchKeyEvent', [
            'type' => 'keyUp',
            'key' => $key,
            'code' => $key,
            'windowsVirtualKeyCode' => $code,
            'nativeVirtualKeyCode' => $code,
        ], $timeout);
    }

    /**
     * Make this tab the active tab of its window.
     *
     * Role: the precondition for Input events. Chrome drops `Input.*` on a background tab —
     * the call never answers — so a click or key press has to focus the tab first.
     *
     * Guidance: this is a tab-level activation, not a window raise. It costs one round trip on an
     * open connection and leaves the operating system's frontmost application alone.
     */
    protected function bringToFront(): void
    {
        $this->session->call('Page.bringToFront', [], min(5.0, $this->timeout));
    }
}
