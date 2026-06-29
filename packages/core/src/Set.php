<?php

declare(strict_types=1);

namespace Switon\Core;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

use function array_keys;
use function count;

/**
 * Mutable membership set keyed by `array-key` values.
 *
 * Guidance: Prefer this over `array<T, true>` for long-lived fields and public APIs;
 * keep local `$seen[$k] = true` scratch maps as plain arrays.
 *
 * @template T of array-key
 *
 * @implements IteratorAggregate<int, T>
 *
 * @see \Switon\Core\ArrayableInterface
 */
class Set implements ArrayableInterface, Countable, IteratorAggregate
{
    /** @var array<T, true> */
    protected array $items = [];

    /**
     * @param iterable<T> $items
     */
    public function __construct(iterable $items = [])
    {
        foreach ($items as $item) {
            $this->items[$item] = true;
        }
    }

    /**
     * Build from members and/or arrays of members (arrays are expanded one level).
     *
     * @param T|array<T> ...$items
     *
     * @return static<T>
     */
    public static function of(string|int|array ...$items): static
    {
        $set = new static();
        foreach ($items as $item) {
            if (is_array($item)) {
                foreach ($item as $member) {
                    $set->add($member);
                }
            } else {
                $set->add($item);
            }
        }

        return $set;
    }

    /**
     * @param T $item
     */
    public function add(string|int $item): void
    {
        $this->items[$item] = true;
    }

    /**
     * @param T $item
     */
    public function remove(string|int $item): void
    {
        unset($this->items[$item]);
    }

    /**
     * @param T $item
     */
    public function has(string|int $item): bool
    {
        return isset($this->items[$item]);
    }

    /**
     * @return list<T>
     */
    public function all(): array
    {
        return array_keys($this->items);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    /**
     * @return list<T>
     */
    public function toArray(): array
    {
        return $this->all();
    }

    /**
     * @return Traversable<int, T>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }
}
