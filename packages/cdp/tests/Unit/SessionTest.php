<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Exception;
use Switon\Cdp\Session;
use Switon\Cdp\WebSocket;

#[CoversClass(Session::class)]
class SessionTest extends TestCase
{
    #[Test]
    public function callReturnsTheCommandResult(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Runtime.evaluate'] = ['result' => ['value' => 7]];
        $session = new Session($socket);

        $result = $session->call('Runtime.evaluate', ['expression' => '1+1']);

        $this->assertSame(7, $result['result']['value']);
        $this->assertStringContainsString('Runtime.evaluate', $socket->sent[0]);
    }

    #[Test]
    public function callReportsAProtocolError(): void
    {
        $socket = new ScriptedSocket();
        $socket->failures['Page.navigate'] = 'net::ERR_FAILED';
        $session = new Session($socket);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('net::ERR_FAILED');
        $session->call('Page.navigate', ['url' => 'https://example.com/']);
    }

    #[Test]
    public function callRejectsANonJsonReply(): void
    {
        $socket = new ScriptedSocket();
        $socket->inbox[] = '{';
        $session = new Session($socket);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Invalid CDP JSON');
        $session->call('Runtime.evaluate');
    }

    #[Test]
    public function documentHtmlReturnsThePageSource(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Runtime.evaluate'] = ['result' => ['value' => '<html></html>']];
        $session = new Session($socket);

        $this->assertSame('<html></html>', $session->documentHtml());
    }

    #[Test]
    public function documentHtmlRejectsAnEmptyDocument(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Runtime.evaluate'] = ['result' => ['value' => '']];
        $session = new Session($socket);

        $this->expectException(Exception::class);
        $session->documentHtml();
    }

    #[Test]
    public function selectorExistsReadsThePageBoolean(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Runtime.evaluate'] = ['result' => ['value' => true]];
        $session = new Session($socket);

        $this->assertTrue($session->selectorExists('main'));
    }

    #[Test]
    public function selectorExistsReportsABrokenSelector(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Runtime.evaluate'] = ['exceptionDetails' => ['text' => 'bad']];
        $session = new Session($socket);

        $this->expectException(Exception::class);
        $session->selectorExists('main');
    }

    #[Test]
    public function navigateSettlesOnNetworkIdleAndKeepsTheDocumentStatus(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Page.navigate'] = ['loaderId' => 'L1'];
        $socket->then['Page.navigate'] = [
            [
                'method' => 'Network.responseReceived',
                'params' => [
                    'type' => 'Document',
                    'loaderId' => 'L1',
                    'response' => ['status' => 200, 'headers' => ['content-type' => 'text/html']],
                ],
            ],
            [
                'method' => 'Page.lifecycleEvent',
                'params' => ['name' => 'networkIdle', 'loaderId' => 'L1'],
            ],
        ];
        $session = new Session($socket);

        $session->navigateAndWait('https://example.com/', 1.0);

        $this->assertSame(200, $session->navigationStatus());
        $this->assertSame('text/html', $session->navigationHeaders()['content-type']);
    }

    #[Test]
    public function navigateReportsAFailedNavigation(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Page.navigate'] = ['errorText' => 'net::ERR_NAME_NOT_RESOLVED', 'loaderId' => 'L1'];
        $session = new Session($socket);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('net::ERR_NAME_NOT_RESOLVED');
        $session->navigateAndWait('https://missing.example/', 1.0);
    }

    #[Test]
    public function waitForEventReturnsTheMatchingPayload(): void
    {
        $socket = new ScriptedSocket();
        $socket->inbox[] = json_encode([
            'method' => 'Page.loadEventFired',
            'params' => ['timestamp' => 1],
        ], JSON_THROW_ON_ERROR);
        $session = new Session($socket);

        $params = $session->waitForEvent('Page.loadEventFired', 1.0);

        $this->assertSame(1, $params['timestamp']);
    }

    #[Test]
    public function diagnosticsKeepsConsoleErrorsExceptionsAndHttpFailures(): void
    {
        $socket = new ScriptedSocket();
        $socket->inbox[] = json_encode([
            'method' => 'Runtime.consoleAPICalled',
            'params' => [
                'type' => 'error',
                'args' => [['value' => 'boom']],
                'stackTrace' => ['callFrames' => [['url' => 'https://example.com/app.js', 'lineNumber' => 4]]],
            ],
        ], JSON_THROW_ON_ERROR);
        $socket->inbox[] = json_encode([
            'method' => 'Runtime.exceptionThrown',
            'params' => [
                'exceptionDetails' => [
                    'exception' => ['description' => 'ReferenceError: x'],
                    'url' => 'https://example.com/app.js',
                    'lineNumber' => 9,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $socket->inbox[] = json_encode([
            'method' => 'Network.responseReceived',
            'params' => [
                'response' => ['status' => 500, 'statusText' => 'Server Error', 'url' => 'https://example.com/api'],
            ],
        ], JSON_THROW_ON_ERROR);
        $socket->inbox[] = json_encode([
            'method' => 'Network.loadingFailed',
            'params' => ['errorText' => 'net::ERR_ABORTED', 'documentURL' => 'https://example.com/old'],
        ], JSON_THROW_ON_ERROR);
        $session = new Session($socket);

        $lines = $session->diagnostics();

        $this->assertSame('console', $lines[0]['type']);
        $this->assertSame('boom', $lines[0]['text']);
        $this->assertSame(5, $lines[0]['line']);
        $this->assertSame('exception', $lines[1]['type']);
        $this->assertSame('ReferenceError: x', $lines[1]['text']);
        $this->assertSame('response', $lines[2]['type']);
        $this->assertSame('500 Server Error', $lines[2]['text']);
        $this->assertSame('debug', $lines[3]['level']);
    }

    #[Test]
    public function anOpenDialogIsAccepted(): void
    {
        $socket = new ScriptedSocket();
        $socket->inbox[] = json_encode([
            'method' => 'Page.javascriptDialogOpening',
            'params' => ['type' => 'alert'],
        ], JSON_THROW_ON_ERROR);
        $session = new Session($socket);

        $session->diagnostics();

        $joined = implode("\n", $socket->sent);
        $this->assertStringContainsString('Page.handleJavaScriptDialog', $joined);
    }

    #[Test]
    public function framesListsASameOriginChildAndAnAttachedCrossOriginFrame(): void
    {
        $socket = new ScriptedSocket();
        $socket->then['Target.setAutoAttach'] = [[
            'method' => 'Target.attachedToTarget',
            'params' => [
                'sessionId' => 'child',
                'targetInfo' => ['type' => 'iframe', 'url' => 'https://other.example/'],
            ],
        ]];
        $socket->results['Page.getFrameTree'] = static function (array $message): array {
            if (($message['sessionId'] ?? '') === 'child') {
                return ['frameTree' => ['frame' => ['id' => 'frame-1']]];
            }

            return ['frameTree' => ['childFrames' => [['frame' => ['id' => 'same', 'url' => 'https://example.com/in']]]]];
        };
        $socket->results['DOM.getFrameOwner'] = ['backendNodeId' => 9];
        $socket->results['DOM.resolveNode'] = ['object' => ['objectId' => 'obj']];
        $socket->results['Runtime.callFunctionOn'] = ['result' => ['value' => ['x' => 4, 'y' => 8]]];
        $session = new Session($socket);

        $frames = $session->frames(0.3);

        $this->assertSame('same', $frames[0]['frameId']);
        $this->assertTrue($frames[0]['sameOrigin']);
        $this->assertSame('child', $frames[1]['sessionId']);
        $this->assertFalse($frames[1]['sameOrigin']);
        $this->assertSame(9, $frames[1]['ownerBackendNodeId']);
        $this->assertSame(4.0, $frames[1]['offset']['x']);
        $this->assertSame('child', $session->frameSessionForOwner(9));
        $this->assertSame(4.0, $session->frameOffsetForOwner(9)['x']);
        $this->assertSame(0.0, $session->frameOffsetForOwner(1)['x']);
    }

    #[Test]
    public function diagnosticsKeepsALogLineAndDropsVerboseNoise(): void
    {
        $socket = new ScriptedSocket();
        $socket->inbox[] = json_encode([
            'method' => 'Log.entryAdded',
            'params' => ['entry' => ['level' => 'error', 'text' => 'failed', 'url' => 'https://example.com/a.js', 'lineNumber' => 2]],
        ], JSON_THROW_ON_ERROR);
        $socket->inbox[] = json_encode([
            'method' => 'Log.entryAdded',
            'params' => ['entry' => ['level' => 'verbose', 'text' => 'noise']],
        ], JSON_THROW_ON_ERROR);
        $session = new Session($socket);

        $lines = $session->diagnostics();

        $this->assertCount(1, $lines);
        $this->assertSame('log', $lines[0]['type']);
        $this->assertSame('failed', $lines[0]['text']);
        $this->assertSame(3, $lines[0]['line']);
    }

    #[Test]
    public function enableDiagnosticsIsIdempotent(): void
    {
        $socket = new ScriptedSocket();
        $session = new Session($socket);

        $session->enableDiagnostics(1.0);
        $session->enableDiagnostics(1.0);

        $enables = 0;
        foreach ($socket->sent as $payload) {
            $message = json_decode($payload, true);
            if (str_ends_with((string) ($message['method'] ?? ''), '.enable')) {
                $enables++;
            }
        }
        $this->assertSame(3, $enables);
    }

    #[Test]
    public function waitForNetworkIdleReturnsWhenNothingIsInFlight(): void
    {
        $socket = new ScriptedSocket();
        $session = new Session($socket);

        $this->assertTrue($session->waitForNetworkIdle(0.3, 0.05));
    }

    #[Test]
    public function connectToDebuggerUrlRejectsABadAddress(): void
    {
        $this->expectException(Exception::class);
        Session::connectToDebuggerUrl('not-a-websocket');
    }

    #[Test]
    public function navigationReturnsWhenTheDocumentLoadedWithoutGoingIdle(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Page.navigate'] = ['loaderId' => 'L1'];
        $socket->then['Page.navigate'] = [[
            'method' => 'Page.loadEventFired',
            'params' => ['timestamp' => 1],
        ]];
        $session = new Session($socket);

        $session->navigateAndWait('https://example.com/', 0.5);

        $this->assertSame(0, $session->navigationStatus());
    }

    #[Test]
    public function navigationTimesOutWhenTheDocumentNeverLoads(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Page.navigate'] = ['loaderId' => 'L1'];
        $session = new Session($socket);

        $this->expectException(Exception::class);
        $session->navigateAndWait('https://example.com/', 0.5);
    }

    #[Test]
    public function aBusyPageDoesNotReportNetworkIdle(): void
    {
        $socket = new ScriptedSocket();
        $socket->inbox[] = json_encode([
            'method' => 'Network.requestWillBeSent',
            'params' => ['requestId' => '1'],
        ], JSON_THROW_ON_ERROR);
        $session = new Session($socket);

        $this->assertFalse($session->waitForNetworkIdle(0.2, 0.05));
    }

    #[Test]
    public function documentSnapshotReadsReadyStateAndDropsABrokenReply(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Runtime.evaluate'] = static function (array $message): array {
            static $calls = 0;
            $calls++;

            return $calls === 1
                ? ['result' => ['value' => '{"ready":"complete","href":"https://example.com/"}']]
                : ['result' => ['value' => 'not-json']];
        };
        $session = new class ($socket) extends Session {
            /** @return array{ready: string, href: string} */
            public function snapshot(): array
            {
                return $this->documentNavigationSnapshot(0.5);
            }
        };

        $this->assertSame(['ready' => 'complete', 'href' => 'https://example.com/'], $session->snapshot());
        $this->assertSame(['ready' => '', 'href' => ''], $session->snapshot());

        $failed = new ScriptedSocket();
        $failed->failures['Runtime.evaluate'] = 'dead';
        $broken = new class ($failed) extends Session {
            /** @return array{ready: string, href: string} */
            public function snapshot(): array
            {
                return $this->documentNavigationSnapshot(0.5);
            }
        };

        $this->assertSame(['ready' => '', 'href' => ''], $broken->snapshot());
    }

    #[Test]
    public function closeClosesTheSocket(): void
    {
        $socket = new ScriptedSocket();
        $session = new Session($socket);

        $session->close();

        $this->assertTrue($socket->closed);
    }

    #[Test]
    public function diagnosticsSkipsEntriesThatAreNotActionable(): void
    {
        $socket = new ScriptedSocket();
        $socket->inbox[] = json_encode([
            'method' => 'Runtime.exceptionThrown',
            'params' => ['exceptionDetails' => ['text' => 'just text']],
        ], JSON_THROW_ON_ERROR);
        $socket->inbox[] = json_encode([
            'method' => 'Network.responseReceived',
            'params' => ['response' => ['status' => '404', 'statusText' => 'Missing', 'url' => 'https://example.com/missing']],
        ], JSON_THROW_ON_ERROR);
        $lines = (new Session($socket))->diagnostics();

        $this->assertSame('just text', $lines[0]['text']);
        $this->assertSame('404 Missing', $lines[1]['text']);

        foreach ([
            ['method' => 'Log.entryAdded', 'params' => ['entry' => 'nope']],
            ['method' => 'Network.responseReceived', 'params' => ['response' => 'nope']],
            ['method' => 'Network.responseReceived', 'params' => ['response' => ['status' => '200', 'url' => 'https://example.com/ok']]],
        ] as $event) {
            $skipped = new ScriptedSocket();
            $skipped->inbox[] = json_encode($event, JSON_THROW_ON_ERROR);
            $this->assertSame([], (new Session($skipped))->diagnostics());
        }
    }

    #[Test]
    public function framesIgnoreABrokenTreeAndAFrameWithoutAnOwner(): void
    {
        $socket = new ScriptedSocket();
        $socket->failures['Target.setAutoAttach'] = 'unavailable';
        $socket->results['Page.getFrameTree'] = [
            'frameTree' => ['childFrames' => [
                ['notFrame' => true],
                ['frame' => ['id' => 'same', 'url' => 'https://example.com/in'], 'childFrames' => [['frame' => 'no']]],
            ]],
        ];

        $listed = (new Session($socket))->frames(0.1);
        $this->assertSame('same', $listed[0]['frameId']);

        $attached = new ScriptedSocket();
        $attached->then['Target.setAutoAttach'] = [[
            'method' => 'Target.attachedToTarget',
            'params' => [
                'sessionId' => 'child',
                'targetInfo' => ['type' => 'iframe', 'url' => 'https://other.example/'],
            ],
        ]];
        $attached->results['Page.getFrameTree'] = static function (array $message): array {
            if (($message['sessionId'] ?? '') === 'child') {
                return ['frameTree' => ['frame' => []]];
            }

            return ['frameTree' => []];
        };
        $frames = (new Session($attached))->frames(0.1);

        $this->assertSame(0, $frames[0]['ownerBackendNodeId']);
        $this->assertSame('child', $frames[0]['sessionId']);
        $this->assertSame(['x' => 0.0, 'y' => 0.0], $frames[0]['offset']);
    }

    #[Test]
    public function navigationIgnoresEventsFromAnotherDocument(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Page.navigate'] = ['loaderId' => 'L1'];
        $socket->then['Page.navigate'] = [
            ['method' => 'Network.requestWillBeSent', 'params' => []],
            ['method' => 'Network.loadingFinished', 'params' => []],
            ['method' => 'Network.responseReceived', 'params' => ['type' => 'Script', 'loaderId' => 'L1', 'response' => ['status' => 200]]],
            ['method' => 'Network.responseReceived', 'params' => ['type' => 'Document', 'loaderId' => 'other', 'response' => ['status' => 500]]],
            ['method' => 'Network.responseReceived', 'params' => ['type' => 'Document', 'loaderId' => 'L1', 'response' => ['status' => 200, 'headers' => 'bad']]],
            ['method' => 'Network.responseReceived', 'params' => [
                'type' => 'Document',
                'loaderId' => 'L1',
                'response' => ['status' => 204, 'headers' => ['content-type' => 'text/html', '' => 'x', 1 => 'y']],
            ]],
            ['method' => 'Page.lifecycleEvent', 'params' => ['name' => 'networkIdle', 'loaderId' => 'other']],
            ['method' => 'Page.lifecycleEvent', 'params' => ['name' => 'networkIdle', 'loaderId' => 'L1']],
        ];
        $session = new Session($socket);

        $session->navigateAndWait('https://example.com/', 1.0);

        $this->assertSame(204, $session->navigationStatus());
        $this->assertSame('text/html', $session->navigationHeaders()['content-type']);
        $this->assertTrue($session->waitForNetworkIdle(0.3, 0.05));
    }

    #[Test]
    public function aStringProtocolErrorAndAMissedEventAreReported(): void
    {
        $socket = new StringErrorSocket();
        try {
            (new Session($socket))->call('Runtime.evaluate');
            $this->fail('A string protocol error must be reported.');
        } catch (Exception $e) {
            $this->assertStringContainsString('plain', $e->getMessage());
        }

        $waiting = new ScriptedSocket();
        $waiting->inbox[] = json_encode(null, JSON_THROW_ON_ERROR);
        $waiting->inbox[] = json_encode(['method' => 'Target.attachedToTarget', 'params' => ['sessionId' => 'x']], JSON_THROW_ON_ERROR);
        $waiting->inbox[] = json_encode([
            'method' => 'Target.attachedToTarget',
            'params' => ['sessionId' => 's', 'targetInfo' => ['type' => 'page', 'url' => 'https://example.com/']],
        ], JSON_THROW_ON_ERROR);
        $waiting->inbox[] = json_encode([
            'method' => 'Page.javascriptDialogOpening',
            'params' => ['type' => 'prompt'],
        ], JSON_THROW_ON_ERROR);
        $waiting->failures['Page.handleJavaScriptDialog'] = 'already closed';
        $waiting->inbox[] = json_encode([
            'method' => 'Network.responseReceived',
            'params' => ['response' => ['status' => 'nope']],
        ], JSON_THROW_ON_ERROR);
        for ($i = 0; $i < 1001; $i++) {
            $waiting->inbox[] = json_encode(['method' => 'Log.entryAdded', 'params' => ['entry' => ['level' => 'verbose']]], JSON_THROW_ON_ERROR);
        }

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Timed out waiting');
        (new Session($waiting))->waitForEvent('Page.loadEventFired', 0.1);
    }

    #[Test]
    public function callReportsATimeoutAndDiagnosticsKeepConsoleText(): void
    {
        try {
            (new Session(new QuietSocket()))->call('Runtime.evaluate', [], 0.1);
            $this->fail('A call with no reply must time out.');
        } catch (Exception $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }

        $socket = new ScriptedSocket();
        for ($i = 0; $i < 200; $i++) {
            $socket->inbox[] = json_encode([
                'method' => 'Runtime.consoleAPICalled',
                'params' => ['args' => [['value' => 'x']]],
            ], JSON_THROW_ON_ERROR);
        }
        $socket->inbox[] = json_encode([
            'method' => 'Runtime.consoleAPICalled',
            'params' => ['args' => [
                'skip',
                ['value' => 'hi'],
                ['description' => 'Error: boom'],
                ['unserializableValue' => 'Infinity'],
            ]],
        ], JSON_THROW_ON_ERROR);
        $lines = (new Session($socket))->diagnostics();

        $this->assertSame('hi Error: boom Infinity', $lines[199]['text']);
        $this->assertCount(200, $lines);
    }

    #[Test]
    public function framesStopWhenTheOwnerIsMissingOrUnreadable(): void
    {
        $missing = new ScriptedSocket();
        $missing->then['Target.setAutoAttach'] = [[
            'method' => 'Target.attachedToTarget',
            'params' => ['sessionId' => 'child', 'targetInfo' => ['type' => 'iframe', 'url' => 'https://other.example/']],
        ]];
        $missing->results['Page.getFrameTree'] = static function (array $message): array {
            return ($message['sessionId'] ?? '') === 'child'
                ? ['frameTree' => ['frame' => ['id' => 'child-frame']]]
                : ['frameTree' => []];
        };
        $missing->results['DOM.getFrameOwner'] = ['backendNodeId' => 0];
        $frames = (new Session($missing))->frames(0.1);
        $this->assertSame(0, $frames[0]['ownerBackendNodeId']);

        $broken = new ScriptedSocket();
        $broken->then['Target.setAutoAttach'] = [[
            'method' => 'Target.attachedToTarget',
            'params' => ['sessionId' => 'child', 'targetInfo' => ['type' => 'iframe', 'url' => 'https://other.example/']],
        ]];
        $broken->results['Page.getFrameTree'] = ['frameTree' => []];
        $broken->failures['Page.getFrameTree'] = static function (array $message): ?string {
            return ($message['sessionId'] ?? '') === 'child' ? 'no tree' : null;
        };
        $this->assertSame(0, (new Session($broken))->frames(0.1)[0]['ownerBackendNodeId']);

        $origin = new ScriptedSocket();
        $origin->then['Target.setAutoAttach'] = [[
            'method' => 'Target.attachedToTarget',
            'params' => ['sessionId' => 'child', 'targetInfo' => ['type' => 'iframe', 'url' => 'https://other.example/']],
        ]];
        $origin->results['Page.getFrameTree'] = static function (array $message): array {
            return ($message['sessionId'] ?? '') === 'child'
                ? ['frameTree' => ['frame' => ['id' => 'child-frame']]]
                : ['frameTree' => []];
        };
        $origin->results['DOM.getFrameOwner'] = ['backendNodeId' => 5];
        $origin->results['DOM.resolveNode'] = ['object' => []];
        $this->assertSame(['x' => 0.0, 'y' => 0.0], (new Session($origin))->frames(0.1)[0]['offset']);

        $odd = new ScriptedSocket();
        $odd->then['Target.setAutoAttach'] = [[
            'method' => 'Target.attachedToTarget',
            'params' => ['sessionId' => 'child', 'targetInfo' => ['type' => 'iframe', 'url' => 'https://other.example/']],
        ]];
        $odd->results['Page.getFrameTree'] = $origin->results['Page.getFrameTree'];
        $odd->results['DOM.getFrameOwner'] = ['backendNodeId' => 5];
        $odd->results['DOM.resolveNode'] = ['object' => ['objectId' => 'node']];
        $odd->results['Runtime.callFunctionOn'] = ['result' => ['value' => ['x' => 'no']]];
        $this->assertSame(['x' => 0.0, 'y' => 0.0], (new Session($odd))->frames(0.1)[0]['offset']);
    }

    #[Test]
    public function navigationSnapshotTreatsAnEmptyValueAsUnready(): void
    {
        $socket = new ScriptedSocket();
        $socket->results['Runtime.evaluate'] = ['result' => ['value' => '']];
        $session = new class ($socket) extends Session {
            public function __construct(WebSocket $socket)
            {
                parent::__construct($socket);
            }

            /** @return array{ready: string, href: string} */
            public function snapshot(): array
            {
                return $this->documentNavigationSnapshot(0.2);
            }
        };

        $this->assertSame(['ready' => '', 'href' => ''], $session->snapshot());
    }
}

final class StringErrorSocket extends \Switon\Cdp\WebSocket
{
    /** @var list<string> */
    public array $inbox = [];

    public function __construct()
    {
    }

    public function sendText(string $payload): void
    {
        $message = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        $this->inbox[] = json_encode(['id' => $message['id'], 'error' => 'plain'], JSON_THROW_ON_ERROR);
    }

    public function tryReceiveText(float $timeout): ?string
    {
        $next = array_shift($this->inbox);

        return $next === null ? null : $next;
    }

    public function close(): void
    {
    }
}
