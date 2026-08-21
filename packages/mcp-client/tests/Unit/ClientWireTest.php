<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Switon\McpClient\Client;
use Switon\McpClient\ClientInfo;
use Switon\McpClient\ClientInterface;
use Switon\McpClient\Tests\TestCase;
use Switon\McpClient\Transport\StdioTransportInterface;

#[CoversClass(Client::class)]
class ClientWireTest extends TestCase
{
    public function testStdioTransportAndClient(): void
    {
        $server = dirname(__DIR__) . '/Fixtures/stdio_mcp_server.php';

        $transport = $this->container->make(StdioTransportInterface::class, [
            'command' => PHP_BINARY,
            'args' => [$server],
            'cwd' => dirname(__DIR__, 2),
        ]);

        $client = $this->container->make(ClientInterface::class, [
            'server' => 'fixture',
            'transport' => $transport,
            'clientInfo' => ClientInfo::of('switon-mcp-client-test'),
        ]);

        $this->assertSame('pong', $client->callTool("ping")['content'][0]['text']);

        unset($client);
    }
}
