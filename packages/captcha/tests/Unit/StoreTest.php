<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Unit;

use Switon\Captcha\Store;
use Switon\Captcha\Tests\TestCase as CaptchaTestCase;
use Switon\Core\AppInterface;
use Switon\Core\Exception\JsonException;
use Switon\Redis\ClientInterface;

class StoreTest extends CaptchaTestCase
{
    protected const string DEFAULT_PREFIX = 'cache:test-app:captcha:';

    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->createStub(AppInterface::class);
        $app->method('id')->willReturn('test-app');
        $this->container->set(AppInterface::class, $app);
    }

    public function testGetReturnsNullWhenRedisValueMissing(): void
    {
        $redis = new FakeRedisClient();
        $store = $this->createStore($redis);

        $result = $store->get('missing');

        $this->assertNull($result);
    }

    public function testGetParsesJsonPayloadFromRedis(): void
    {
        $redis = new FakeRedisClient();
        $redis->values[self::DEFAULT_PREFIX . 'key1'] = '{"code":"ABCD","attempts":2}';
        $store = $this->createStore($redis);

        $result = $store->get('key1');

        $this->assertSame(['code' => 'ABCD', 'attempts' => 2], $result);
    }

    public function testGetThrowsJsonExceptionWhenRedisPayloadIsInvalidJson(): void
    {
        $redis = new FakeRedisClient();
        $redis->values[self::DEFAULT_PREFIX . 'key_bad'] = '{bad-json';
        $store = $this->createStore($redis);

        $this->expectException(JsonException::class);

        $store->get('key_bad');
    }

    public function testPutPassesTtlThroughWithoutClamping(): void
    {
        $redis = new FakeRedisClient();
        $store = $this->createStore($redis);

        $store->put('k0', ['code' => 'X1'], 0);
        $store->put('kn', ['code' => 'X2'], -10);

        $this->assertCount(2, $redis->setCalls);
        $this->assertSame(self::DEFAULT_PREFIX . 'k0', $redis->setCalls[0]['key']);
        $this->assertSame(self::DEFAULT_PREFIX . 'kn', $redis->setCalls[1]['key']);
        $this->assertSame(0, $redis->setCalls[0]['ttl']);
        $this->assertSame(-10, $redis->setCalls[1]['ttl']);
    }

    public function testPutUsesCustomPrefix(): void
    {
        $redis = new FakeRedisClient();
        $store = $this->createStore($redis, 'custom:captcha:');

        $store->put('abc', ['code' => 'A'], 30);

        $this->assertSame('custom:captcha:abc', $redis->setCalls[0]['key']);
    }

    public function testGetReadsValueUsingCustomPrefix(): void
    {
        $redis = new FakeRedisClient();
        $redis->values['custom:captcha:lookup'] = '{"code":"ZXCV"}';
        $store = $this->createStore($redis, 'custom:captcha:');

        $result = $store->get('lookup');

        $this->assertSame(['code' => 'ZXCV'], $result);
    }

    public function testPutKeepsPositiveTtlAsIs(): void
    {
        $redis = new FakeRedisClient();
        $store = $this->createStore($redis);

        $store->put('keep', ['code' => 'ABC'], 120);

        $this->assertSame(120, $redis->setCalls[0]['ttl']);
    }

    public function testForgetDeletesPrefixedKey(): void
    {
        $redis = new FakeRedisClient();
        $store = $this->createStore($redis);

        $store->forget('key2');

        $this->assertSame([self::DEFAULT_PREFIX . 'key2'], $redis->delCalls);
    }

    public function testForgetRemovesPreviouslyStoredPayload(): void
    {
        $redis = new FakeRedisClient();
        $store = $this->createStore($redis);

        $store->put('evict-me', ['code' => 'AB12'], 30);
        $this->assertSame(['code' => 'AB12'], $store->get('evict-me'));

        $store->forget('evict-me');

        $this->assertNull($store->get('evict-me'));
    }

    protected function createStore(FakeRedisClient $redis, ?string $prefix = null): Store
    {
        $this->container->replace(ClientInterface::class, $redis);

        $parameters = [];
        if ($prefix !== null) {
            $parameters['prefix'] = $prefix;
        }

        return $this->make(Store::class, $parameters);
    }
}

class FakeRedisClient implements ClientInterface
{
    /** @var array<string, string> */
    public array $values = [];

    /** @var list<array{key: string, value: string, ttl: int}> */
    public array $setCalls = [];

    /** @var list<string> */
    public array $delCalls = [];

    public function getUri(): ?string
    {
        return null;
    }


    public function get(string $key): string|false
    {
        return $this->values[$key] ?? false;
    }

    public function set(string $key, string $value, int $ttl): bool
    {
        $this->values[$key] = $value;
        $this->setCalls[] = ['key' => $key, 'value' => $value, 'ttl' => $ttl];
        return true;
    }

    public function del(string $key): int
    {
        $this->delCalls[] = $key;
        unset($this->values[$key]);
        return 1;
    }

    public function getTransient(): static
    {
        return $this;
    }
}
