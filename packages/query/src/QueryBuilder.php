<?php

declare(strict_types=1);

namespace Switon\Query;

use Switon\Core\Attribute\Autowired;
use Switon\Core\MakerInterface;

/**
 * Default implementation of {@see QueryBuilderInterface}.
 *
 * This builder wraps {@see MakerInterface::make()} to avoid state carry-over
 * when multiple logical queries are built in one request flow.
 *
 * @see \Switon\Query\QueryBuilderInterface
 * @see \Switon\Core\MakerInterface
 */
class QueryBuilder implements QueryBuilderInterface
{
    #[Autowired] protected MakerInterface $maker;

    /**
     * @return QueryInterface<mixed>
     * {@inheritDoc}
     */
    public function from(string $tableOrEntity, ?string $alias = null): QueryInterface
    {
        return $this->maker->make(QueryInterface::class)->from($tableOrEntity, $alias);
    }
}
