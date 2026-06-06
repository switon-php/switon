<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Fixtures;

use Switon\Captcha\StoreInterface;

class MockStore implements StoreInterface
{
    protected array $storage = [];

    public function get(string $key): ?array
    {
        return $this->storage[$key] ?? null;
    }

    public function put(string $key, array $payload, int $ttl): void
    {
        $this->storage[$key] = $payload;
    }

    public function forget(string $key): void
    {
        if (isset($this->storage[$key])) {
            unset($this->storage[$key]);
        }
    }
}
