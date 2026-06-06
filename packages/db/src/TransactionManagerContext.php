<?php

declare(strict_types=1);

namespace Switon\Db;

use Switon\Core\ContextIsolated;

/**
 * Request/coroutine-local state for TransactionManager transaction scopes.
 *
 * @see \Switon\Db\TransactionManager
 * @see \Switon\Core\ContextIsolated
 */
class TransactionManagerContext implements ContextIsolated
{
    /** True after begin() until the scope is committed/rolled back. */
    public bool $useTransaction = false;

    /** @var array<string, bool> Active connection names in current transaction scope. */
    public array $connections = [];

    /** @var array<string, ClientInterface> Transient clients pinned to active connections. */
    public array $clients = [];

    /** @var array<string, true> Sharded connection names seen in this scope. */
    public array $shardedConnections = [];

    /** True once any sharded connection name has been bound in this scope. */
    public bool $hasShardedTransaction = false;
}
