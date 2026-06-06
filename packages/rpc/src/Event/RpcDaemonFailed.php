<?php

declare(strict_types=1);

namespace Switon\Rpc\Event;

use JsonSerializable;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;
use Throwable;

/**
 * Emitted when the RPC daemon loop catches one processing failure.
 *
 * Log category: rpc daemon lifecycle.
 *
 * @see \Switon\Rpc\Rpc::daemon()
 */
#[EventLevel(Severity::ERROR)]
class RpcDaemonFailed implements JsonSerializable
{
    /**
     * @param string $method RPC method being processed, when known
     * @param Throwable $error Failure that escaped one daemon loop iteration
     */
    public function __construct(
        public string    $method,
        public Throwable $error,
    ) {
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
