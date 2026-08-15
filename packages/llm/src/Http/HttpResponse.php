<?php

declare(strict_types=1);

namespace Switon\Llm\Http;

/**
 * HTTP response metadata returned by the LLM transport.
 */
class HttpResponse
{
    /**
     * @param array<string, string> $headers Lowercase response header name => last observed value.
     */
    public function __construct(public int $statusCode, public string $body, public array $headers = [])
    {
    }
}
