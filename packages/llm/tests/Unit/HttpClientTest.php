<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\ClockInterface;
use Switon\Eventing\ListenerProviderInterface;
use Switon\Llm\Event\HttpFailed;
use Switon\Llm\Event\HttpRequested;
use Switon\Llm\Event\HttpRequesting;
use Switon\Llm\Event\HttpStreaming;
use Switon\Llm\Exception\HttpStatusException;
use Switon\Llm\Exception\HttpTransportException;
use Switon\Llm\Http\HttpClient;
use Switon\Llm\Tests\TestCase;
use Switon\Llm\Tests\Unit\Fixtures\StubCurl;
use Switon\Testing\EventDispatcher as TestEventDispatcher;
use Switon\Testing\MockClock;

#[CoversClass(HttpClient::class)]
class HttpClientTest extends TestCase
{
    public function testPostDispatchesHttpLifecycleEvents(): void
    {
        $curl = new StubCurl();
        $curl->responseBody = '{"ok":true}';
        $curl->responseHeaders = [
            "Content-Type: application/json\r\n",
            "X-Request-Id: req-123\r\n",
        ];

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);
        $dispatcher = $this->eventDispatcher();

        $response = $httpClient->post('https://api.example.test/v1/chat/completions', ['Authorization' => 'Bearer x'], '{}');

        $this->assertSame(200, $response->statusCode);
        $this->assertSame('{"ok":true}', $response->body);
        $this->assertSame('application/json', $response->headers['content-type']);
        $this->assertSame('req-123', $response->headers['x-request-id']);

        $requesting = $dispatcher->getEventsByClass(HttpRequesting::class);
        $this->assertCount(1, $requesting);
        $this->assertSame('https://api.example.test/v1/chat/completions', $requesting[0]->url);
        $this->assertFalse($requesting[0]->stream);

        $requested = $dispatcher->getEventsByClass(HttpRequested::class);
        $this->assertCount(1, $requested);
        $this->assertSame(200, $requested[0]->response->statusCode);
        $this->assertSame('{"ok":true}', $requested[0]->response->body);
        $this->assertSame('req-123', $requested[0]->response->headers['x-request-id']);
    }

    public function testPostHttpRequestingMutationIsObserved(): void
    {
        $curl = new StubCurl();
        $curl->responseBody = 'ok';

        $dispatcher = $this->eventDispatcher();
        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);

        $this->container->get(ListenerProviderInterface::class)->on(
            null,
            static function (HttpRequesting $event): void {
                $event->url = 'https://mutated.test/chat/completions';
                $event->headers['Authorization'] = 'Bearer mutated';
            },
        );

        $httpClient->post('https://original.test/chat/completions', ['Authorization' => 'Bearer original'], '{}');

        $requested = $dispatcher->getEventsByClass(HttpRequested::class);
        $this->assertSame('https://mutated.test/chat/completions', $requested[0]->url);
        $this->assertSame('Bearer mutated', $requested[0]->headers['Authorization']);
    }

    public function testPostDispatchesHttpFailedOnErrorStatus(): void
    {
        $curl = new StubCurl();
        $curl->responseBody = 'bad gateway';
        $curl->statusCode = 502;

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);
        $dispatcher = $this->eventDispatcher();

        try {
            $httpClient->post('https://api.example.test/v1/chat/completions', [], '{}');
            $this->fail('Expected HttpStatusException.');
        } catch (HttpStatusException $e) {
            $this->assertSame(502, $e->getStatusCode());
            $this->assertSame('bad gateway', $e->getResponseBody());
        }

        $failed = $dispatcher->getEventsByClass(HttpFailed::class);
        $this->assertCount(1, $failed);
        $this->assertSame(502, $failed[0]->response?->statusCode);
        $this->assertSame('bad gateway', $failed[0]->response?->body);
        $this->assertCount(0, $dispatcher->getEventsByClass(HttpRequested::class));
    }

    public function testPostDispatchesHttpFailedOnNon2xxRedirect(): void
    {
        $curl = new StubCurl();
        $curl->responseBody = '';
        $curl->statusCode = 302;

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);
        $dispatcher = $this->eventDispatcher();

        try {
            $httpClient->post('https://api.example.test/v1/chat/completions', [], '{}');
            $this->fail('Expected HttpStatusException.');
        } catch (HttpStatusException $e) {
            $this->assertSame(302, $e->getStatusCode());
        }

        $this->assertCount(1, $dispatcher->getEventsByClass(HttpFailed::class));
        $this->assertCount(0, $dispatcher->getEventsByClass(HttpRequested::class));
    }

    public function testPostDispatchesHttpFailedWhenCurlExecFails(): void
    {
        $curl = new StubCurl();
        $curl->execFails = true;

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);
        $dispatcher = $this->eventDispatcher();

        try {
            $httpClient->post('https://api.example.test/v1/chat/completions', [], '{}');
            $this->fail('Expected HttpTransportException.');
        } catch (HttpTransportException $e) {
            $this->assertSame('LLM HTTP transport failed.', $e->getMessage());
        }

        $failed = $dispatcher->getEventsByClass(HttpFailed::class);
        $this->assertCount(1, $failed);
        $this->assertNull($failed[0]->response);
        $this->assertSame('LLM HTTP transport failed.', $failed[0]->error->getMessage());
    }

    public function testPostDispatchesHttpFailedWhenCurlInitFails(): void
    {
        $curl = new StubCurl();
        $curl->initFails = true;

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);
        $dispatcher = $this->eventDispatcher();

        try {
            $httpClient->post('https://api.example.test/v1/chat/completions', [], '{}');
            $this->fail('Expected HttpTransportException.');
        } catch (HttpTransportException $e) {
            $this->assertSame('Unable to initialize curl for LLM request.', $e->getMessage());
        }

        $failed = $dispatcher->getEventsByClass(HttpFailed::class);
        $this->assertCount(1, $failed);
        $this->assertNull($failed[0]->response);
        $this->assertSame('Unable to initialize curl for LLM request.', $failed[0]->error->getMessage());
    }

    public function testStreamDispatchesHttpStreamingAndRequested(): void
    {
        $curl = new StubCurl();
        $curl->streamChunks = ["data: hello\n", "data: [DONE]\n"];
        $curl->responseHeaders = [
            "Content-Type: text/event-stream\r\n",
            "X-Request-Id: stream-1\r\n",
        ];

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);
        $dispatcher = $this->eventDispatcher();
        $lines = [];

        $httpClient->stream('https://api.example.test/v1/chat/completions', [], '{}', 60, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $this->assertSame(['data: hello', 'data: [DONE]'], $lines);
        $this->assertSame(0, $curl->options[CURLOPT_TIMEOUT] ?? null);
        $this->assertSame(60, $curl->options[CURLOPT_CONNECTTIMEOUT] ?? null);
        $this->assertArrayHasKey(CURLOPT_XFERINFOFUNCTION, $curl->options);

        $streaming = $dispatcher->getEventsByClass(HttpStreaming::class);
        $this->assertCount(2, $streaming);
        $this->assertSame('data: hello', $streaming[0]->line);

        $requested = $dispatcher->getEventsByClass(HttpRequested::class);
        $this->assertCount(1, $requested);
        $this->assertTrue($requested[0]->stream);
        $this->assertSame(200, $requested[0]->response->statusCode);
        $this->assertSame('', $requested[0]->response->body);
        $this->assertSame('text/event-stream', $requested[0]->response->headers['content-type']);
        $this->assertSame('stream-1', $requested[0]->response->headers['x-request-id']);
    }

    public function testStreamIdleTimeoutAbortsWhenNoBytesArrive(): void
    {
        $clock = new MockClock(1_000.0);
        $this->container->replace(ClockInterface::class, $clock);

        $curl = new StubCurl();
        $curl->streamChunks = ["data: one\n", "data: two\n"];
        $curl->beforeChunk = static function (int $index) use ($clock): void {
            if ($index === 1) {
                $clock->advance(301.0);
            }
        };

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);
        $lines = [];

        try {
            $httpClient->stream('https://api.example.test/v1/chat/completions', [], '{}', 300, static function (string $line) use (&$lines): void {
                $lines[] = $line;
            }, );
            $this->fail('Expected HttpTransportException on idle stall.');
        } catch (HttpTransportException) {
        }

        $this->assertSame(['data: one'], $lines);
        $this->assertCount(1, $this->eventDispatcher()->getEventsByClass(HttpFailed::class));
    }

    public function testStreamKeepsGoingWhenBytesArriveSlowlyWithinIdleWindow(): void
    {
        $clock = new MockClock(1_000.0);
        $this->container->replace(ClockInterface::class, $clock);

        $curl = new StubCurl();
        $curl->streamChunks = ["data: one\n", "data: two\n", "data: [DONE]\n"];
        $curl->beforeChunk = static function (int $index) use ($clock): void {
            if ($index > 0) {
                $clock->advance(10.0);
            }
        };

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);
        $lines = [];

        $httpClient->stream('https://api.example.test/v1/chat/completions', [], '{}', 60, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, );

        $this->assertSame(['data: one', 'data: two', 'data: [DONE]'], $lines);
    }

    public function testStreamDispatchesHttpFailedOnCurlInitFailure(): void
    {
        $curl = new StubCurl();
        $curl->initFails = true;

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);
        $dispatcher = $this->eventDispatcher();

        try {
            $httpClient->stream('https://api.example.test/v1/chat/completions', [], '{}', 60, static function (string $line): void {
            });
            $this->fail('Expected HttpTransportException.');
        } catch (HttpTransportException $e) {
            $this->assertSame('Unable to initialize curl for LLM request.', $e->getMessage());
        }

        $failed = $dispatcher->getEventsByClass(HttpFailed::class);
        $this->assertCount(1, $failed);
        $this->assertNull($failed[0]->response);
        $this->assertSame('Unable to initialize curl for LLM request.', $failed[0]->error->getMessage());
    }

    public function testStreamShouldAbortStopsEarly(): void
    {
        $curl = new StubCurl();
        $curl->streamChunks = ["data: one\n", "data: two\n", "data: three\n"];

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);
        $lines = [];
        $polls = 0;

        $httpClient->stream('https://api.example.test/v1/chat/completions', [], '{}', 60, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, shouldAbort: static function () use (&$polls): bool {
            $polls++;

            return $polls >= 2;
        }, );

        $this->assertSame(['data: one'], $lines);

        $dispatcher = $this->eventDispatcher();
        $requested = $dispatcher->getEventsByClass(HttpRequested::class);
        $this->assertCount(1, $requested);
        $this->assertSame(200, $requested[0]->response->statusCode);
        $this->assertSame('', $requested[0]->response->body);
    }

    public function testStreamFinishReasonStopsEarly(): void
    {
        $curl = new StubCurl();
        $curl->streamChunks = ["data: one\n", "data: two\n", "data: three\n"];

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);
        $lines = [];
        $finishReason = null;

        $httpClient->stream('https://api.example.test/v1/chat/completions', [], '{}', 60, static function (string $line) use (&$lines, &$finishReason): void {
            $lines[] = $line;
            if ($line === 'data: one') {
                $finishReason = 'stop';
            }
        }, $finishReason, );

        $this->assertSame(['data: one'], $lines);
    }

    public function testStreamHttpFailedIncludesProviderErrorDetail(): void
    {
        $curl = new StubCurl();
        $curl->streamChunks = [
            '{"type":"error","error":{"type":"invalid_request_error","message":"tool call result does not follow tool call (2013)"}}' . "\n",
        ];
        $curl->statusCode = 400;

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);

        try {
            $httpClient->stream('https://api.example.test/v1/chat/completions', [], '{}', 60, static function (string $line): void {
            });
            $this->fail('Expected HttpStatusException.');
        } catch (HttpStatusException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('tool call result does not follow tool call', $e->getResponseBody());
        }
    }

    public function testStreamHttpFailedIncludesSseFramedProviderErrorDetail(): void
    {
        $curl = new StubCurl();
        $curl->streamChunks = [
            'data: {"type":"error","error":{"type":"invalid_request_error","message":"sse framed provider error"}}\n',
        ];
        $curl->statusCode = 400;

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);

        try {
            $httpClient->stream('https://api.example.test/v1/chat/completions', [], '{}', 60, static function (string $line): void {
            });
            $this->fail('Expected HttpStatusException.');
        } catch (HttpStatusException $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertStringContainsString('sse framed provider error', $e->getResponseBody());
        }
    }

    public function testStreamHttpFailedIncludesOpenAiStyleErrorWithoutTypeField(): void
    {
        $curl = new StubCurl();
        $curl->streamChunks = [
            '{"error":{"message":"model \'qwen2.5-coder:7b\' not found","type":"not_found_error","param":null,"code":null}}' . "\n",
        ];
        $curl->statusCode = 404;

        $httpClient = $this->make(HttpClient::class, ['curl' => $curl]);

        try {
            $httpClient->stream('https://api.example.test/v1/chat/completions', [], '{}', 60, static function (string $line): void {
            });
            $this->fail('Expected HttpStatusException.');
        } catch (HttpStatusException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertStringContainsString("model 'qwen2.5-coder:7b' not found", $e->getResponseBody());
            $this->assertStringContainsString("model 'qwen2.5-coder:7b' not found", $e->interruptMessage());
        }
    }

    protected function eventDispatcher(): TestEventDispatcher
    {
        $dispatcher = $this->container->get(EventDispatcherInterface::class);
        $this->assertInstanceOf(TestEventDispatcher::class, $dispatcher);

        return $dispatcher;
    }
}
