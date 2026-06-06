<?php

declare(strict_types=1);

namespace Switon\Db\Tests\Fixtures;

class MockPoolManager implements \Switon\Pooling\PoolManagerInterface
{
    public array $pools = [];
    public array $added = [];
    public array $popped = [];
    public array $pushed = [];
    public array $created = [];

    public function remove(object $owner, ?string $type = null): void
    {
    }

    public function create(object $owner, int $capacity, string $type = 'default'): void
    {
        $ownerId = spl_object_id($owner);
        $this->created[$ownerId][$type] = ['capacity' => $capacity];
    }

    public function add(object $owner, object|array $sample, int $size = 1, string $type = 'default'): void
    {
        $ownerId = spl_object_id($owner);
        if (!isset($this->pools[$ownerId][$type])) {
            $this->pools[$ownerId][$type] = [];
        }

        for ($i = 0; $i < $size; $i++) {
            $this->pools[$ownerId][$type][] = $sample;
        }

        $this->added[$ownerId][$type][] = ['sample' => $sample, 'size' => $size];
    }

    public function push(object $owner, object $instance, string $type = 'default'): void
    {
        $ownerId = spl_object_id($owner);
        $this->pools[$ownerId][$type][] = $instance;
        $this->pushed[$ownerId][$type][] = $instance;
    }

    public function pop(object $owner, ?float $timeout = null, string $type = 'default'): object
    {
        $ownerId = spl_object_id($owner);
        if (empty($this->pools[$ownerId][$type])) {
            $connection = new MockConnection();
            $this->popped[$ownerId][$type][] = $connection;
            return $connection;
        }

        $instance = array_shift($this->pools[$ownerId][$type]);
        $this->popped[$ownerId][$type][] = $instance;
        return $instance;
    }

    public function acquire(object $owner, ?float $timeout = null, string $type = 'default'): object
    {
        return $this->pop($owner, $timeout, $type);
    }

    public function release(object $owner, object $instance, string $type = 'default', ?float $elapsed = null): void
    {
        $this->push($owner, $instance, $type);
    }

    public function guard(object $owner, ?float $timeout = null, string $type = 'default'): \Switon\Pooling\PoolGuard
    {
        return new \Switon\Pooling\PoolGuard($this, $owner, $this->acquire($owner, $timeout, $type), $type);
    }

    public function exists(object $owner, string $type = 'default'): bool
    {
        $ownerId = spl_object_id($owner);
        return isset($this->pools[$ownerId][$type]) && !empty($this->pools[$ownerId][$type]);
    }

    public function size(object $owner, string $type = 'default'): int
    {
        $ownerId = spl_object_id($owner);
        return count($this->pools[$ownerId][$type] ?? []);
    }

    public function isEmpty(object $owner, string $type = 'default'): bool
    {
        $ownerId = spl_object_id($owner);
        return empty($this->pools[$ownerId][$type]);
    }
}
