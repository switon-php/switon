<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Exception;
use Switon\Cdp\HeadlessChrome;
use Switon\Cdp\Page;
use Switon\Cdp\Session;

#[CoversClass(Page::class)]
class PageTest extends TestCase
{
    #[Test]
    public function contentReturnsTheDocument(): void
    {
        $page = $this->page($this->socket());

        $this->assertSame('<html></html>', $page->content());
    }

    #[Test]
    public function navigateRejectsAnEmptyUrl(): void
    {
        $page = $this->page($this->socket());

        $this->expectException(Exception::class);
        $page->navigate('   ');
    }

    #[Test]
    public function evaluateReturnsTheValueAndReportsAPageException(): void
    {
        $page = $this->page($this->socket());

        $this->assertSame('https://example.com/', $page->url());

        $socket = $this->socket();
        $socket->results['Runtime.evaluate'] = static function (array $message): array {
            $expression = (string) ($message['params']['expression'] ?? '');
            if (str_contains($expression, 'throw')) {
                return ['exceptionDetails' => ['exception' => ['description' => 'ReferenceError: x']]];
            }

            return ['result' => ['value' => null]];
        };
        $page = $this->page($socket);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('ReferenceError: x');
        $page->evaluate('throw 1');
    }

    #[Test]
    public function clickDispatchesThePointerAtTheElementCentre(): void
    {
        $socket = $this->socket();
        $page = $this->page($socket);

        $page->click('#save');

        $pressed = $this->sentParams($socket, 'Input.dispatchMouseEvent', 'mousePressed');
        $this->assertEquals(10, $pressed['x']);
        $this->assertEquals(20, $pressed['y']);
    }

    #[Test]
    public function clickRefusesAnElementCoveredBySomethingElse(): void
    {
        $socket = $this->socket();
        $socket->results['Runtime.evaluate'] = static fn (): array => [
            'result' => ['value' => ['x' => 1, 'y' => 2, 'w' => 10, 'h' => 10, 'blocked' => true, 'by' => '#modal']],
        ];
        $page = $this->page($socket);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('covered by');
        $page->click('#save');
    }

    #[Test]
    public function clickOnANodeIdUsesItsContentQuad(): void
    {
        $socket = $this->socket();
        $socket->results['DOM.getContentQuads'] = ['quads' => [[0, 0, 20, 0, 20, 10, 0, 10]]];
        $page = $this->page($socket);

        $page->click('#5');

        $pressed = $this->sentParams($socket, 'Input.dispatchMouseEvent', 'mousePressed');
        $this->assertEquals(10, $pressed['x']);
        $this->assertEquals(5, $pressed['y']);
    }

    #[Test]
    public function screenshotDecodesThePngAndRejectsAnEmptyImage(): void
    {
        $socket = $this->socket();
        $socket->results['Page.captureScreenshot'] = ['data' => base64_encode('png-bytes')];
        $page = $this->page($socket);

        $this->assertSame('png-bytes', $page->screenshot());

        $socket->results['Page.captureScreenshot'] = ['data' => ''];
        $this->expectException(Exception::class);
        $page->screenshot();
    }

    #[Test]
    public function layoutConvertsDevicePixelsToCssPixels(): void
    {
        $socket = $this->socket();
        $socket->results['DOMSnapshot.captureSnapshot'] = [
            'documents' => [[
                'scrollOffsetX' => 0,
                'scrollOffsetY' => 0,
                'nodes' => ['backendNodeId' => [7]],
                'layout' => [
                    'nodeIndex' => [0],
                    'bounds' => [[20, 40, 100, 50]],
                ],
            ]],
        ];
        $page = $this->page($socket);

        $this->assertSame(
            ['x' => 10.0, 'y' => 20.0, 'w' => 50.0, 'h' => 25.0],
            $page->layout()[7],
        );
    }

    #[Test]
    public function assertReportsAConditionThatAlreadyHolds(): void
    {
        $page = $this->page($this->socket());

        $result = $page->assert('#status', 'text', 'Saved', 0.2);

        $this->assertTrue($result['ok']);
        $this->assertSame('Saved', $result['actual']);
        $this->assertSame('text', $result['kind']);
    }

    #[Test]
    public function fetchReturnsTheResponseFromThePage(): void
    {
        $page = $this->page($this->socket());

        $response = $page->fetch('POST', 'https://example.com/api', [], '{"q":1}');

        $this->assertSame(201, $response['status']);
        $this->assertSame('ok', $response['body']);
        $this->assertSame('application/json', $response['headers']['content-type']);
    }

    #[Test]
    public function closeIsSafeToRepeat(): void
    {
        $socket = $this->socket();
        $page = $this->page($socket);

        $page->close();
        $page->close();

        $this->assertTrue($socket->closed);
    }

    #[Test]
    public function navigateThenReportsTheDocumentStatus(): void
    {
        $socket = $this->socket();
        $socket->results['Page.navigate'] = ['loaderId' => 'L1'];
        $socket->then['Page.navigate'] = [
            [
                'method' => 'Network.responseReceived',
                'params' => [
                    'type' => 'Document',
                    'loaderId' => 'L1',
                    'response' => ['status' => 204, 'headers' => ['content-type' => 'text/html']],
                ],
            ],
            [
                'method' => 'Page.lifecycleEvent',
                'params' => ['name' => 'networkIdle', 'loaderId' => 'L1'],
            ],
        ];
        $page = $this->page($socket);

        $page->navigate('https://example.com/');

        $this->assertSame(204, $page->status());
        $this->assertSame('text/html', $page->headers()['content-type']);
    }

    #[Test]
    public function waitReturnsOnceTheSelectorMatches(): void
    {
        $page = $this->page($this->socket(), 0.4);

        $page->wait('main');
        $this->expectException(Exception::class);
        $page->wait('   ');
    }

    #[Test]
    public function fillReplacesTheFieldAndCanSubmit(): void
    {
        $socket = $this->socket();
        $page = $this->page($socket);

        $page->fill('#name', 'Ada', true);

        $inserted = $this->sentParams($socket, 'Input.insertText', '');
        $this->assertSame('Ada', $inserted['text']);
        $enter = $this->sentParams($socket, 'Input.dispatchKeyEvent', 'keyDown');
        $this->assertSame('Enter', $enter['key']);
    }

    #[Test]
    public function accessibilityTreeReturnsTheNodesChromeReported(): void
    {
        $socket = $this->socket();
        $nodes = [];
        for ($i = 0; $i < 8; $i++) {
            $nodes[] = ['role' => ['value' => 'button']];
        }
        $socket->results['Accessibility.getFullAXTree'] = ['nodes' => $nodes];
        $page = $this->page($socket);

        $this->assertCount(8, $page->accessibilityTree()['nodes']);
    }

    #[Test]
    public function viewportReadsTheWindowSize(): void
    {
        $page = $this->page($this->socket());

        $this->assertSame(
            ['width' => 1200, 'height' => 800, 'scrollY' => 0, 'scrollHeight' => 800],
            $page->viewport(),
        );
    }

    #[Test]
    public function scrollToMovesTheDocumentOrANode(): void
    {
        $socket = $this->socket();
        $socket->results['DOM.getContentQuads'] = ['quads' => [[0, 0, 8, 0, 8, 8, 0, 8]]];
        $page = $this->page($socket);

        $page->scrollTo('', 400);
        $joined = implode("\n", $socket->sent);
        $this->assertStringContainsString('window.scrollTo(0, 400)', $joined);

        $page->scrollTo('#5');
        $scrolled = false;
        foreach ($socket->sent as $payload) {
            $message = json_decode($payload, true);
            if (($message['method'] ?? '') === 'DOM.scrollIntoViewIfNeeded') {
                $scrolled = true;
            }
        }
        $this->assertTrue($scrolled);
    }

    #[Test]
    public function selectorBuildsALabelForANode(): void
    {
        $socket = $this->socket();
        $socket->results['DOM.resolveNode'] = ['object' => ['objectId' => 'obj']];
        $socket->results['Runtime.callFunctionOn'] = ['result' => ['value' => '#save']];
        $page = $this->page($socket);

        $this->assertSame('#save', $page->selector(5));
    }

    #[Test]
    public function inspectReportsComputedStyle(): void
    {
        $page = $this->page($this->socket());

        $report = $page->inspect('#save', ['display']);

        $this->assertSame('button', $report['tag']);
        $this->assertSame('block', $report['styles']['display']);
    }

    #[Test]
    public function diagnosticsPassesThroughConsoleLines(): void
    {
        $socket = $this->socket();
        $socket->inbox[] = json_encode([
            'method' => 'Runtime.consoleAPICalled',
            'params' => ['type' => 'error', 'args' => [['value' => 'boom']]],
        ], JSON_THROW_ON_ERROR);
        $page = $this->page($socket);

        $lines = $page->diagnostics();

        $this->assertSame('boom', $lines[0]['text']);
    }

    #[Test]
    public function frameEvaluateRunsInThatSession(): void
    {
        $socket = $this->socket();
        $page = $this->page($socket);

        $this->assertSame('https://example.com/', $page->frameEvaluate('child', 'location.href'));
        $sent = json_decode($socket->sent[0], true);
        $this->assertSame('child', $sent['sessionId']);
    }

    #[Test]
    public function frameSnapshotReadsThatFrame(): void
    {
        $socket = $this->socket();
        $socket->results['Accessibility.getFullAXTree'] = ['nodes' => [['role' => ['value' => 'RootWebArea']]]];
        $page = $this->page($socket);

        $tree = $page->frameSnapshot(['frameId' => 'same', 'sessionId' => '']);

        $this->assertSame('RootWebArea', $tree['nodes'][0]['role']['value']);
    }

    #[Test]
    public function fillInsideACrossOriginFrameResolvesTheNodeThere(): void
    {
        $socket = $this->socket();
        $socket->results['DOM.getContentQuads'] = ['quads' => [[0, 0, 8, 0, 8, 8, 0, 8]]];
        $socket->results['DOM.resolveNode'] = ['object' => ['objectId' => 'obj']];
        $socket->results['Runtime.callFunctionOn'] = ['result' => ['value' => true]];
        $page = $this->page($socket);

        $page->fill('#9:child/4', 'Ada');

        $resolvedInFrame = false;
        foreach ($socket->sent as $payload) {
            $message = json_decode($payload, true);
            if (($message['method'] ?? '') === 'DOM.resolveNode' && ($message['sessionId'] ?? '') === 'child') {
                $resolvedInFrame = true;
            }
        }
        $this->assertTrue($resolvedInFrame);
    }

    #[Test]
    public function activateFocusesTheTab(): void
    {
        $socket = $this->socket();
        $chrome = new class () extends HeadlessChrome {
            public string $focused = '';

            public function focusTarget(string $targetId): void
            {
                $this->focused = $targetId;
            }

            public function dropTarget(string $targetId): void
            {
            }
        };
        $page = new Page(new Session($socket), 'tab-9', $chrome, 2.0);

        $page->activate();

        $this->assertSame('tab-9', $chrome->focused);
    }

    #[Test]
    public function aClosedPageRejectsFurtherReads(): void
    {
        $page = $this->page($this->socket());
        $page->close();

        $this->expectException(Exception::class);
        $page->url();
    }

    #[Test]
    public function framesListsTheChildDocuments(): void
    {
        $socket = new ScriptedSocket();
        $socket->then['Target.setAutoAttach'] = [[
            'method' => 'Target.attachedToTarget',
            'params' => [
                'sessionId' => 'child',
                'targetInfo' => ['type' => 'iframe', 'url' => 'https://other.example/'],
            ],
        ]];
        $socket->results['Page.getFrameTree'] = ['frameTree' => ['childFrames' => [['frame' => ['id' => 'same', 'url' => 'https://example.com/in']]]]];
        $socket->results['DOM.getFrameOwner'] = ['backendNodeId' => 0];
        $page = $this->page($socket, 0.3);

        $frames = $page->frames();

        $this->assertSame('same', $frames[0]['frameId']);
        $this->assertTrue($frames[0]['sameOrigin']);
        $this->assertSame('child', $frames[1]['sessionId']);
    }

    #[Test]
    public function assertReadsANodeAndASelector(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['DOM.resolveNode'] = ['object' => ['objectId' => 'obj']];
        $socket->results['Runtime.callFunctionOn'] = ['result' => ['value' => ['ok' => true, 'actual' => 'shown']]];
        $page = $this->page($socket, 0.4);

        $node = $page->assert('#5', 'visible');

        $this->assertTrue($node['ok']);
        $this->assertSame('shown', $node['actual']);
        $this->assertSame('#5', $node['target']);

        $selectorSocket = new ScriptedSocket();
        $selectorSocket->results['Runtime.evaluate'] = ['result' => ['value' => ['ok' => false, 'actual' => 'gone']]];
        $selector = $this->page($selectorSocket, 0.4)->assert('main', 'text', 'Saved', 0.15);

        $this->assertFalse($selector['ok']);
        $this->assertSame('gone', $selector['actual']);
    }

    #[Test]
    public function assertRejectsAnEmptyTarget(): void
    {
        $page = $this->page($this->socket());

        $this->expectException(Exception::class);
        $page->assert('  ');
    }

    #[Test]
    public function frameEvaluateReportsAScriptFailure(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Runtime.evaluate'] = ['exceptionDetails' => ['exception' => ['description' => 'bad frame']]];
        $page = $this->page($socket);

        try {
            $page->frameEvaluate('child', '1');
            $this->fail('A frame script error must be reported.');
        } catch (Exception $e) {
            $this->assertStringContainsString('bad frame', $e->getMessage());
        }

        $this->expectException(Exception::class);
        $page->frameEvaluate('  ', '1');
    }

    #[Test]
    public function inspectReadsANodeAndAFrameAndRejectsAMiss(): void
    {
        $socket = $this->socket();
        $socket->results['DOM.resolveNode'] = ['object' => ['objectId' => 'obj']];
        $socket->results['Runtime.callFunctionOn'] = ['result' => ['value' => [
            'tag' => 'input',
            'visible' => true,
            'box' => ['x' => 1, 'y' => 2, 'w' => 3, 'h' => 4],
            'styles' => ['display' => 'block'],
        ]]];
        $page = $this->page($socket);

        $node = $page->inspect('#5');
        $this->assertSame('input', $node['tag']);
        $this->assertSame('#5', $node['target']);

        $frame = $page->inspect('#9:child/4', ['color']);
        $this->assertSame('input', $frame['tag']);

        try {
            $page->inspect('  ');
            $this->fail('An empty inspect target must be rejected.');
        } catch (Exception $e) {
            $this->assertStringContainsString('empty', $e->getMessage());
        }

        $missing = new ScriptedSocket();
        $missing->results['Runtime.evaluate'] = ['result' => ['value' => null]];
        $this->expectException(Exception::class);
        $this->page($missing)->inspect('#missing');
    }

    #[Test]
    public function fetchAppliesARefererAndRejectsAnEmptyUrl(): void
    {
        $socket = $this->socket();
        $page = $this->page($socket);

        $response = $page->fetch('GET', 'https://example.com/api', ['Referer' => 'https://example.com/', 'X-A' => '1']);
        $this->assertSame(201, $response['status']);
        $headers = false;
        foreach ($socket->sent as $payload) {
            $message = json_decode($payload, true);
            if (($message['method'] ?? '') === 'Network.setExtraHTTPHeaders'
                && (($message['params']['headers']['Referer'] ?? '') === 'https://example.com/')
            ) {
                $headers = true;
            }
        }
        $this->assertTrue($headers);

        $this->expectException(Exception::class);
        $page->fetch('GET', '  ');
    }

    #[Test]
    public function fetchReportsAPageFailure(): void
    {
        $socket = $this->socket();
        $socket->results['Runtime.evaluate'] = ['exceptionDetails' => ['text' => 'TypeError']];
        $page = $this->page($socket);

        $this->expectException(Exception::class);
        $page->fetch('GET', 'https://example.com/api');
    }

    #[Test]
    public function layoutFallsBackWhenTheDeviceScaleIsMissing(): void
    {
        $socket = $this->socket();
        $socket->results['Runtime.evaluate'] = ['result' => ['value' => 0]];
        $socket->results['DOMSnapshot.captureSnapshot'] = [
            'documents' => [[
                'scrollOffsetX' => 0,
                'scrollOffsetY' => 0,
                'nodes' => ['backendNodeId' => [7]],
                'layout' => [
                    'nodeIndex' => [0],
                    'bounds' => [[20, 40, 100, 50]],
                ],
            ]],
        ];
        $page = $this->page($socket);

        $this->assertSame(
            ['x' => 20.0, 'y' => 40.0, 'w' => 100.0, 'h' => 50.0],
            $page->layout()[7],
        );
    }

    #[Test]
    public function scrollToMovesACssNodeAndAFrameNode(): void
    {
        $socket = $this->socket();
        $socket->results['DOM.getContentQuads'] = ['quads' => [[0, 0, 8, 0, 8, 8, 0, 8]]];
        $page = $this->page($socket);

        $page->scrollTo('main');
        $page->scrollTo('#9:child/4');

        $inFrame = false;
        foreach ($socket->sent as $payload) {
            $message = json_decode($payload, true);
            if (($message['method'] ?? '') === 'DOM.scrollIntoViewIfNeeded'
                && ($message['sessionId'] ?? '') === 'child'
            ) {
                $inFrame = true;
            }
        }
        $this->assertTrue($inFrame);
        $this->assertStringContainsString('scrollIntoView', implode("\n", $socket->sent));
    }

    #[Test]
    public function aClosedPageIgnoresActivateAndFillClearsAField(): void
    {
        $socket = $this->socket();
        $page = $this->page($socket);

        $page->fill('#save', '');
        $this->assertSame('Backspace', $this->sentParams($socket, 'Input.dispatchKeyEvent', 'keyDown')['key']);

        $page->close();
        $page->activate();
        $this->assertTrue($socket->closed);
    }

    #[Test]
    public function viewportFallsBackWhenTheWindowSizeIsMissing(): void
    {
        $socket = $this->socket();
        $socket->results['Runtime.evaluate'] = ['result' => ['value' => null]];
        $page = $this->page($socket);

        $this->assertSame(
            ['width' => 0, 'height' => 0, 'scrollY' => 0, 'scrollHeight' => 0],
            $page->viewport(),
        );
    }

    private function socket(): ScriptedSocket
    {
        $socket = new ScriptedSocket();
        $socket->results['Runtime.evaluate'] = static function (array $message): array {
            $expression = (string) ($message['params']['expression'] ?? '');
            if (str_contains($expression, 'outerHTML')) {
                return ['result' => ['value' => '<html></html>']];
            }
            if (str_contains($expression, 'location.href')) {
                return ['result' => ['value' => 'https://example.com/']];
            }
            if ($expression === 'window.devicePixelRatio') {
                return ['result' => ['value' => 2]];
            }
            if (str_contains($expression, 'elementFromPoint')) {
                return ['result' => ['value' => [
                    'x' => 10, 'y' => 20, 'w' => 30, 'h' => 40, 'blocked' => false, 'by' => '',
                ]]];
            }
            if (str_contains($expression, 'fetch(')) {
                return ['result' => ['value' => [
                    'status' => 201,
                    'body' => 'ok',
                    'headers' => ['content-type' => 'application/json'],
                ]]];
            }
            if (str_contains($expression, 'ok:')) {
                return ['result' => ['value' => ['ok' => true, 'actual' => 'Saved']]];
            }
            if (str_contains($expression, 'querySelector') && str_contains($expression, '!== null')) {
                return ['result' => ['value' => true]];
            }
            if (str_contains($expression, 'el.select')) {
                return ['result' => ['value' => true]];
            }
            if (str_contains($expression, 'getComputedStyle')) {
                return ['result' => ['value' => [
                    'tag' => 'button',
                    'visible' => true,
                    'box' => ['x' => 1, 'y' => 2, 'w' => 3, 'h' => 4],
                    'styles' => ['display' => 'block'],
                ]]];
            }
            if (str_contains($expression, 'innerWidth')) {
                return ['result' => ['value' => '{"width":1200,"height":800,"scrollY":0,"scrollHeight":800}']];
            }

            return ['result' => ['value' => null]];
        };

        return $socket;
    }

    private function page(ScriptedSocket $socket, float $timeout = 2.0): Page
    {
        $chrome = new class () extends HeadlessChrome {
            public function focusTarget(string $targetId): void
            {
            }

            public function dropTarget(string $targetId): void
            {
            }
        };

        return new Page(new Session($socket), 'tab-1', $chrome, $timeout);
    }

    /**
     * @return array<string, mixed>
     */
    private function sentParams(ScriptedSocket $socket, string $method, string $type): array
    {
        foreach ($socket->sent as $payload) {
            $message = json_decode($payload, true);
            if (($message['method'] ?? '') !== $method) {
                continue;
            }
            $params = $message['params'] ?? [];
            if ($type === '' || ($params['type'] ?? '') === $type) {
                return $params;
            }
        }

        $this->fail("No {$method} {$type} was sent.");
    }
}
