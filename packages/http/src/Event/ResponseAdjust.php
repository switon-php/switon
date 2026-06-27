<?php

declare(strict_types=1);

namespace Switon\Http\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;
use Switon\Http\RequestHandlerInterface;

/**
 * Application hook before response stringify and send.
 *
 * Guidance: Set string content here to skip default JSON encoding; leave arrays untouched to keep the normal stringify path.
 *
 * Road-signs:
 * - same exit for success, StopFlow, and handled exceptions
 * - runs immediately before ResponseStringify
 * - framework registers no default listeners here
 *
 * Log category: <code>switon.http.response.adjust</code>
 *
 * @see \Switon\Http\Event\RequestAdjust
 * @see \Switon\Http\Event\ResponseStringify
 * @see \Switon\Http\RequestHandler::handle()
 */
#[EventLevel(Severity::DEBUG)]
class ResponseAdjust implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public RequestHandlerInterface $sender,
        public RequestInterface $request,
        public ResponseInterface $response,
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
            'method' => $this->request->verb(),
            'status' => $this->response->getStatusCode(),
        ];
    }
}
