<?php

declare(strict_types=1);

namespace Switon\Browser\Tests\Unit;

use RuntimeException;
use Switon\Cdp\Exception as CdpException;
use Switon\Cdp\PageInterface;

/**
 * Page that records agent actions. No Chrome.
 */
final class FakePage implements PageInterface
{
    public string $html = '<html></html>';

    public string $href = 'https://example.com/';

    /** @var array<string, mixed> */
    public array $ax = ['nodes' => []];

    /** @var array<int, array{x: float, y: float, w: float, h: float}> */
    public array $boxes = [];

    /** @var array{width: int, height: int, scrollY: int, scrollHeight: int} */
    public array $view = ['width' => 800, 'height' => 600, 'scrollY' => 0, 'scrollHeight' => 600];

    /** @var list<array{frameId: string, url: string, sessionId: string, sameOrigin: bool, ownerBackendNodeId: int, offset: array{x: float, y: float}}> */
    public array $childFrames = [];

    /** @var array<string, array<string, mixed>> */
    public array $frameTrees = [];

    /** @var list<string> */
    public array $clicked = [];

    /** @var list<array{0: string, 1: string, 2: bool}> */
    public array $filled = [];

    /** @var list<array{0: string, 1: int}> */
    public array $scrolled = [];

    public bool $closed = false;

    public string $waited = '';

    public string $navigated = '';

    public string $png = "\x89PNG\r\n\x1a\n";

    /** @var list<array{type: string, level: string, text: string, url: string, line: int}> */
    public array $console = [];

    /** @var array<string, mixed> */
    public array $inspected = [];

    /** @var array{ok: bool, actual: string, target: string, kind: string, expected: string, waitedMs: int} */
    public array $assertion = [
        'ok' => true,
        'actual' => 'visible',
        'target' => '',
        'kind' => 'visible',
        'expected' => '',
        'waitedMs' => 0,
    ];

    public mixed $scriptResult = null;

    /** CDP method name that should fail (`layout`, `frames`, `frameSnapshot`, `click`). */
    public string $cdpFail = '';

    public bool $closeThrows = false;

    public function content(): string
    {
        return $this->html;
    }

    public function status(): int
    {
        return 200;
    }

    public function headers(): array
    {
        return [];
    }

    public function url(): string
    {
        return $this->href;
    }

    public function navigate(string $url): void
    {
        $this->navigated = $url;
        $this->href = $url;
    }

    public function wait(string $selector): void
    {
        $this->waited = $selector;
    }

    public function click(string $selector): void
    {
        $this->failIf('click');
        $this->clicked[] = $selector;
    }

    public function fill(string $selector, string $text, bool $submit = false): void
    {
        $this->filled[] = [$selector, $text, $submit];
    }

    public function screenshot(bool $fullPage = false): string
    {
        return $this->png;
    }

    public function accessibilityTree(): array
    {
        return $this->ax;
    }

    public function layout(): array
    {
        $this->failIf('layout');

        return $this->boxes;
    }

    public function viewport(): array
    {
        return $this->view;
    }

    public function scrollTo(string $selector = '', int $y = 0): void
    {
        $this->scrolled[] = [$selector, $y];
    }

    public function selector(int $backendNodeId): string
    {
        return '#' . $backendNodeId;
    }

    public function evaluate(string $expression): mixed
    {
        return $this->scriptResult ?? $expression;
    }

    public function diagnostics(): array
    {
        return $this->console;
    }

    public function inspect(string $target, array $properties = []): array
    {
        return $this->inspected + ['target' => $target];
    }

    public function assert(string $path, string $kind = 'visible', string $expected = '', float $timeout = 5.0): array
    {
        return $this->assertion + ['target' => $path, 'kind' => $kind, 'expected' => $expected];
    }

    public function frames(): array
    {
        $this->failIf('frames');

        return $this->childFrames;
    }

    public function frameSnapshot(?array $frame = null): array
    {
        $this->failIf('frameSnapshot');
        $id = is_string($frame['frameId'] ?? null) ? $frame['frameId'] : '';

        return $this->frameTrees[$id] ?? [];
    }

    public function frameEvaluate(string $sessionId, string $expression): mixed
    {
        return $expression;
    }

    public function fetch(string $method, string $url, array $headers = [], ?string $body = null): array
    {
        return ['status' => 200, 'headers' => [], 'body' => ''];
    }

    public function activate(): void
    {
    }

    public function close(): void
    {
        if ($this->closeThrows) {
            throw new RuntimeException('tab already gone');
        }
        $this->closed = true;
    }

    private function failIf(string $method): void
    {
        if ($this->cdpFail === $method) {
            CdpException::raise($method . ' failed');
        }
    }
}
