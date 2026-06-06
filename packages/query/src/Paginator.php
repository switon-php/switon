<?php

declare(strict_types=1);

namespace Switon\Query;

use JetBrains\PhpStorm\ArrayShape;
use JsonSerializable;
use Switon\Core\ArrayableInterface;
use Switon\Core\Json;

use function ceil;

/**
 * Default implementation of <code>PaginatorInterface</code>.
 *
 * Use when serializing query results with page, size, count, pages, and items.
 *
 * Road-signs:
 * - page/size/count/pages
 * - prev/next -1 none
 * - items current page
 * - toArray()/jsonSerialize()/__toString()
 *
 * @see \Switon\Query\PaginatorInterface
 * @see \Switon\Query\QueryInterface
 */
class Paginator implements PaginatorInterface, ArrayableInterface, JsonSerializable
{
    public int $count;

    public int $size;

    public int $page;

    public int $pages;

    /** @var int Previous page number (-1 if no previous page). */
    public int $prev;

    /** @var int Next page number (-1 if no next page). */
    public int $next;

    /** @var array<int, mixed> Current page items. */
    public array $items;

    /**
     * Creates a new Paginator instance.
     *
     * @param array<int|string, mixed> $items Current page items
     * @param int $page Current page number (1-based)
     * @param int $size Number of items per page
     * @param int $count Total number of records
     */
    public function __construct(array $items = [], int $page = 1, int $size = 10, int $count = 0)
    {
        $this->items = $items;
        $this->paginate($count, $page, $size);
    }

    /**
     * {@inheritDoc}
     */
    public function setItems(array $items): static
    {
        $this->items = $items;
        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function paginate(int $count, int $page, int $size): static
    {
        $this->count = $count;
        $this->page = $page;
        $this->size = $size;
        $this->pages = (int)ceil($this->count / $this->size);
        $this->prev = ($this->page <= $this->pages && $this->page > 1) ? $this->page - 1 : -1;
        $this->next = $this->page < $this->pages ? $this->page + 1 : -1;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    #[ArrayShape(['page' => 'int', 'size' => 'int', 'count' => 'int', 'pages' => 'int', 'items' => 'array'])]
    public function toArray(): array
    {
        return [
            'page' => $this->page,
            'size' => $this->size,
            'count' => $this->count,
            'pages' => $this->pages,
            'items' => $this->items
        ];
    }

    /**
     * Converts paginator to JSON string.
     *
     * @return string JSON representation of pagination data
     */
    public function __toString(): string
    {
        return Json::stringify($this->toArray());
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
