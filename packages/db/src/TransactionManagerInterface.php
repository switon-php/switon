<?php

declare(strict_types=1);

namespace Switon\Db;

use Switon\Db\Exception\CrossDatabaseTransactionException;
use Switon\Db\Exception\NoActiveTransactionException;
use Throwable;

/**
 * Coordinates ORM transaction scopes across named DB clients.
 *
 * One logical scope may open multiple local transactions (not distributed/2PC).
 * A scope may delay binding, but once a logical connection name is chosen, later operations must stay on it.
 * For sharded names (for example <code>user:1</code>), one scope can bind only one distinct shard connection.
 *
 * Guidance: Use this at ORM transaction boundaries; call <code>getCurrentClient()</code> only when raw SQL must join the already-bound ORM scope.
 *
 * Road-signs:
 * - Transactional attribute and EntityManager are the usual entrypoints
 * - begin() starts a scope
 * - useTransaction() gates ORM routing
 * - ensureConnection() binds the first logical connection
 * - getCurrentClient() returns the bound client for raw SQL
 * - commitAll/rollbackAll close all bound clients
 * - fail CrossDatabaseTransactionException
 *
 * @see \Switon\Db\TransactionManager
 * @see \Switon\Db\TransactionManagerInterface::transaction()
 * @see \Switon\Db\TransactionManagerInterface::ensureConnection()
 * @see \Switon\Db\ClientInterface
 * @see \Switon\Orm\EntityManagerInterface
 * @see \Switon\Db\Exception\NoActiveTransactionException
 * @see \Switon\Orm\Attribute\Transactional
 * @see \Switon\Db\TransactionManagerContext
 */
interface TransactionManagerInterface
{
    /**
     * Ensure an operation uses the specified connection within the current transaction scope.
     *
     * If no transaction requested, does nothing.
     * If a transaction is active, this binds the logical connection on first use
     * and rejects any later request for a different logical connection.
     *
     * @throws CrossDatabaseTransactionException If the requested connection does not match the bound logical connection
     */
    public function ensureConnection(string $connection): void;

    /**
     * Get the transaction-pinned database client for raw SQL or other non-ORM work.
     *
     * The caller must pass the logical connection name that the current scope has already
     * bound. This returns the transient client created for that scope, not a fresh client.
     *
     * @param string $connection Bound logical connection name
     *
     * @throws NoActiveTransactionException If no transaction is active or the connection has not been bound yet
     */
    public function getCurrentClient(string $connection): ClientInterface;

    /**
     * Commit transaction for specified connection.
     *
     * @param string $connection Connection name to commit
     *
     * @throws NoActiveTransactionException If no active transaction for this connection
     */
    public function commit(string $connection): void;

    /**
     * Rollback transaction for specified connection.
     *
     * Unlike {@see ClientInterface::rollback()}, this method throws when no transaction is active.
     */
    public function rollback(string $connection): void;

    /**
     * Commit all active transactions in the current context.
     *
     * @throws NoActiveTransactionException If no transaction is active
     */
    public function commitAll(): void;

    /**
     * Roll back all active transactions in the current context.
     *
     * @throws NoActiveTransactionException If no transaction is active
     */
    public function rollbackAll(): void;

    /**
     * Execute callback within transaction.
     *
     * @param callable $callback Callback to execute
     * @param string|null $connection Connection name (null = determined by first ORM operation)
     *
     * @return mixed Return value from callback
     *
     * @throws Throwable Any exception from callback
     */
    public function transaction(callable $callback, ?string $connection = null): mixed;

    /**
     * Begin transaction (for interceptor usage).
     *
     * @param string|null $connection Connection name (null = determined by first ORM operation)
     *
     * @throws CrossDatabaseTransactionException If transaction already started
     */
    public function begin(?string $connection = null): void;

    /**
     * Decide whether the current operation should use the transaction client for a specific connection.
     *
     * Returns true when the scope is active and the connection is either still unbound
     * or already bound to this logical connection. Returns false only when no transaction
     * scope is active.
     *
     * @throws CrossDatabaseTransactionException When a different logical connection is requested in the same scope
     */
    public function useTransaction(string $connection): bool;

    /**
     * Check if currently in transaction for a specific connection.
     *
     * @return bool True when the named connection is active in the current scope
     */
    public function isInTransaction(string $connection): bool;

    /**
     * Get active connection names for current context.
     *
     * @return string[]
     */
    public function getActiveConnectionNames(): array;
}
