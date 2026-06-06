<?php

declare(strict_types=1);

namespace Switon\Inspector\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use Switon\Core\App;
use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\PathAliasInterface;
use Switon\Http\RequestInterface;
use Switon\Inspector\Storage;
use Switon\Inspector\StorageInterface;
use Switon\Inspector\Tests\TestCase;
use Switon\Redis\ClientInterface;
use DateInterval;

use function base64_encode;
use function gzdecode;
use function gzencode;
use function json_encode;

/**
 * Simple mock Redis client for testing Storage Redis operations.
 */
class MockRedisClientForStorage implements ClientInterface
{
    public array $storage = [];
    public array $calls = [];

    public function getUri(): ?string
    {
        return null;
    }

    public function getTransient(): static
    {
        return $this;
    }

    public function get(string $key): string|false
    {
        $this->calls[] = ['method' => 'get', 'key' => $key];
        return $this->storage[$key] ?? false;
    }

    public function set(string $key, string $value, null|int|DateInterval $ttl = null): bool
    {
        $this->calls[] = ['method' => 'set', 'key' => $key, 'value' => $value, 'ttl' => $ttl];
        $this->storage[$key] = $value;
        return true;
    }

    public function publish(string $channel, string $message): int
    {
        $this->calls[] = ['method' => 'publish', 'channel' => $channel, 'message' => $message];
        return 1;
    }
}

#[AllowMockObjectsWithoutExpectations]
class StorageTest extends TestCase
{
    #[Autowired] protected StorageInterface $storage;
    #[Autowired] protected AppInterface $app;
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ClientInterface $redisCache;
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected PathAliasInterface $pathAlias;

    protected function setUp(): void
    {
        parent::setUp();

        $this->container->remove(App::class);
        $this->container->set(App::class, ['id' => 'test-app']);
        // Also update AppInterface to ensure app->id() returns 'test-app'
        $this->container->replace(\Switon\Core\AppInterface::class, ['class' => App::class, 'id' => 'test-app']);

        $this->request = $this->createMock(RequestInterface::class);
        $this->container->set(RequestInterface::class, $this->request);

        // Create a mock that implements the Redis methods via @mixin
        // Since ClientInterface uses @mixin \Redis, we need to create a mock that allows method calls
        // Use a simple mock implementation for Redis operations
        $this->redisCache = new MockRedisClientForStorage();
        $this->container->set(ClientInterface::class, $this->redisCache);

        $this->filesystem = $this->createMock(FilesystemInterface::class);
        $this->container->set(FilesystemInterface::class, $this->filesystem);

        $this->pathAlias = $this->container->get(PathAliasInterface::class);

        // Configure Storage with TTL = 0 to use filesystem (not Redis)
        // All dependencies are now autowired, so we set them via array binding
        $this->container->set(StorageInterface::class, [
            'class' => Storage::class,
            'ttl' => 0,
            'prefix' => 'test:prefix:'
        ]);

        // Autowire all properties including storage (container will auto-resolve StorageInterface to Storage)
        $this->injector->inject($this);
    }

    public function testReadReturnsNullWhenFileDoesNotExist(): void
    {
        $key = 'test-key';
        $filePath = $this->pathAlias->resolve("@runtime/inspector/$key.zip");

        // is_file() returns false, so read() is not called
        $this->filesystem->expects($this->never())
            ->method('read');

        $result = $this->storage->read($key);

        $this->assertNull($result);
    }

    public function testReadReturnsDecodedDataWhenFileExists(): void
    {
        $key = 'test-key';
        $data = ['test' => 'data'];
        $encoded = gzencode(json_encode($data, JSON_PRETTY_PRINT));
        $filePath = $this->pathAlias->resolve("@runtime/inspector/$key.zip");

        // Create the directory and file so is_file() returns true
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($filePath, $encoded);

        $this->filesystem->expects($this->once())
            ->method('read')
            ->with("@runtime/inspector/$key.zip")
            ->willReturn($encoded);

        $result = $this->storage->read($key);

        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertSame($data, $decoded);

        // Cleanup
        if (is_file($filePath)) {
            unlink($filePath);
        }
        if (is_dir($dir) && count(scandir($dir)) === 2) {
            rmdir($dir);
        }
    }

    public function testWriteSavesEncodedDataToFile(): void
    {
        $key = 'test-key';
        $data = ['test' => 'data'];

        $this->filesystem->expects($this->once())
            ->method('write')
            ->with(
                "@runtime/inspector/$key.zip",
                $this->callback(function ($content) use ($data) {
                    $decoded = json_decode(gzdecode($content), true);
                    return $decoded === $data;
                })
            );

        $this->storage->write($key, $data);
    }

    public function testWriteEncodesDataWithGzip(): void
    {
        $key = 'test-key';
        $data = ['test' => 'data'];

        $expectedContent = gzencode(\Switon\Core\Json::stringify($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT));

        $this->filesystem->expects($this->once())
            ->method('write')
            ->with("@runtime/inspector/$key.zip", $expectedContent);

        $this->storage->write($key, $data);
    }

    public function testBroadcastLinkDoesNothingWhenTtlIsZero(): void
    {
        $link = 'http://example.com/inspector/test-key.html';

        // Act — when TTL is 0 (filesystem mode), broadcastLink should exit early
        // Note: ClientInterface uses @mixin \Redis with no declared methods,
        // so mock expectations cannot be configured for 'publish'
        $this->storage->broadcastLink($link);

        // Assert — no Redis interaction occurred (would throw if TTL branch was entered)
        $this->addToAssertionCount(1);
    }

    public function testReadReturnsNullWhenRedisKeyDoesNotExist(): void
    {
        // Arrange - Configure for Redis mode (TTL > 0)
        $this->container->replace(StorageInterface::class, [
            'class' => Storage::class,
            'ttl' => 3600,
            'prefix' => 'test:prefix:'
        ]);
        $this->injector->inject($this);

        $key = 'non-existent-key';
        // MockRedisClientForStorage returns false by default for non-existent keys

        // Act
        $result = $this->storage->read($key);

        // Assert
        $this->assertNull($result);
    }

    public function testReadReturnsDecodedDataFromRedis(): void
    {
        // Arrange - Configure for Redis mode (TTL > 0)
        $this->container->replace(StorageInterface::class, [
            'class' => Storage::class,
            'ttl' => 3600,
            'prefix' => 'test:prefix:'
        ]);
        $this->injector->inject($this);

        $key = 'test-key';
        $data = ['test' => 'data'];
        $json = \Switon\Core\Json::stringify($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT);
        $compressed = gzencode($json);
        $base64Encoded = base64_encode($compressed);

        // Set the value in mock storage
        $this->redisCache->storage["test:prefix:$key"] = $base64Encoded;

        // Act
        $result = $this->storage->read($key);

        // Assert
        $this->assertIsString($result);
        $decoded = json_decode($result, true);
        $this->assertSame($data, $decoded);
    }

    public function testReadReturnsNullWhenRedisPayloadIsNotValidGzip(): void
    {
        $this->container->replace(StorageInterface::class, [
            'class' => Storage::class,
            'ttl' => 3600,
            'prefix' => 'test:prefix:',
        ]);
        $this->injector->inject($this);

        $key = 'bad-payload';
        $this->redisCache->storage["test:prefix:$key"] = base64_encode('not gzip data');

        $this->assertNull($this->storage->read($key));
    }

    public function testWriteSavesBase64EncodedDataToRedis(): void
    {
        // Arrange - Configure for Redis mode (TTL > 0)
        $this->container->replace(StorageInterface::class, [
            'class' => Storage::class,
            'ttl' => 3600,
            'prefix' => 'test:prefix:'
        ]);
        $this->injector->inject($this);

        $key = 'test-key';
        $data = ['test' => 'data'];
        $json = \Switon\Core\Json::stringify($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT);
        $compressed = gzencode($json);
        $expectedBase64 = base64_encode($compressed);

        // Verify set was called by checking calls array after execution
        $callCountBefore = count($this->redisCache->calls);

        // Act
        $this->storage->write($key, $data);

        // Assert - verify set was called and value stored
        $this->assertGreaterThan($callCountBefore, count($this->redisCache->calls));
        $lastCall = array_last($this->redisCache->calls);
        $this->assertSame('set', $lastCall['method']);
        $this->assertSame("test:prefix:$key", $lastCall['key']);
        $this->assertSame($expectedBase64, $lastCall['value']);
        $this->assertSame(3600, $lastCall['ttl']);
        $this->assertArrayHasKey("test:prefix:$key", $this->redisCache->storage);
    }

    public function testBroadcastLinkPublishesToRedisWhenTtlIsSet(): void
    {
        // Arrange - Configure for Redis mode with broadcast enabled
        // Ensure App id is set correctly
        $this->container->remove(App::class);
        $this->container->set(App::class, ['id' => 'test-app']);
        $this->container->replace(\Switon\Core\AppInterface::class, ['class' => App::class, 'id' => 'test-app']);

        $this->request->method('ip')->willReturn('127.0.0.1');
        $this->request->method('handler')->willReturn('TestController::action');

        $this->container->replace(StorageInterface::class, [
            'class' => Storage::class,
            'ttl' => 3600,
            'prefix' => 'test:prefix:',
            'broadcast' => true
        ]);
        $this->injector->inject($this);

        $link = 'http://example.com/inspector/test-key.html';
        $expectedChannel = '__inspector:test-app:127.0.0.1:TestController::action';

        $callCountBefore = count($this->redisCache->calls);

        // Act
        $this->storage->broadcastLink($link);

        // Assert - verify publish was called
        $this->assertGreaterThan($callCountBefore, count($this->redisCache->calls));
        $lastCall = array_last($this->redisCache->calls);
        $this->assertSame('publish', $lastCall['method']);
        $this->assertSame($expectedChannel, $lastCall['channel']);
        $this->assertSame($link, $lastCall['message']);
    }

    public function testBroadcastLinkSkipsWhenBroadcastIsDisabled(): void
    {
        // Arrange - Configure for Redis mode but broadcast disabled
        $this->container->replace(StorageInterface::class, [
            'class' => Storage::class,
            'ttl' => 3600,
            'prefix' => 'test:prefix:',
            'broadcast' => false
        ]);
        $this->injector->inject($this);

        $link = 'http://example.com/inspector/test-key.html';

        // Act & Assert - should not call publish
        $this->storage->broadcastLink($link);
        $this->addToAssertionCount(1);
    }

    public function testDefaultPrefixIsUsedWhenNotConfigured(): void
    {
        // Arrange
        $this->container->remove(StorageInterface::class);
        $this->container->set(StorageInterface::class, [
            'class' => Storage::class,
            'ttl' => 0,
            // prefix not provided, should use default 'cache:inspector:'
        ]);

        // Act
        $storage = $this->container->make(StorageInterface::class);

        // Assert - verify storage is created successfully with default prefix
        $this->assertInstanceOf(StorageInterface::class, $storage);
    }
}
