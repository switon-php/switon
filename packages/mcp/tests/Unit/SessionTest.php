<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\Core\Attribute\Autowired;
use Switon\Core\InputInterface;
use Switon\Http\Event\RequestReceived;
use Switon\Http\RequestInterface;
use Switon\Http\ServerInterface;
use Switon\Mcp\Session;
use Switon\Mcp\Transport\HttpTransportInterface;
use Switon\Mcp\Tests\TestCase;

#[CoversClass(Session::class)]
class SessionTest extends TestCase
{
    #[Autowired] protected RequestInterface $request;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();
        $this->container->set(InputInterface::class, RequestInterface::class);
    }

    public function testGetIdReturnsNullWithoutHeader(): void
    {
        $this->seedRequest();

        $this->assertNull($this->make(Session::class)->getId());
    }

    public function testGetIdReadsMcpSessionHeader(): void
    {
        $this->seedRequest(sessionId: 'abc123sessionidxxxxxx');

        $this->assertSame('abc123sessionidxxxxxx', $this->make(Session::class)->getId());
    }

    public function testHeaderAndHeaders(): void
    {
        $this->seedRequest(authorization: 'Bearer x', sessionId: 'sid');

        $session = $this->make(Session::class);
        $this->assertSame('Bearer x', $session->header('Authorization'));
        $this->assertSame('sid', $session->header(HttpTransportInterface::SESSION_HEADER));
        $this->assertArrayHasKey('authorization', $session->headers());
        $this->assertArrayHasKey('mcp-session-id', $session->headers());
    }

    public function testGetAccessKeyPrefersBearerOverQuery(): void
    {
        $this->seedRequest(authorization: 'Bearer from-header', accessKey: 'from-query');

        $this->assertSame('from-header', $this->make(Session::class)->getAccessKey());
    }

    public function testGetAccessKeyFallsBackToQueryAccessKey(): void
    {
        $this->seedRequest(accessKey: 'query-key');

        $this->assertSame('query-key', $this->make(Session::class)->getAccessKey());
    }

    public function testGetAccessKeyReturnsNullWhenAbsent(): void
    {
        $this->seedRequest();

        $this->assertNull($this->make(Session::class)->getAccessKey());
    }

    protected function seedRequest(
        ?string $sessionId = null,
        ?string $authorization = null,
        ?string $accessKey = null,
    ): void {
        $server = [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/mcp',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_HOST' => 'localhost',
            'REQUEST_SCHEME' => 'http',
        ];
        $get = [];
        if ($sessionId !== null) {
            $server['HTTP_MCP_SESSION_ID'] = $sessionId;
        }
        if ($authorization !== null) {
            $server['HTTP_AUTHORIZATION'] = $authorization;
        }
        if ($accessKey !== null) {
            $get['access_key'] = $accessKey;
            $server['QUERY_STRING'] = 'access_key=' . rawurlencode($accessKey);
            $server['REQUEST_URI'] = '/mcp?' . $server['QUERY_STRING'];
        }

        $this->request->onRequestReceived(new RequestReceived(
            sender: $this->createStub(ServerInterface::class),
            GET: $get,
            POST: [],
            SERVER: $server,
            RAW_BODY: '{}',
            COOKIE: [],
            FILES: [],
        ));
    }
}
