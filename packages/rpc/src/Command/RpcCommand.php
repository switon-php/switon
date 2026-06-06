<?php

declare(strict_types=1);

namespace Switon\Rpc\Command;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Rpc\RpcInterface;

/**
 * CLI entrypoint for the RPC daemon worker.
 *
 * Use when running the long-lived queue-backed RPC request loop from the console.
 *
 * @see \Switon\Rpc\RpcInterface::daemon()
 */
class RpcCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected RpcInterface $rpc;

    /**
     * Starts the RPC daemon loop.
     */
    public function daemonAction(): int
    {
        $this->rpc->daemon();

        return 0;
    }
}
