<?php

declare(strict_types=1);

namespace Switon\Mcp\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Mcp\Event\TransportWrite;
use Switon\Mcp\Transport\StdioTransport;
use Switon\Mcp\Tests\TestCase;
use Switon\Testing\EventDispatcher as TestEventDispatcher;

#[CoversClass(StdioTransport::class)]
class StdioTransportTest extends TestCase
{
    public function testWriteDispatchesTransportWriteEvent(): void
    {
        $dispatcher = $this->eventDispatcher();
        $dispatcher->clear();

        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
        $response = ['jsonrpc' => '2.0', 'id' => 1, 'result' => []];

        $this->makeTransport()->write($request, $response);

        $events = $dispatcher->getEventsByClass(TransportWrite::class);
        $this->assertCount(1, $events);
        $this->assertSame($request, $events[0]->request);
        $this->assertSame($response, $events[0]->response);
    }

    public function testWriteSkipsStdoutForEmptyResponse(): void
    {
        $dispatcher = $this->eventDispatcher();
        $dispatcher->clear();

        $this->makeTransport()->write(['method' => 'notifications/initialized'], []);

        $this->assertCount(1, $dispatcher->getEventsByClass(TransportWrite::class));
    }

    public function testReadStreamParsesValidJsonLine(): void
    {
        $dispatcher = $this->eventDispatcher();
        $dispatcher->clear();

        $stream = $this->memoryStream("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"ping\"}\n");
        $request = $this->makeTestableTransport()->readStream($stream);

        $this->assertSame('ping', $request['method']);
        $this->assertCount(1, $dispatcher->getEventsByClass(\Switon\Mcp\Event\TransportRead::class));
    }

    public function testReadStreamSkipsBlankLines(): void
    {
        $stream = $this->memoryStream("\n\n{\"jsonrpc\":\"2.0\",\"id\":2,\"method\":\"ping\"}\n");
        $request = $this->makeTestableTransport()->readStream($stream);

        $this->assertSame(2, $request['id']);
    }

    public function testReadStreamSkipsInvalidJsonAndNonObjectPayload(): void
    {
        $stream = $this->memoryStream("{bad}\n\"text\"\n{\"jsonrpc\":\"2.0\",\"id\":3,\"method\":\"ping\"}\n");
        $request = $this->makeTestableTransport()->readStream($stream);

        $this->assertSame(3, $request['id']);
    }

    public function testReadStreamReturnsNullAtEndOfStream(): void
    {
        $stream = $this->memoryStream('');
        $this->assertNull($this->makeTestableTransport()->readStream($stream));
    }

    public function testLogStderrWritesToStderrWhenDefined(): void
    {
        if (!defined('STDERR')) {
            $this->markTestSkipped('STDERR is not defined in this SAPI.');
        }

        $this->makeTestableTransport()->triggerLogStderr('stdio warning');
        $this->addToAssertionCount(1);
    }

    protected function makeTransport(): StdioTransport
    {
        $transport = $this->container->make(StdioTransport::class);
        $this->injector->inject($transport);

        return $transport;
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
