<?php

declare(strict_types=1);

namespace Switon\Http\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Switon\Http\ResponseInterface;
use Switon\Http\ResponseSenderInterface;

use function strlen;

/**
 * Event emitted after a response body is sent.
 *
 * Road-signs:
 * - emitted after non-streaming body output completes
 * - carries final body length and send result
 * - pairs with BodySending
 *
 * Log category: <code>switon.http.body.sent</code>
 *
 * @see \Switon\Http\ServerInterface
 * @see \Switon\Http\Event\BodySending
 * @see \Switon\Http\Server\Adapter\Native\Sender::sendBody()
 * @see \Switon\Http\Server\Adapter\Swoole::sendBody()
 */
#[EventLevel(Severity::DEBUG)]
class BodySent implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public ResponseSenderInterface $sender,
        public ResponseInterface $response,
        public string $content,
        public bool $result
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
            'content_length' => strlen($this->content),
            'result' => $this->result,
        ];
    }
}
