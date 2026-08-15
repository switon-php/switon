<?php

declare(strict_types=1);

namespace Switon\Llm\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Llm\Http\HttpClientInterface;
use Switon\Llm\Http\HttpResponse;
use Throwable;

/**
 * Emitted when LLM HTTP transport fails or returns a non-success status.
 *
 * Log category: `switon.llm.http.failed`
 *
 * Guidance: `$response` is set for non-success HTTP replies; transport/init failures leave it null.
 *
 * @see \Switon\Llm\Http\HttpClient::post()
 * @see \Switon\Llm\Http\HttpClient::stream()
 */
#[EventLevel(Severity::WARNING)]
class HttpFailed implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public HttpClientInterface $sender,
        public string              $url,
        public array               $headers,
        public string              $body,
        public int                 $timeout,
        public bool                $stream,
        public Throwable           $error,
        public float               $elapsed,
        public ?HttpResponse       $response = null,
    ) {
    }

    public function getSender(): HttpClientInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'url' => $this->url,
            'stream' => $this->stream,
            'status_code' => $this->response?->statusCode,
            'error' => $this->error->getMessage(),
            'elapsed' => $this->elapsed,
        ];
    }
}
