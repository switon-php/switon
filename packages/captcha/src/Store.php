<?php

declare(strict_types=1);

namespace Switon\Captcha;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Json;
use Switon\Redis\ClientInterface;

/**
 * Redis-backed CAPTCHA payload store keyed by challenge id.
 *
 * Guidance: Use prefix to isolate apps or tenants; payload JSON must preserve numeric fields.
 *
 * Road-signs:
 * - get() returns decoded payload or null
 * - put() normalizes ttl <= 0 to 1
 * - forget() removes one prefixed key
 *
 * @see \Switon\Captcha\StoreInterface
 * @see \Switon\Captcha\AbstractCaptcha
 * @see \Switon\Redis\ClientInterface
 */
class Store implements StoreInterface
{
    #[Autowired] protected ClientInterface $redis;

    /** Redis key prefix for captcha payloads. */
    #[Autowired] protected string $prefix = 'captcha:';

    /**
     * {@inheritDoc}
     */
    public function get(string $key): ?array
    {
        $data = $this->redis->get($this->prefix . $key);

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
            $this->prefix . $key,
            Json::stringify($payload),
            $ttl > 0 ? $ttl : 1
        );
    }

    /**
     * {@inheritDoc}
     */
    public function forget(string $key): void
    {
        $this->redis->del($this->prefix . $key);
    }
}
