<?php

declare(strict_types=1);

namespace Switon\McpClient\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\ProcessTree;
use Switon\McpClient\Event\TransportRead;
use Switon\McpClient\Event\TransportWrite;
use Switon\McpClient\Exception\AbortedTransportException;
use Switon\McpClient\Exception\TransportException;
use Switon\McpClient\Tests\TestCase;
use Switon\McpClient\Transport\StdioTransport;
use Switon\Testing\EventDispatcher as TestEventDispatcher;

use function bin2hex;
use function file_get_contents;
use function file_put_contents;
use function is_file;
use function mkdir;
use function random_bytes;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function unlink;
use function usleep;

#[CoversClass(StdioTransport::class)]
class StdioTransportTest extends TestCase
{
    public function testSendNotificationWritesOnly(): void
    {
        $dispatcher = $this->eventDispatcher();
        $dispatcher->clear();

        $stdin = fopen('php://memory', 'w+');
        $stdout = fopen('php://memory', 'r+');
        $transport = $this->makeTestableTransport();
        $transport->stubOpen($stdin, $stdout);

        $message = ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'];
        $this->assertNull($transport->send($message, static fn (): bool => false));

        $events = $dispatcher->getEventsByClass(TransportWrite::class);
        $this->assertCount(1, $events);
        $this->assertSame($message, $events[0]->message);
        $this->assertCount(0, $dispatcher->getEventsByClass(TransportRead::class));
        rewind($stdin);
        $this->assertSame('{"jsonrpc":"2.0","method":"notifications/initialized"}' . "\n", stream_get_contents($stdin));
    }

    public function testSendRequestRoundTripsResponse(): void
    {
        $dispatcher = $this->eventDispatcher();
        $dispatcher->clear();

        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
        $response = ['jsonrpc' => '2.0', 'id' => 1, 'result' => []];

        $stdin = fopen('php://memory', 'w+');
        $stdout = fopen('php://memory', 'r+');
        fwrite($stdout, '{"jsonrpc":"2.0","id":1,"result":[]}' . "\n");
        rewind($stdout);

        $transport = $this->makeTestableTransport();
        $transport->stubOpen($stdin, $stdout);

        $this->assertSame($response, $transport->send($request, static fn (): bool => false));

        $readEvents = $dispatcher->getEventsByClass(TransportRead::class);
        $this->assertCount(1, $readEvents);
        $this->assertSame($response, $readEvents[0]->message);
    }

    public function testSendSkipsBlankLinesAndInvalidJson(): void
    {
        $response = ['jsonrpc' => '2.0', 'id' => 2, 'result' => []];
        $stdout = $this->memoryStream("\n{bad}\n" . '{"jsonrpc":"2.0","id":2,"result":[]}' . "\n");
        $transport = $this->makeTestableTransport();
        $transport->stubOpen(fopen('php://memory', 'w+'), $stdout);

        $this->assertSame($response, $transport->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'], static fn (): bool => false));
    }

    public function testSendReturnsNullWhenStdoutCloses(): void
    {
        $transport = $this->makeTestableTransport();
        $transport->stubOpen(fopen('php://memory', 'w+'), fopen('php://memory', 'r+'));

        $this->assertNull($transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], static fn (): bool => false));
    }

    public function testSendForwardsNotificationsBeforeMatchingResponse(): void
    {
        $stdout = $this->memoryStream(
            '{"jsonrpc":"2.0","method":"notifications/progress","params":{"step":1}}' . "\n" .
            '{"jsonrpc":"2.0","id":2,"result":[]}' . "\n"
        );
        $transport = $this->makeTestableTransport();
        $transport->stubOpen(fopen('php://memory', 'w+'), $stdout);

        $notifications = [];
        $response = $transport->send(
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
            static fn (): bool => false,
            static function (array $message) use (&$notifications): void {
                $notifications[] = $message;
            },
        );

        $this->assertSame(['jsonrpc' => '2.0', 'id' => 2, 'result' => []], $response);
        $this->assertSame([
            ['jsonrpc' => '2.0', 'method' => 'notifications/progress', 'params' => ['step' => 1]],
        ], $notifications);
    }

    public function testSendDoesNotWriteWhenAlreadyAborted(): void
    {
        $stdin = fopen('php://memory', 'w+');
        $transport = $this->makeTestableTransport();
        $transport->stubOpen($stdin, fopen('php://memory', 'r+'));

        try {
            $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], static fn (): bool => true);
            $this->fail('Abort must raise before the request is written.');
        } catch (AbortedTransportException) {
            rewind($stdin);
            $this->assertSame('', stream_get_contents($stdin));
        }
    }

    public function testSendRejectsALinePastTheCap(): void
    {
        $stdout = $this->socketPair();
        $stderr = $this->socketPair();
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);
        stream_set_blocking($stderr[0], false);
        fclose($stderr[1]);
        fwrite($stdout[1], str_repeat('x', 64));

        $transport = $this->makeTestableTransport();
        $transport->setReadTimeout(0.2);
        $transport->setMaxLineBytes(32);
        $transport->stubOpen(fopen('php://memory', 'w+'), $stdout[0], $stderr[0]);

        try {
            $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], static fn (): bool => false);
            $this->fail('A line without a newline must stop at the byte cap.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('exceeded', $e->getMessage());
        } finally {
            fclose($stdout[0]);
            fclose($stdout[1]);
            fclose($stderr[0]);
        }
    }

    public function testSendRaisesTransportExceptionOnReadTimeout(): void
    {
        $pair = $this->socketPair();
        [$read, $write] = $pair;
        // Keep $write open but never write -> $read blocks until the timeout fires.

        $transport = $this->makeTestableTransport();
        $transport->setReadTimeout(0.05);
        $transport->stubOpen(fopen('php://memory', 'w+'), $read);

        $start = microtime(true);
        try {
            $transport->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], static fn (): bool => false);
            $this->fail('Expected TransportException on read timeout.');
        } catch (TransportException $e) {
            $elapsed = microtime(true) - $start;
            $this->assertGreaterThan(0.04, $elapsed, 'Read must block until the timeout fires.');
            $this->assertLessThan(0.5, $elapsed);
            $this->assertStringContainsString('timed out', $e->getMessage());
        } finally {
            fclose($read);
            fclose($write);
        }
    }

    public function testCloseStopsDescendant(): void
    {
        $dir = sys_get_temp_dir() . '/switon-stdio-proc-' . bin2hex(random_bytes(4));
        mkdir($dir, 0700, true);
        $pidFile = $dir . '/child.pid';
        $launcher = $dir . '/parent.php';
        file_put_contents($launcher, <<<'PHP'
<?php
$pidFile = $argv[1];
$proc = proc_open(
    [PHP_BINARY, '-r', 'sleep(60);'],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
);
if (!is_resource($proc)) {
    fwrite(STDERR, "child did not start\n");
    exit(1);
}
$status = proc_get_status($proc);
file_put_contents($pidFile, (string) $status['pid']);
while (true) {
    sleep(30);
}
PHP);
        $transport = $this->make(StdioTransport::class, [
            'command' => PHP_BINARY,
            'args' => [$launcher, $pidFile],
        ]);
        $pid = 0;
        try {
            $transport->open();
            $deadline = microtime(true) + 2;
            while (microtime(true) < $deadline && !(is_file($pidFile) && (int) file_get_contents($pidFile) > 0)) {
                usleep(1000);
            }
            $this->assertTrue(is_file($pidFile));
            $pid = (int) file_get_contents($pidFile);
            $this->assertTrue(ProcessTree::alive($pid));
            $transport->close();
            $gone = microtime(true) + 0.5;
            while (microtime(true) < $gone && ProcessTree::alive($pid)) {
                usleep(1000);
            }
            $this->assertFalse(ProcessTree::alive($pid), 'descendant still alive after stdio close');
        } finally {
            $transport->close();
            if ($pid > 0 && ProcessTree::alive($pid)) {
                ProcessTree::terminate($pid, 0.0);
            }
            @unlink($pidFile);
            @unlink($launcher);
            @rmdir($dir);
        }
    }

    protected function makeTestableTransport(): TestableStdioTransport
    {
        $transport = new TestableStdioTransport();
        $this->injector->inject($transport);

        return $transport;
    }

    /**
     * @return resource
     */
    /**
     * @return array{0: resource, 1: resource}
     */
    protected function socketPair(): array
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $pair = @stream_socket_pair(STREAM_PF_INET, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        }
        $this->assertIsArray($pair);

        return $pair;
    }

    /**
     * @return resource
     */
    protected function memoryStream(string $contents)
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        return $stream;
    }

    protected function eventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }
}
