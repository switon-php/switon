<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Integration;

use Switon\McpClient\Client;
use Switon\McpClient\ClientInfo;
use Switon\McpClient\ClientInterface;
use Switon\McpClient\Tests\TestCase;
use Switon\McpClient\Transport\StdioTransportInterface;

class StdioClientIntegrationTest extends TestCase
{
    public function testPingAndCallTool(): void
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

        $client->ping();

        $tools = $client->listTools();
        $this->assertNotEmpty($tools);
        $this->assertArrayHasKey('ping', $tools);
        $this->assertSame('ping', $tools['ping']->definition['name']);

        $this->assertSame('pong', $client->callTool("ping")['content'][0]['text']);

        unset($client);
    }
}
