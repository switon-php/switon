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
 * Event emitted before response headers are sent.
 *
 * Road-signs:
 * - emitted after response state is finalized
 * - listeners may inspect headers before transport output
 * - HeadersSent follows after emission
 *
 * Log category: <code>switon.http.headers.sending</code>
 *
 * @see \Switon\Http\ServerInterface
 * @see \Switon\Http\Server\Adapter\Native\Sender::sendHeaders()
 * @see \Switon\Http\Server\Adapter\Swoole::sendHeaders()
 * @see \Switon\Http\Event\HeadersSent
 */
#[EventLevel(Severity::DEBUG)]
class HeadersSending implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public ResponseSenderInterface $sender,
        public ResponseInterface $response
    ) {

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
            'content_type' => $this->response->getHeader('Content-Type') ?? '',
        ];
    }
}
