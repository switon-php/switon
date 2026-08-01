<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Integration;

use Switon\Mcp\Tests\TestCase;

class StdioTransportIntegrationTest extends TestCase
{
    public function testReadParsesRequestFromStdin(): void
    {
        $fixture = dirname(__DIR__) . '/Fixtures/stdio_read_once.php';
        $root = dirname(__DIR__, 2);

        $proc = proc_open(
            [PHP_BINARY, $fixture],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        $this->assertIsResource($proc);

        fwrite($pipes[0], '{"jsonrpc":"2.0","id":1,"method":"ping"}' . "\n");
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $decoded = json_decode($stdout, true);
        $this->assertSame('ping', $decoded['method']);
        $this->assertSame(1, $decoded['id']);
    }

    public function testWriteOutputsJsonLine(): void
    {
        $fixture = dirname(__DIR__) . '/Fixtures/stdio_write_once.php';
        $root = dirname(__DIR__, 2);

        $proc = proc_open(
            [PHP_BINARY, $fixture],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        $this->assertIsResource($proc);

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $this->assertSame('{"jsonrpc":"2.0","id":7,"result":[]}', trim($stdout));
    }
}
