<?php

declare(strict_types=1);

namespace Switon\Rpc\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Emitted when one RPC request is about to be sent.
 *
 * Log category: `switon.rpc.calling`
 *
 * @see \Switon\Rpc\Rpc::call()
 */
#[EventLevel(Severity::DEBUG)]
class RpcCalling implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param array<int|string, mixed> $params
     */
    public function __construct(
        public \Switon\Rpc\RpcInterface $sender,
        public string $method,
        public array  $params,
        public string $correlationId,
        public float  $timeout,
    ) {
    }

    public function getSender(): \Switon\Rpc\RpcInterface
    {
        return $this->sender;
    }

    /**
     * Returns the outgoing RPC request payload.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'method' => $this->method,
            'params' => $this->params,
            'correlationId' => $this->correlationId,
            'timeout' => $this->timeout,
        ];
    }
}
