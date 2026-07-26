<?php

declare(strict_types=1);

namespace Switon\Mail\Tests\Fixtures;

use Switon\Pooling\PoolGuard;
use Switon\Pooling\PoolManagerInterface;
use stdClass;

/**
 * @internal
 */
class SpyPoolManager implements PoolManagerInterface
{
    public ?object $defaultResource = null;

    /** @var array<string, object> */
    public array $resources = [];

    /** @var list<array{owner: object, type: string, sample: object|array, size: int}> */
    public array $addCalls = [];

    /** @var list<array{owner: object, type: string, timeout: ?float}> */
    public array $acquireCalls = [];

    /** @var list<array{owner: object, type: string, instance: object, elapsed: ?float}> */
    public array $releaseCalls = [];

    public function remove(object $owner, ?string $type = null): void
    {
        if ($type === null) {
            $this->resources = [];
            return;
        }

        unset($this->resources[$this->key($owner, $type)]);
    }

    public function create(object $owner, int $capacity, string $type = 'default'): void
    {
    }

    public function add(object $owner, object|array $sample, int $size = 1, string $type = 'default'): void
    {
        $this->addCalls[] = ['owner' => $owner, 'type' => $type, 'sample' => $sample, 'size' => $size];

        $key = $this->key($owner, $type);
        if (!isset($this->resources[$key])) {
            if ($this->defaultResource !== null) {
                $this->resources[$key] = $this->defaultResource;
            } elseif ($sample instanceof stdClass) {
                $this->resources[$key] = $sample;
            } elseif (is_array($sample) && isset($sample[0]) && $sample[0] === \Switon\Mail\ConnectionInterface::class) {
                $this->resources[$key] = new \Switon\Mail\Connection();
            } elseif ($sample instanceof object) {
                $this->resources[$key] = $sample;
            } else {
                $this->resources[$key] = new SpyConnection();
            }
        }
    }

    public function release(object $owner, object $instance, string $type = 'default', ?float $elapsed = null): void
    {
        $this->releaseCalls[] = ['owner' => $owner, 'type' => $type, 'instance' => $instance, 'elapsed' => $elapsed];
    }

    public function acquire(object $owner, ?float $timeout = null, string $type = 'default'): object
    {
        $this->acquireCalls[] = ['owner' => $owner, 'type' => $type, 'timeout' => $timeout];

        return $this->resources[$this->key($owner, $type)] ??= new SpyConnection();
    }

    public function guard(object $owner, ?float $timeout = null, string $type = 'default'): PoolGuard
    {
        return new PoolGuard($this, $owner, $this->acquire($owner, $timeout, $type), $type);
    }

    public function isEmpty(object $owner, string $type = 'default'): bool
    {
        return !isset($this->resources[$this->key($owner, $type)]);
    }

    public function exists(object $owner, string $type = 'default'): bool
    {
        return isset($this->resources[$this->key($owner, $type)]);
    }

    public function size(object $owner, string $type = 'default'): int
    {
        return isset($this->resources[$this->key($owner, $type)]) ? 1 : 0;
    }

    protected function key(object $owner, string $type): string
    {
        return spl_object_id($owner) . ':' . $type;
    }
}
