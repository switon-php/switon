<?php

declare(strict_types=1);

namespace Switon\Inspector;

use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Switon\Core\Lazy;
use Switon\Http\RequestInterface;
use Switon\Redis\ClientInterface;

use function base64_decode;
use function base64_encode;
use function gzdecode;
use function gzencode;
use function implode;
use function is_string;

/**
 * Persists inspector payloads in Redis or filesystem storage.
 *
 * Use when inspector data must be stored at request end and loaded by render endpoints.
 *
 * Configure with:
 * - <code>ttl</code> to choose Redis mode (>0) or filesystem mode (=0)
 * - <code>prefix</code> for Redis key naming
 * - <code>broadcast</code> to publish generated links on Redis channels
 *
 * @see \Switon\Inspector\StorageInterface
 * @see \Switon\Inspector\Inspector
 * @see \Switon\Inspector\InspectorContext
 */
class Storage implements StorageInterface
{
    #[Autowired] protected AppInterface $app;
    #[Autowired] protected RequestInterface $request;
    #[Autowired] protected ClientInterface|Lazy $redisCache;
    #[Autowired] protected FilesystemInterface $filesystem;

    /** Inspector payload TTL in seconds; 0 switches to filesystem mode. */
    #[Autowired] protected int $ttl = 3600;
    /** Redis key prefix for inspector payload entries. */
    #[Autowired] protected string $prefix = 'cache:inspector:';
    /** Whether to publish generated links via Redis pub/sub. */
    #[Autowired] protected bool $broadcast = true;

    /** {@inheritDoc} */
    public function read(string $key): ?string
    {
        if ($this->ttl) {
            if (($content = $this->redisCache->get($this->prefix . $key)) === false) {
                return null;
            }
            // For Redis, decode base64 first, then decompress
            $content = is_string($content) ? base64_decode($content, true) : null;
        } else {
            $file = "@runtime/inspector/$key.zip";
            $content = $this->filesystem->isFile($file) ? $this->filesystem->read($file) : null;
        }

        if (!is_string($content)) {
            return null;
        }

        // Corrupt or non-gzip payloads are treated as missing; @ avoids zlib data-error noise.
        $decoded = @gzdecode($content);

        return is_string($decoded) ? $decoded : null;
    }

    /** {@inheritDoc} */
    public function write(string $key, array $data): void
    {
        // Encode JSON data with gzip compression
        $json = Json::stringify($data, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT);
        $compressed = gzencode($json);

        if ($this->ttl) {
            // For Redis, add base64 encoding to avoid binary data issues
            $content = base64_encode($compressed);
            $this->redisCache->set($this->prefix . $key, $content, $this->ttl);
        } else {
            $this->filesystem->write("@runtime/inspector/$key.zip", $compressed);
        }
    }

    /** {@inheritDoc} */
    public function broadcastLink(string $link): void
    {
        if ($this->ttl && $this->broadcast) {
            $this->redisCache->publish($this->getBroadcastKey(), $link);
        }
    }

    /**
     * Returns Redis pub/sub channel for inspector link notifications.
     *
     * Format: <code>__inspector:{app_id}:{ip}:{handler}</code>
     */
    protected function getBroadcastKey(): string
    {
        return implode(
            ':',
            ['__inspector', $this->app->id(), $this->request->ip(),
                $this->request->handler()]
        );
    }
}
