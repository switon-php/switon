<?php

declare(strict_types=1);

namespace Switon\Llm\Http;

use Psr\EventDispatcher\EventDispatcherInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ClockInterface;
use Switon\Llm\Event\HttpFailed;
use Switon\Llm\Event\HttpRequested;
use Switon\Llm\Event\HttpRequesting;
use Switon\Llm\Event\HttpStreaming;
use Switon\Llm\Exception\HttpStatusException;
use Switon\Llm\Exception\HttpTransportException;
use Throwable;

use function is_array;
use function is_string;
use function json_decode;
use function rtrim;
use function str_starts_with;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function trim;

/**
 * Curl-backed HTTP client for LLM requests (JSON and SSE).
 *
 * @see \Switon\Llm\Http\HttpClientInterface
 */
class HttpClient implements HttpClientInterface
{
    #[Autowired] protected CurlInterface $curl;
    #[Autowired] protected EventDispatcherInterface $eventDispatcher;
    #[Autowired] protected ClockInterface $clock;

    /**
     * @param array<string, string> $headers
     */
    public function post(string $url, array $headers, string $body, int $timeout = 60): HttpResponse
    {
        $started = $this->clock->microtime();
        $requesting = new HttpRequesting($this, $url, $headers, $body, $timeout, stream: false);
        $this->eventDispatcher->dispatch($requesting);
        $responseHeaders = [];

        try {
            $handle = $this->curl->init();
            if ($handle === false) {
                HttpTransportException::raise('Unable to initialize curl for LLM request.');
            }

            $wireHeaders = [];
            foreach ($requesting->headers as $name => $value) {
                $wireHeaders[] = $name . ': ' . $value;
            }

            $this->curl->setopt($handle, CURLOPT_URL, $requesting->url);
            $this->curl->setopt($handle, CURLOPT_POST, true);
            $this->curl->setopt($handle, CURLOPT_POSTFIELDS, $requesting->body);
            $this->curl->setopt($handle, CURLOPT_HTTPHEADER, $wireHeaders);
            $this->curl->setopt($handle, CURLOPT_TIMEOUT, $requesting->timeout);
            $this->curl->setopt($handle, CURLOPT_RETURNTRANSFER, true);
            $this->curl->setopt(
                $handle,
                CURLOPT_HEADERFUNCTION,
                function ($ch, string $line) use (&$responseHeaders): int {
                    $this->collectHeaderLine($responseHeaders, $line);

                    return strlen($line);
                },
            );
            $responseBody = $this->curl->exec($handle);
        } catch (Throwable $e) {
            $this->eventDispatcher->dispatch(new HttpFailed(
                $requesting->sender,
                $requesting->url,
                $requesting->headers,
                $requesting->body,
                $requesting->timeout,
                $requesting->stream,
                $e,
                $this->clock->elapsed($started),
            ));

            throw $e;
        }

        if ($responseBody === false) {
            $errno = $this->curl->errno($handle);
            $errorMessage = $this->curl->error($handle);
            $elapsed = $this->clock->elapsed($started);
            $error = HttpTransportException::of(
                'LLM HTTP transport failed.',
                ['errno' => $errno, 'error' => $errorMessage,],
            );
            $this->eventDispatcher->dispatch(new HttpFailed(
                $requesting->sender,
                $requesting->url,
                $requesting->headers,
                $requesting->body,
                $requesting->timeout,
                $requesting->stream,
                $error,
                $elapsed,
            ));

            throw $error;
        }

        $statusCode = (int)$this->curl->getinfo($handle, CURLINFO_RESPONSE_CODE);
        $elapsed = $this->clock->elapsed($started);
        $response = new HttpResponse($statusCode, (string)$responseBody, $responseHeaders);

        if ($statusCode < 200 || $statusCode >= 300) {
            $error = HttpStatusException::of(
                'LLM HTTP request failed ({status_code}).',
                ['status_code' => $statusCode, 'body' => $response->body,],
            );
            $this->eventDispatcher->dispatch(new HttpFailed(
                $requesting->sender,
                $requesting->url,
                $requesting->headers,
                $requesting->body,
                $requesting->timeout,
                $requesting->stream,
                $error,
                $elapsed,
                $response,
            ));

            throw $error;
        }

        $this->eventDispatcher->dispatch(new HttpRequested(
            $requesting->sender,
            $requesting->url,
            $requesting->headers,
            $requesting->body,
            $requesting->timeout,
            $requesting->stream,
            $response,
            $elapsed,
        ));

        return $response;
    }

    /**
     * @param array<string, string> $headers
     */
    public function stream(
        string $url,
        array $headers,
        string $body,
        int $timeout,
        callable $onLine,
        ?string &$finishReason = null,
        ?callable $shouldAbort = null,
    ): void {
        $started = $this->clock->microtime();
        $requesting = new HttpRequesting($this, $url, $headers, $body, $timeout, stream: true);
        $this->eventDispatcher->dispatch($requesting);

        $buffer = '';
        $responseHeaders = [];
        /** @var list<string> $streamLines */
        $streamLines = [];

        try {
            $handle = $this->curl->init();
            if ($handle === false) {
                HttpTransportException::raise('Unable to initialize curl for LLM request.');
            }

            $wireHeaders = [];
            foreach ($requesting->headers as $name => $value) {
                $wireHeaders[] = $name . ': ' . $value;
            }

            $this->curl->setopt($handle, CURLOPT_URL, $requesting->url);
            $this->curl->setopt($handle, CURLOPT_POST, true);
            $this->curl->setopt($handle, CURLOPT_POSTFIELDS, $requesting->body);
            $this->curl->setopt($handle, CURLOPT_HTTPHEADER, $wireHeaders);
            // Streaming: no wall-clock CURLOPT_TIMEOUT — abort only on idle stall (no bytes).
            $this->curl->setopt($handle, CURLOPT_TIMEOUT, 0);
            $idleSeconds = max(1, $requesting->timeout);
            $this->curl->setopt($handle, CURLOPT_CONNECTTIMEOUT, $idleSeconds);
            $lastByteAt = $this->clock->microtime();
            $this->curl->setopt($handle, CURLOPT_NOPROGRESS, false);
            $this->curl->setopt(
                $handle,
                CURLOPT_XFERINFOFUNCTION,
                function ($ch, $downloadTotal, $downloaded, $uploadTotal, $uploaded) use (&$lastByteAt, $idleSeconds): int {
                    if ($this->clock->microtime() - $lastByteAt > $idleSeconds) {
                        return 1;
                    }

                    return 0;
                },
            );
            $this->curl->setopt($handle, CURLOPT_RETURNTRANSFER, false);
            $this->curl->setopt(
                $handle,
                CURLOPT_HEADERFUNCTION,
                function ($ch, string $line) use (&$responseHeaders): int {
                    $this->collectHeaderLine($responseHeaders, $line);

                    return strlen($line);
                },
            );
            $this->curl->setopt(
                $handle,
                CURLOPT_WRITEFUNCTION,
                function ($ch, string $chunk) use (&$buffer, $onLine, $requesting, &$finishReason, &$streamLines, $shouldAbort, &$lastByteAt): int {
                    $lastByteAt = $this->clock->microtime();
                    if ($this->shouldStopStream($finishReason, $shouldAbort)) {
                        return 0;
                    }

                    $buffer .= $chunk;

                    while (($position = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $position);
                        $buffer = substr($buffer, $position + 1);
                        $line = rtrim($line, "\r");
                        $streamLines[] = $line;
                        $this->eventDispatcher->dispatch(new HttpStreaming(
                            $requesting->sender,
                            $requesting->url,
                            $requesting->headers,
                            $requesting->body,
                            $requesting->timeout,
                            $requesting->stream,
                            $line,
                        ));
                        $onLine($line);

                        if ($this->shouldStopStream($finishReason, $shouldAbort)) {
                            return 0;
                        }
                    }

                    return strlen($chunk);
                },
            );
            $success = $this->curl->exec($handle);
        } catch (Throwable $e) {
            $this->eventDispatcher->dispatch(new HttpFailed(
                $requesting->sender,
                $requesting->url,
                $requesting->headers,
                $requesting->body,
                $requesting->timeout,
                $requesting->stream,
                $e,
                $this->clock->elapsed($started),
            ));

            throw $e;
        }

        if ($success === false && $this->shouldStopStream($finishReason, $shouldAbort)) {
            $elapsed = $this->clock->elapsed($started);
            $response = new HttpResponse(200, '', $responseHeaders);
            $this->eventDispatcher->dispatch(new HttpRequested(
                $requesting->sender,
                $requesting->url,
                $requesting->headers,
                $requesting->body,
                $requesting->timeout,
                $requesting->stream,
                $response,
                $elapsed,
            ));

            return;
        }

        if ($success === false) {
            $errno = $this->curl->errno($handle);
            $errorMessage = $this->curl->error($handle);
            $elapsed = $this->clock->elapsed($started);
            $error = HttpTransportException::of(
                'LLM HTTP transport failed.',
                ['errno' => $errno, 'error' => $errorMessage,],
            );
            $this->eventDispatcher->dispatch(new HttpFailed(
                $requesting->sender,
                $requesting->url,
                $requesting->headers,
                $requesting->body,
                $requesting->timeout,
                $requesting->stream,
                $error,
                $elapsed,
            ));

            throw $error;
        }

        if ($buffer !== '') {
            $line = rtrim($buffer, "\r");
            $streamLines[] = $line;
            $this->eventDispatcher->dispatch(new HttpStreaming(
                $requesting->sender,
                $requesting->url,
                $requesting->headers,
                $requesting->body,
                $requesting->timeout,
                $requesting->stream,
                $line,
            ));
            $onLine($line);
        }

        $statusCode = (int)$this->curl->getinfo($handle, CURLINFO_RESPONSE_CODE);
        $elapsed = $this->clock->elapsed($started);
        $errorBody = $this->extractStreamErrorDetail($streamLines);
        if ($errorBody === '' && $buffer !== '') {
            $errorBody = trim($buffer);
        }
        $response = new HttpResponse($statusCode, $errorBody, $responseHeaders);

        if ($statusCode < 200 || $statusCode >= 300) {
            $error = HttpStatusException::of('LLM HTTP streaming request failed.', [
                'status_code' => $statusCode,
                'body' => $errorBody,
            ]);
            $this->eventDispatcher->dispatch(new HttpFailed(
                $requesting->sender,
                $requesting->url,
                $requesting->headers,
                $requesting->body,
                $requesting->timeout,
                $requesting->stream,
                $error,
                $elapsed,
                $response,
            ));

            throw $error;
        }

        $this->eventDispatcher->dispatch(new HttpRequested(
            $requesting->sender,
            $requesting->url,
            $requesting->headers,
            $requesting->body,
            $requesting->timeout,
            $requesting->stream,
            $response,
            $elapsed,
        ));
    }

    /**
     * @phpstan-impure Finish reason / abort state can change between calls (by-ref + callback).
     */
    protected function shouldStopStream(?string $finishReason, ?callable $shouldAbort): bool
    {
        // Empty string is "not finished" (some OpenAI-compat relays); only real reasons abort.
        if ($finishReason !== null && $finishReason !== '') {
            return true;
        }

        return $shouldAbort !== null && $shouldAbort();
    }

    /**
     * @param array<string, string> $headers
     */
    protected function collectHeaderLine(array &$headers, string $line): void
    {
        $line = trim($line);
        if ($line === '' || !str_contains($line, ':')) {
            return;
        }

        [$name, $value] = explode(':', $line, 2);
        $name = strtolower(trim($name));
        if ($name === '') {
            return;
        }

        $headers[$name] = trim($value);
    }

    /**
     * @param list<string> $streamLines
     */
    protected function extractStreamErrorDetail(array $streamLines): string
    {
        $detail = '';
        foreach ($streamLines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, 'data:')) {
                $line = trim(substr($line, 5));
            }

            if ($line === '' || $line === '[DONE]' || !str_starts_with($line, '{')) {
                continue;
            }

            $payload = json_decode($line, true);
            if (!is_array($payload)) {
                continue;
            }

            // Anthropic: {"type":"error","error":{...}} · OpenAI/Ollama: {"error":{"message":"..."}}
            $error = $payload['error'] ?? null;
            $message = '';
            if (is_string($error)) {
                $message = trim($error);
            } elseif (is_array($error)) {
                $message = is_string($error['message'] ?? null) ? trim($error['message']) : '';
            }

            if ($message !== '') {
                $detail = $message;
            }
        }

        return $detail;
    }
}
