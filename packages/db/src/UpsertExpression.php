<?php

declare(strict_types=1);

namespace Switon\Db;

/**
 * Internal value object for explicit raw UPSERT assignment expressions.
 *
 * @internal
 */
final readonly class UpsertExpression
{
    public function __construct(public string $sql)
    {
    }
}
