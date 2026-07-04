<?php

declare(strict_types=1);

namespace Switon\Rpc\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Emitted when the RPC daemon receives one request from queue.
 *
 * Log category: `switon.rpc.received`
 *
 * @see \Switon\Rpc\Rpc::daemon()
 */
#[EventLevel(Severity::DEBUG)]
class RpcReceived implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param array<int|string, mixed> $params
     */
    public function __construct(
        public \Switon\Rpc\RpcInterface $sender,
        public string $method,
        public array  $params,
        public string $correlationId,
        public string $replyTo,
    ) {
    }

    public function getSender(): \Switon\Rpc\RpcInterface
    {
        return $this->sender;
    }

    /**
     * Returns the received RPC request payload.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'method' => $this->method,
            'params' => $this->params,
            'correlationId' => $this->correlationId,
            'replyTo' => $this->replyTo,
        ];
    }
}
