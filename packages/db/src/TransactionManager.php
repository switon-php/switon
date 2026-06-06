<?php

declare(strict_types=1);

namespace Switon\Db;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContextAware;
use Switon\Core\ContextManagerInterface;
use Switon\Db\Exception\CrossDatabaseTransactionException;
use Switon\Db\Exception\NoActiveTransactionException;
use Switon\Di\NamedLookupInterface;

/**
 * Coordinates ORM transaction scopes with request/coroutine-local state.
 *
 * Stores request/coroutine state in <code>TransactionManagerContext</code>.
 *
 * Guidance: Let ORM transaction entrypoints bind the logical connection first; use <code>getCurrentClient()</code> only when raw SQL must participate in that same scope.
 *
 * Road-signs:
 * - Transactional attribute and EntityManager are the usual callers
 * - begin() flips <code>useTransaction</code>
 * - useTransaction() decides ORM routing
 * - ensureConnection() binds the logical connection
 * - getCurrentClient() serves raw SQL on the bound client
 * - commitAll/rollbackAll reset context
 * - fail NoActiveTransaction/CrossDatabaseTransaction
 *
 * Guidance: Use getCurrentClient() only after the scope has already bound a logical connection.
 *
 * @see \Switon\Db\TransactionManagerInterface
 * @see \Switon\Db\TransactionManagerInterface::ensureConnection()
 * @see \Switon\Db\TransactionManagerInterface::transaction()
 * @see \Switon\Db\ClientInterface::getTransient()
 * @see \Switon\Db\ClientInterface::begin()
 * @see \Switon\Orm\EntityManagerInterface
 * @see \Switon\Db\TransactionManagerContext
 * @see \Switon\Orm\Attribute\Transactional
 * @see \Switon\Db\Exception\NoActiveTransactionException
 * @see \Switon\Db\Exception\CrossDatabaseTransactionException
 */
class TransactionManager implements TransactionManagerInterface, ContextAware
{
    /** @var NamedLookupInterface<ClientInterface> */
    #[Autowired] protected NamedLookupInterface $namedLookup;
    #[Autowired] protected ContextManagerInterface $contextManager;

    public function getContext(): TransactionManagerContext
    {
        return $this->contextManager->getContext($this);
    }

    /**
     * {@inheritDoc}
     */
    public function ensureConnection(string $connection): void
    {
        $context = $this->getContext();

        if (!$context->useTransaction) {
            return;
        }

        $this->assertConnectionAllowed($connection);

        if ($this->isShardedConnectionName($connection)) {
            if ($context->hasShardedTransaction && !isset($context->shardedConnections[$connection])) {
                CrossDatabaseTransactionException::raise('Cannot use connection "{connection}" in sharded transaction: different shard connections are not allowed within a single transaction', ['connection' => $connection]);
            }
            $context->hasShardedTransaction = true;
            $context->shardedConnections[$connection] = true;
        }

        if (isset($context->clients[$connection])) {
            return;
        }

        $baseClient = $this->namedLookup->by(ClientInterface::class, $connection);
        $client = $baseClient->getTransient();
        $client->begin();

        $context->clients[$connection] = $client;
        $context->connections[$connection] = true;
    }

    /**
     * {@inheritDoc}
     */
    public function getCurrentClient(string $connection): ClientInterface
    {
        $context = $this->getContext();

        if (!$context->useTransaction) {
            NoActiveTransactionException::raise('No active transaction for connection "{connection}", call begin() first', ['connection' => $connection]);
        }
        if (!isset($context->clients[$connection])) {
            NoActiveTransactionException::raise(
                'No connection established for this transaction. Perform a database operation first (e.g., query, insert) to determine the connection.'
            );
        }

        return $context->clients[$connection];
    }

    /**
     * {@inheritDoc}
     */
    public function commit(string $connection): void
    {
        $context = $this->getContext();

        if (!$context->useTransaction) {
            NoActiveTransactionException::raise('No active transaction to commit on connection "{connection}", call begin() first', ['connection' => $connection]);
        }
        if (!isset($context->clients[$connection])) {
            NoActiveTransactionException::raise('Cannot commit connection "{connection}": no database operations have been performed in this transaction', ['connection' => $connection]);
        }

        $context->clients[$connection]->commit();

        unset($context->clients[$connection]);
        unset($context->connections[$connection]);
        unset($context->shardedConnections[$connection]);
        if (empty($context->shardedConnections)) {
            $context->hasShardedTransaction = false;
        }
        if (empty($context->connections)) {
            $context->useTransaction = false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function rollback(string $connection): void
    {
        $context = $this->getContext();

        if (!$context->useTransaction) {
            NoActiveTransactionException::raise('No active transaction to rollback on connection "{connection}", call begin() first', ['connection' => $connection]);
        }
        if (!isset($context->clients[$connection])) {
            NoActiveTransactionException::raise('Cannot rollback connection "{connection}": no database operations have been performed in this transaction', ['connection' => $connection]);
        }

        $context->clients[$connection]->rollback();

        unset($context->clients[$connection]);
        unset($context->connections[$connection]);
        unset($context->shardedConnections[$connection]);
        if (empty($context->shardedConnections)) {
            $context->hasShardedTransaction = false;
        }
        if (empty($context->connections)) {
            $context->useTransaction = false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function commitAll(): void
    {
        $context = $this->getContext();

        if (!$context->useTransaction) {
            NoActiveTransactionException::raise('No active transaction to commit, call begin() first');
        }

        foreach ($context->clients as $client) {
            $client->commit();
        }

        $this->resetContext($context);
    }

    /**
     * {@inheritDoc}
     */
    public function rollbackAll(): void
    {
        $context = $this->getContext();

        if (!$context->useTransaction) {
            NoActiveTransactionException::raise('No active transaction to rollback, call begin() first');
        }

        foreach ($context->clients as $client) {
            $client->rollback();
        }

        $this->resetContext($context);
    }

    /**
     * {@inheritDoc}
     */
    public function transaction(callable $callback, ?string $connection = null): mixed
    {
        $context = $this->getContext();

        $this->begin($connection);

        $success = false;
        try {
            $result = $callback();
            $success = true;
            return $result;
        } finally {
            if (!empty($context->connections)) {
                $success ? $this->commitAll() : $this->rollbackAll();
            } else {
                $this->resetContext($context);
            }
        }
    }

    /**
     * {@inheritDoc}
     */
    public function begin(?string $connection = null): void
    {
        $context = $this->getContext();

        if ($context->useTransaction) {
            CrossDatabaseTransactionException::raise(
                'Transaction already active: nested transactions are not supported at ORM level. Use database client directly for savepoints, or call commitAll()/rollbackAll() first.'
            );
        }

        $context->useTransaction = true;

        if ($connection !== null) {
            $this->ensureConnection($connection);
        }
    }

    /**
     * Decide whether the current operation should use the transaction client for a connection.
     *
     * This is the ORM-facing gate: true means the current write/read path should use
     * the transaction client, false means no transaction scope is active.
     *
     * {@inheritDoc}
     */
    public function useTransaction(string $connection): bool
    {
        $context = $this->getContext();
        if (!$context->useTransaction) {
            return false;
        }
        if (empty($context->connections)) {
            return true;
        }

        $this->assertConnectionAllowed($connection);
        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function isInTransaction(string $connection): bool
    {
        return $this->getContext()->connections[$connection] ?? false;
    }

    /**
     * {@inheritDoc}
     */
    public function getActiveConnectionNames(): array
    {
        return array_keys($this->getContext()->connections);
    }

    /** Returns true when connection name includes shard suffix separators (: or ,). */
    protected function isShardedConnectionName(string $connection): bool
    {
        return strcspn($connection, ':,') !== strlen($connection);
    }

    /**
     * Ensures the scope stays on one logical connection name.
     *
     * @throws CrossDatabaseTransactionException When a different logical connection is requested
     */
    protected function assertConnectionAllowed(string $connection): void
    {
        $context = $this->getContext();

        if (empty($context->connections) || isset($context->connections[$connection])) {
            return;
        }

        $boundConnection = array_key_first($context->connections);
        CrossDatabaseTransactionException::raise(
            'Cannot use connection "{connection}" in transaction scope bound to "{boundConnection}"',
            [
                'connection' => $connection,
                'boundConnection' => $boundConnection ?? '',
            ]
        );
    }

    /** Clears all transaction flags and bound clients in current context. */
    protected function resetContext(TransactionManagerContext $context): void
    {
        $context->useTransaction = false;
        $context->connections = [];
        $context->clients = [];
        $context->shardedConnections = [];
        $context->hasShardedTransaction = false;
    }
}
