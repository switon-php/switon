<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Fixtures;

use Switon\Redis\ClientInterface;

class MockRedis implements ClientInterface
{
    protected array $storage = [];

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $this->storage[$key] = $value;
        return true;
    }

    public function get(string $key): mixed
    {
        return $this->storage[$key] ?? false;
    }

    public function del(array|string $keys): int
    {
        if (is_string($keys)) {
            $keys = [$keys];
        }
        $count = 0;
        foreach ($keys as $key) {
            if (isset($this->storage[$key])) {
                unset($this->storage[$key]);
                $count++;
            }
        }
        return $count;
    }

    // Methods below are not used in Captcha tests but required by interface
    public function exists(array|string $keys): int|bool
    {
        return false;
    }

    public function expire(string $key, int $seconds): bool
    {
        return true;
    }

    public function ttl(string $key): int
    {
        return 0;
    }

    public function incr(string $key): int
    {
        return 0;
    }

    public function decr(string $key): int
    {
        return 0;
    }

    public function hSet(string $key, string $field, string $value): bool|int
    {
        return true;
    }

    public function hGet(string $key, string $field): string|false
    {
        return false;
    }

    public function hDel(string $key, string $field): bool|int
    {
        return true;
    }

    public function lPush(string $key, array|string $value): int|bool
    {
        return 1;
    }

    public function rPush(string $key, array|string $value): int|bool
    {
        return 1;
    }

    public function lPop(string $key): string|bool
    {
        return false;
    }

    public function rPop(string $key): string|bool
    {
        return false;
    }

    public function sAdd(string $key, array|string $value): int
    {
        return 1;
    }

    public function sRem(string $key, array|string $value): int
    {
        return 1;
    }

    public function sMembers(string $key): array
    {
        return [];
    }

    public function sIsMember(string $key, string $value): bool
    {
        return false;
    }

    public function flushAll(): bool
    {
        $this->storage = [];
        return true;
    }

    public function keys(string $pattern): array
    {
        return [];
    }

    public function scan(?int &$iterator, string $pattern = '*', int $count = 10): array|bool
    {
        return [];
    }

    public function pipeline(): mixed
    {
        return null;
    }

    public function transaction(callable $callback): mixed
    {
        return null;
    }

    public function getTransient(): mixed
    {
        return null;
    }


    public function close(): void
    {
    }
}
