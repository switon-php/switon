<?php

declare(strict_types=1);

namespace Switon\Schedule\Tests\Unit;

use Switon\Redis\ClientInterface;
use Switon\Schedule\Lock;
use PHPUnit\Framework\TestCase;
use LogicException;

class LockTest extends TestCase
{
    public function testReleaseDeletesWhenTokenMatches(): void
    {
        $redis = new class () implements ClientInterface {
            /** @var array<string, string> */
            public array $store = [];
            public bool $deleted = false;

            public function getUri(): ?string
            {
                return null;
            }

            public function getTransient(): static
            {
                return $this;
            }

            public function __call(string $method, array $arguments): mixed
            {
                if ($method === 'set') {
                    [$key, $value] = $arguments;
                    if (isset($this->store[$key])) {
                        return false;
                    }
                    $this->store[$key] = $value;

                    return true;
                }

                if ($method === 'watch' || $method === 'multi' || $method === 'unwatch') {
                    return true;
                }

                if ($method === 'get') {
                    [$key] = $arguments;

                    return $this->store[$key] ?? false;
                }

                if ($method === 'del') {
                    [$key] = $arguments;
                    unset($this->store[$key]);
                    $this->deleted = true;

                    return 1;
                }

                if ($method === 'exec') {
                    return [1];
                }

                throw new LogicException('Unexpected redis method: ' . $method);
            }
        };

        $lock = new TestableLock();
        $this->setRedisLock($lock, $redis);

        $this->assertTrue($lock->tryAcquire('schedule:test:task', 30));
        $this->assertNotEmpty($redis->store['schedule:test:task']);

        $lock->release('schedule:test:task');

        $this->assertTrue($redis->deleted);
        $this->assertArrayNotHasKey('schedule:test:task', $redis->store);
    }

    public function testReleaseSkipsDeleteWhenTokenDoesNotMatch(): void
    {
        $redis = new class () implements ClientInterface {
            /** @var array<string, string> */
            public array $store = [];
            public bool $deleted = false;

            public function getUri(): ?string
            {
                return null;
            }

            public function getTransient(): static
            {
                return $this;
            }

            public function __call(string $method, array $arguments): mixed
            {
                if ($method === 'set') {
                    [$key, $value] = $arguments;
                    if (isset($this->store[$key])) {
                        return false;
                    }
                    $this->store[$key] = $value;

                    return true;
                }

                if ($method === 'watch' || $method === 'multi' || $method === 'unwatch') {
                    return true;
                }

                if ($method === 'get') {
                    [$key] = $arguments;

                    return $this->store[$key] ?? false;
                }

                if ($method === 'del') {
                    [$key] = $arguments;
                    unset($this->store[$key]);
                    $this->deleted = true;

                    return 1;
                }

                if ($method === 'exec') {
                    return [1];
                }

                throw new LogicException('Unexpected redis method: ' . $method);
            }
        };

        $lock = new TestableLock();
        $this->setRedisLock($lock, $redis);

        $this->assertTrue($lock->tryAcquire('schedule:test:task', 30));
        $redis->store['schedule:test:task'] = 'new-owner-token';

        $lock->release('schedule:test:task');

        $this->assertFalse($redis->deleted);
        $this->assertSame('new-owner-token', $redis->store['schedule:test:task']);
    }

    public function testReleaseIsNoOpWhenLockWasNeverAcquired(): void
    {
        $redis = $this->createStub(ClientInterface::class);
        $lock = new TestableLock();
        $this->setRedisLock($lock, $redis);

        $lock->release('schedule:test:never-held');

        $this->addToAssertionCount(1);
    }

    public function testReleaseClearsOwnedTokenWhenExecNeverSucceeds(): void
    {
        $redis = new class () implements ClientInterface {
            /** @var array<string, string> */
            public array $store = [];

            public function getUri(): ?string
            {
                return null;
            }

            public function getTransient(): static
            {
                return $this;
            }

            public function __call(string $method, array $arguments): mixed
            {
                if ($method === 'set') {
                    [$key, $value] = $arguments;
                    $this->store[$key] = $value;

                    return true;
                }

                if ($method === 'watch' || $method === 'multi' || $method === 'unwatch') {
                    return true;
                }

                if ($method === 'get') {
                    [$key] = $arguments;

                    return $this->store[$key] ?? false;
                }

                if ($method === 'del') {
                    return 1;
                }

                if ($method === 'exec') {
                    return false;
                }

                throw new LogicException('Unexpected redis method: ' . $method);
            }
        };

        $lock = new TestableLock();
        $this->setRedisLock($lock, $redis);

        $this->assertTrue($lock->tryAcquire('schedule:test:contested', 30));
        $lock->release('schedule:test:contested');

        $this->assertSame([], $lock->ownedTokens());
        $this->assertArrayHasKey('schedule:test:contested', $redis->store);
    }

    public function testTryAcquireReturnsFalseWhenKeyAlreadyHeld(): void
    {
        $redis = new class () implements ClientInterface {
            public function getUri(): ?string
            {
                return null;
            }

            public function getTransient(): static
            {
                return $this;
            }

            public function __call(string $method, array $arguments): mixed
            {
                if ($method === 'set') {
                    return false;
                }

                throw new LogicException('Unexpected redis method: ' . $method);
            }
        };

        $lock = new TestableLock();
        $this->setRedisLock($lock, $redis);

        $this->assertFalse($lock->tryAcquire('schedule:test:busy', 30));
    }

    protected function setRedisLock(TestableLock $lock, ClientInterface $redis): void
    {
        $lock->setRedisLock($redis);
    }
}

class TestableLock extends Lock
{
    public function setRedisLock(ClientInterface $redis): void
    {
        $this->redisLock = $redis;
    }

    /**
     * @return array<string, string>
     */
    public function ownedTokens(): array
    {
        return $this->ownedTokens;
    }
}
