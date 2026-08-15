<?php

declare(strict_types=1);

namespace Switon\Llm\Tests\Unit\Fixtures;

use Switon\Llm\Http\HttpClientInterface;
use Switon\Llm\Http\HttpResponse;

/** @internal Test double for {@see HttpClientInterface}. */
class StubHttpClient implements HttpClientInterface
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, timeout: int}> */
    public array $posts = [];

    /** @var list<array{url: string, headers: array<string, string>, body: string, timeout: int}> */
    public array $streams = [];

    public string $responseBody = '';

    public int $responseStatusCode = 200;

    /** @var array<string, string> */
    public array $responseHeaders = [];

    /** @var list<string> */
    public array $streamLines = [];

    public function post(string $url, array $headers, string $body, int $timeout = 60): HttpResponse
    {
        $this->posts[] = compact('url', 'headers', 'body', 'timeout');

        return new HttpResponse($this->responseStatusCode, $this->responseBody, $this->responseHeaders);
    }

    public function stream(
        string $url,
        array $headers,
        string $body,
        int $timeout,
        callable $onLine,
        ?string &$finishReason = null,
        ?callable $shouldAbort = null,
    ): void {
        $this->streams[] = compact('url', 'headers', 'body', 'timeout');

        foreach ($this->streamLines as $line) {
            if ($finishReason !== null && $finishReason !== '') {
                return;
            }

            if ($shouldAbort !== null && $shouldAbort()) {
                return;
            }

            $onLine($line);

            if ($finishReason !== null && $finishReason !== '') {
                return;
            }
        }
    }
}
