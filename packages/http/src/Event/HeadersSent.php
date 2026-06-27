<?php

declare(strict_types=1);

namespace Switon\Http\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Http\ResponseInterface;
use Switon\Http\ResponseSenderInterface;

/**
 * Event emitted after response headers are sent.
 *
 * Road-signs:
 * - emitted after transport headers are written
 * - pairs with HeadersSending
 *
 * Log category: <code>switon.http.headers.sent</code>
 *
 * @see \Switon\Http\ServerInterface
 * @see \Switon\Http\Event\HeadersSending
 * @see \Switon\Http\Server\Adapter\Native\Sender::sendHeaders()
 * @see \Switon\Http\Server\Adapter\Swoole::sendHeaders()
 */
#[EventLevel(Severity::DEBUG)]
class HeadersSent implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public ResponseSenderInterface $sender,
        public ResponseInterface $response
    )
    {
    }

    public function getSender(): ResponseSenderInterface
    {
        return $this->sender;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'status_code' => $this->response->getStatusCode(),
        ];
    }
}
