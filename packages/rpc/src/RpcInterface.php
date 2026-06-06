<?php

declare(strict_types=1);

namespace Switon\Rpc;

use Switon\Rpc\Exception\RpcProtocolException;
use Switon\Rpc\Exception\RpcRemoteException;
use Switon\Rpc\Exception\RpcTimeoutException;

/**
 * Queue-backed request-reply RPC contract.
 *
 * Use when one service should send one request message and wait for one reply message through `switon/queue`.
 *
 * Road-signs:
 * - request + wait: call
 * - worker loop: daemon
 * - queue transport: switon/queue
 * - fail timeout / remote / protocol
 *
 * @see \Switon\Queue\QueueInterface
 */
interface RpcInterface
{
    /**
     * Send one request to an RPC server and wait for its reply result.
     *
     * `method` is either `ClassName::method` or a logical name mapped in config (`methods` on the RPC binding).
     * Implementations should push the request to one configured RPC queue,
     * attach one process-level reply queue plus a monotonic `correlation_id`,
     * then wait until timeout for the matching reply.
     *
     * @param array<int|string, mixed> $params Positional parameters by default; named overrides are also allowed.
     * @param float $timeout Wait timeout in seconds, must be greater than 0.
     *
     * @throws RpcTimeoutException When the matching reply does not arrive before `timeout`.
     * @throws RpcRemoteException When the remote side replies with an RPC error payload.
     * @throws RpcProtocolException When the reply envelope does not satisfy the RPC reply protocol.
     */
    public function call(string $method, array $params = [], float $timeout = 3): mixed;

    /**
     * Receive and handle RPC requests in a long-running loop.
     *
     * Implementations should auto-reply on success. When one request envelope has already been received and
     * includes `reply_to` plus `correlation_id`, implementations should also try to send an error reply before
     * reporting the loop failure and continuing.
     *
     * @see \Switon\Rpc\Rpc::run() Default per-request execution path
     */
    public function daemon(): void;
}
