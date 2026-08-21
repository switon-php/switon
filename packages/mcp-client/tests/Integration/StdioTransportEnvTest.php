<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Integration;

use Switon\McpClient\Tests\TestCase;
use Switon\McpClient\Transport\StdioTransportInterface;

/**
 * Real subprocess: the child must see the parent environment even when the
 * server entry sets its own env, and a response id of the other JSON-RPC
 * numeric type must still match the request.
 */
class StdioTransportEnvTest extends TestCase
{
    public function testChildKeepsPathWhenServerSetsEnv(): void
    {
        $transport = $this->transport(1, <<<'SH'
if [ -n "$PATH" ]; then has=YES; else has=NO; fi
printf '%s\n' "{\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"hasPath\":\"$has\"}}"
SH, ['FOO' => 'bar']);
        $transport->open();
        try {
            $response = $transport->send(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                static fn (): bool => false,
            );
            $this->assertIsArray($response);
            $this->assertSame('YES', $response['result']['hasPath'], 'Child process must keep PATH.');
        } finally {
            $transport->close();
        }
    }

    public function testStringResponseIdMatchesNumericRequestId(): void
    {
        $transport = $this->transport(1, <<<'SH'
read -r line
id=$(printf '%s' "$line" | sed -n 's/.*"id":\([0-9][0-9]*\).*/\1/p')
printf '%s\n' "{\"jsonrpc\":\"2.0\",\"id\":\"$id\",\"result\":{\"ok\":true}}"
SH);
        $transport->open();
        try {
            $response = $transport->send(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                static fn (): bool => false,
            );
            $this->assertIsArray($response, 'A string id must still match the numeric request id.');
            $this->assertTrue($response['result']['ok']);
        } finally {
            $transport->close();
        }
    }

    /**
     * @param array<string, string> $env
     */
    protected function transport(float $readTimeout, string $script, array $env = []): StdioTransportInterface
    {
        return $this->container->make(StdioTransportInterface::class, [
            'command' => '/bin/sh',
            'args' => ['-c', $script],
            'readTimeout' => $readTimeout,
            'env' => $env,
        ]);
    }
}
