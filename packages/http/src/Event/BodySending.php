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
 * Event emitted before a response body is sent.
 *
 * Road-signs:
 * - emitted for non-streaming body output
 * - listeners may inspect or replace the body text
 * - BodySent follows after emission
 *
 * Log category: <code>switon.http.body.sending</code>
 *
 * @see \Switon\Http\ServerInterface
 * @see \Switon\Http\Server\Adapter\Native\Sender::sendBody()
 * @see \Switon\Http\Server\Adapter\Swoole::sendBody()
 * @see \Switon\Http\Event\BodySent
 */
#[EventLevel(Severity::DEBUG)]
class BodySending implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public ResponseSenderInterface $sender,
        public ResponseInterface $response,
        public string $content
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
        ];
    }
}
