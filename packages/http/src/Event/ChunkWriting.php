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
 * Event emitted before a response chunk is written.
 *
 * Road-signs:
 * - emitted before one streaming chunk is sent
 * - listeners may replace the chunk text
 * - ChunkWritten follows after emission
 *
 * Log category: <code>switon.http.chunk.writing</code>
 *
 * @see \Switon\Http\AbstractServer::write()
 * @see \Switon\Http\Server\Adapter\Swoole::write()
 * @see \Switon\Http\ServerInterface
 * @see \Switon\Http\Event\ChunkWritten
 */
#[EventLevel(Severity::DEBUG)]
class ChunkWriting implements JsonSerializable, SenderAwareInterface
{
    public function __construct(
        public ResponseSenderInterface $sender,
        public ResponseInterface $response,
        public string $chunk
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
            'chunk_length' => strlen($this->chunk),
        ];
    }
}
