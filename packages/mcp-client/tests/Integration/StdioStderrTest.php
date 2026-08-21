<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Integration;

use Switon\McpClient\Exception\TransportException;
use Switon\McpClient\Tests\TestCase;
use Switon\McpClient\Transport\StdioTransportInterface;

/**
 * Real subprocess: stderr larger than the pipe must not block the JSON line,
 * and a read timeout must report that the process is still running plus the stderr tail.
 */
class StdioStderrTest extends TestCase
{
    public function testFloodedStderrStillReturnsStdout(): void
    {
        $transport = $this->transport(1, <<<'SH'
dd if=/dev/zero bs=81920 count=1 2>/dev/null 1>&2
printf '%s\n' '{"jsonrpc":"2.0","id":1,"result":[]}'
SH);
        $transport->open();
        try {
            $start = microtime(true);
            $response = $transport->send(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                static fn (): bool => false,
            );
            $elapsed = microtime(true) - $start;
        } finally {
            $transport->close();
        }

        $this->assertIsArray($response);
        $this->assertSame(1, $response['id']);
        $this->assertLessThan(4.0, $elapsed);
    }

    public function testTimeoutReportsRunningProcessAndStderr(): void
    {
        $transport = $this->transport(0.05, <<<'SH'
printf '%s\n' 'Downloading Chromium' >&2
sleep 30
SH);
        $transport->open();
        $start = microtime(true);
        try {
            $transport->send(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                static fn (): bool => false,
            );
            $this->fail('Expected a read timeout.');
        } catch (TransportException $e) {
            $elapsed = microtime(true) - $start;
            $this->assertLessThan(1.0, $elapsed);
            $this->assertStringContainsString('timed out', $e->getMessage());
            $this->assertStringContainsString('Process still running', $e->getMessage());
            $this->assertStringContainsString('Downloading Chromium', $e->getMessage());
        } finally {
            $transport->close();
        }
    }

    public function testStdoutCloseReportsExitCodeAfterReap(): void
    {
        $transport = $this->transport(0.5, <<<'SH'
printf '%s\n' 'command not found: npx' >&2
exit 127
SH);
        $transport->open();
        $start = microtime(true);
        try {
            $transport->send(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                static fn (): bool => false,
            );
            $this->fail('Expected stdout to close.');
        } catch (TransportException $e) {
            $elapsed = microtime(true) - $start;
            $this->assertLessThan(1.5, $elapsed);
            $this->assertStringContainsString('Process exited with 127', $e->getMessage());
            $this->assertStringNotContainsString('still running', $e->getMessage());
            $this->assertStringContainsString('command not found: npx', $e->getMessage());
        } finally {
            $transport->close();
        }
    }

    public function testCloseDoesNotWaitForHungChild(): void
    {
        $transport = $this->transport(0.05, <<<'SH'
sleep 30
SH);
        $transport->open();
        try {
            $transport->send(
                ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
                static fn (): bool => false,
            );
            $this->fail('Expected a read timeout.');
        } catch (TransportException) {
            // The child is still alive and would run for 30s.
        }

        $start = microtime(true);
        $transport->close();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed, 'close() must terminate the child instead of waiting for it.');
    }

    protected function transport(float $readTimeout, string $script): StdioTransportInterface
    {
        return $this->container->make(StdioTransportInterface::class, [
            'command' => '/bin/sh',
            'args' => ['-c', $script],
            'readTimeout' => $readTimeout,
        ]);
    }
}
