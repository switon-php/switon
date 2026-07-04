<?php

declare(strict_types=1);

namespace Switon\Captcha;

use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;
use Switon\Redis\ClientInterface;

use function sprintf;

/**
 * Redis-backed CAPTCHA payload store keyed by challenge id.
 *
 * Guidance: Default prefix is app-scoped (`cache:{appId}:captcha:`). Override
 * <code>prefix</code> when multiple apps intentionally share one captcha namespace.
 *
 * Road-signs:
 * - get() returns decoded payload or null
 * - put() passes ttl through to Redis (invalid TTL fails at the client)
 * - forget() removes one prefixed key
 *
 * @see \Switon\Captcha\StoreInterface
 * @see \Switon\Captcha\AbstractCaptcha
 * @see \Switon\Redis\ClientInterface
 */
class Store implements StoreInterface
{
    #[Autowired] protected AppInterface $app;
    #[Autowired] protected ClientInterface $redis;

    /** Redis key prefix; null uses app-scoped default. */
    #[Autowired] protected ?string $prefix = null;

    /**
     * {@inheritDoc}
     */
    public function get(string $key): ?array
    {
        $data = $this->redis->get($this->key($key));

        if ($data === false) {
            return null;
        }

        return Json::parse($data);
    }

    /**
     * {@inheritDoc}
     */
    public function put(string $key, array $payload, int $ttl): void
    {
        $this->redis->set(
            $this->key($key),
            Json::stringify($payload),
            $ttl
        );
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $key): void
    {
        $this->redis->del($this->key($key));
    }

    protected function key(string $key): string
    {
        return ($this->prefix ?? sprintf('cache:%s:captcha:', $this->app->id())) . $key;
    }
}
