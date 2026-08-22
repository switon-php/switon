<?php

declare(strict_types=1);

namespace Switon\Cdp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Switon\Cdp\Exception;
use Switon\Cdp\Session;

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
}
