<?php

declare(strict_types=1);

namespace Switon\Rpc\Event;

use JsonSerializable;
use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Throwable;

/**
 * Emitted when the RPC daemon loop catches one processing failure.
 *
 * Log category: `switon.rpc.daemon.failed`
 *
 * @see \Switon\Rpc\Rpc::daemon()
 */
#[EventLevel(Severity::ERROR)]
class RpcDaemonFailed implements JsonSerializable, SenderAwareInterface
{
    /**
     * @param string $method RPC method being processed, when known
     * @param Throwable $error Failure that escaped one daemon loop iteration
     */
    public function __construct(
        public \Switon\Rpc\RpcInterface $sender,
        public string    $method,
        public Throwable $error,
    ) {
    }

    public function getSender(): \Switon\Rpc\RpcInterface
    {
        return $this->sender;
    }

    /**
     * Returns a diagnostic payload for the daemon failure.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'method' => $this->method,
            'error' => [
                'class' => $this->error::class,
                'message' => $this->error->getMessage(),
                'code' => $this->error->getCode(),
                'file' => $this->error->getFile(),
                'line' => $this->error->getLine(),
            ],
        ];
    }
}
