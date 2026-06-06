<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * Factory-style builder that creates fresh query instances.
 *
 * Use when you want state-safe query construction without cloning an injected
 * {@see QueryInterface} singleton-like service reference.
 *
 * Road-signs:
 * - from() returns a fresh QueryInterface
 * - table name and entity class are auto-detected by format
 *
 * @see \Switon\Query\QueryBuilder
 * @see \Switon\Query\QueryInterface
 */
interface QueryBuilderInterface
{
    /**
     * Creates a fresh query instance and initializes source via {@see QueryInterface::from()}.
     *
     * The source can be a table name (for example <code>users</code>) or an
     * entity class FQCN (for example <code>App\Entity\User</code>). Detection
     * is delegated to {@see QueryInterface::from()}.
     *
     * @return QueryInterface<mixed>
     */
    public function from(string $tableOrEntity, ?string $alias = null): QueryInterface;
}
