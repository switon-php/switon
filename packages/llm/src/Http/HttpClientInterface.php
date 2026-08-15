<?php

declare(strict_types=1);

namespace Switon\Llm\Http;

/**
 * Curl HTTP transport for LLM providers.
 *
 * @see \Switon\Llm\Event\HttpRequesting
 * @see \Switon\Llm\Event\HttpRequested
 * @see \Switon\Llm\Event\HttpStreaming
 * @see \Switon\Llm\Event\HttpFailed
 */
interface HttpClientInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, array $headers, string $body, int $timeout = 60): HttpResponse;

    /**
     * @param array<string, string> $headers
     * @param callable(string): void $onLine
     * @param int $timeout Idle stall seconds (wall clock unbounded while bytes keep arriving)
     * @param ?string $finishReason Non-null aborts the write loop (by ref)
     * @param null|callable(): bool $shouldAbort
     */
    public function stream(
        string    $url,
        array     $headers,
        string    $body,
        int       $timeout,
        callable  $onLine,
        ?string   &$finishReason = null,
        ?callable $shouldAbort = null,
    ): void;
}
