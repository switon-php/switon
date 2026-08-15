<?php

declare(strict_types=1);

namespace Switon\Llm\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Llm\Http\HttpClientInterface;
use Switon\Llm\Http\HttpResponse;

/**
 * Emitted after a successful LLM HTTP response.
 *
 * Log category: `switon.llm.http.requested`
 *
 * @see \Switon\Llm\Http\HttpClient::post()
 * @see \Switon\Llm\Http\HttpClient::stream()
 * @see \Switon\Llm\Event\HttpRequesting
 */
#[EventLevel(Severity::DEBUG)]
class HttpRequested implements JsonSerializable, SenderAwareInterface
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
        public HttpResponse        $response,
        public float               $elapsed,
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
            'status_code' => $this->response->statusCode,
            'stream' => $this->stream,
            'elapsed' => $this->elapsed,
        ];
    }
}
