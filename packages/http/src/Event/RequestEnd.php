<?php

declare(strict_types=1);

namespace Switon\Http\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Http\RequestHandlerInterface;
use Switon\Http\RequestInterface;
use Switon\Http\ResponseInterface;

use function strlen;

/**
 * Event emitted at the end of request handling.
 *
 * Log category: <code>switon.http.request.end</code>
 *
 * @see \Switon\Http\RequestHandlerInterface
 * @see \Switon\Http\RequestHandler::handle()
 * @see \Switon\Http\Event\RequestBegin
 * @see \Switon\Http\ResponseInterface
 */
#[EventLevel(Severity::DEBUG)]
class RequestEnd implements JsonSerializable, SenderAwareInterface
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
            'uri' => $this->request->path(),
            'http_code' => $this->response->getStatusCode(),
            'content-type' => $this->response->getHeader('Content-Type'),
            'content-length' => strlen($this->response->getContent() ?? ''),
        ];
    }
}
