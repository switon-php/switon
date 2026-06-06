<?php

declare(strict_types=1);

namespace Switon\Query\Exception;

use JetBrains\PhpStorm\ArrayShape;
use Switon\Core\NotFoundInterface;
use Switon\Query\Exception as BaseException;

/**
 * Exception for missing query results.
 *
 * Used by <code>get()</code> when no matching record exists.
 * Returns HTTP status code <code>404</code>.
 *
 * Road-signs:
 * - resource-by-id get() miss
 * - list/search → 200 + []
 * - maps to 404 json
 *
 * @see \Switon\Core\NotFoundInterface
 * @see \Switon\Query\Exception
 * @see \Switon\Query\Query
 * @see \Switon\Query\AbstractQuery
 */
class NotFoundException extends BaseException implements NotFoundInterface
{
    /**
     * {@inheritDoc}
     */
    public function getStatusCode(): int
    {
        return 404;
    }

    /**
     * {@inheritDoc}
     */
    #[ArrayShape(['code' => 'int', 'msg' => 'string'])]
    public function getJson(): array
    {
        return ['code' => 404, 'msg' => 'record not found'];
    }
}
