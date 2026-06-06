<?php

declare(strict_types=1);

namespace Switon\Query;

/**
 * Contract for query pagination payloads.
 *
 * Use when returning page metadata and page items in a stable structure.
 *
 * Road-signs:
 * - setItems() for current page rows
 * - paginate() for count/page/size math
 * - toArray() for transport output
 *
 * @see \Switon\Query\Paginator
 *
 * @property array<int, mixed> $items
 */
interface PaginatorInterface
{
    /**
     * Sets the items for the current page.
     *
     * @param array<int|string, mixed> $items Current page items
     *
     * @return static
     */
    public function setItems(array $items): static;

    /**
     * Configures pagination metadata from total count, current page, and page size.
     *
     * @param int $count Total number of records
     * @param int $page Current page number (1-based)
     * @param int $size Number of items per page
     *
     * @return static
     */
    public function paginate(int $count, int $page, int $size): static;

    /**
     * Converts pagination data to array format.
     *
     * @return array<string, mixed> Pagination data including page, size, count, pages, and items
     */
    public function toArray(): array;
}
