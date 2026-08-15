<?php

declare(strict_types=1);

namespace Switon\Llm\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Llm\Http\HttpClientInterface;

/**
 * Before an LLM HTTP request.
 *
 * Log category: `switon.llm.http.requesting`
 *
 * Guidance: Listeners may mutate url/headers/body before transport.
 *
 * @see \Switon\Llm\Http\HttpClient::post()
 * @see \Switon\Llm\Http\HttpClient::stream()
 * @see HttpRequested
 */
#[EventLevel(Severity::DEBUG)]
class HttpRequesting implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public HttpClientInterface $sender,
        public string              $url,
        public array               $headers,
        public string              $body,
        public int                 $timeout = 60,
        public bool                $stream = false,
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
            'timeout' => $this->timeout,
        ];
    }
}
