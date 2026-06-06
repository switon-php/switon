<?php

declare(strict_types=1);

namespace Switon\Query\Exception;

use Switon\Query\Exception as BaseException;

/**
 * Exception for entity-aware query operations without entity metadata.
 *
 * Raised when a query method requires an entity class but none is configured.
 *
 * @see \Switon\Query\Exception
 * @see \Switon\Query\AbstractQuery
 */
class EntityClassRequiredException extends BaseException
{
}
