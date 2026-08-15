<?php

declare(strict_types=1);

namespace Switon\Llm\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Llm\Http\HttpClientInterface;

/**
 * Emitted for each complete SSE line during streaming HTTP.
 *
 * Log category: `switon.llm.http.streaming`
 *
 * @see \Switon\Llm\Http\HttpClient::stream()
 */
#[EventLevel(Severity::DEBUG)]
class HttpStreaming implements JsonSerializable, SenderAwareInterface
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
        public string              $line,
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
            'line_length' => strlen($this->line),
        ];
    }
}
