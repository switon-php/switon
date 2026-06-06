<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * Default implementation of <code>ShardingContextInterface</code>.
 *
 * Extracts shard-routing fields from normalized condition predicates.
 *
 * @see \Switon\Query\ShardingContextInterface
 * @see \Switon\Query\AbstractQuery
 */
class ShardingContext implements ShardingContextInterface
{
    /**
     * @param array<int, array<string, mixed>> $predicates
     *
     * @return array<string, mixed>
     * {@inheritDoc}
     */
    public function extract(array $predicates): array
    {
        $context = [];

        foreach ($predicates as $spec) {
            $field = $spec['field'] ?? null;
            if ($field === null) {
                continue;
            }

            $type = $spec['type'] ?? null;
            if ($type === 'eq') {
                if (isset($spec['value'])) {
                    $context[$field] = $spec['value'];
                }
            } elseif ($type === 'in') {
                if (isset($spec['values']) && is_array($spec['values'])) {
                    $context[$field] = $spec['values'];
                }
            }
        }

        return $context;
    }
}
