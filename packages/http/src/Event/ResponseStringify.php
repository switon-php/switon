<?php

declare(strict_types=1);

namespace Switon\Http\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Http\ResponseInterface;
use Switon\Http\RequestHandlerInterface;

/**
 * Event emitted before a response is stringified.
 *
 * Road-signs:
 * - emitted after ResponseAdjust
 * - listeners may inspect or replace the response body representation
 *
 * Log category: <code>switon.http.response.stringify</code>
 *
 * @see \Switon\Http\RequestHandler::handle()
 * @see \Switon\Http\ResponseInterface
 * @see \Switon\Inspector\Inspector::onResponseStringify()
 */
#[EventLevel(Severity::DEBUG)]
class ResponseStringify implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public RequestHandlerInterface $sender,
        public ResponseInterface $response
    ) {
    }

    public function getSender(): RequestHandlerInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'status' => $this->response->getStatusCode(),
            'content_type' => $this->response->getHeader('Content-Type'),
        ];
    }
}
