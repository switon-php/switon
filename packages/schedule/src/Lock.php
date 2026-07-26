<?php

declare(strict_types=1);

namespace Switon\Schedule;

use Switon\Core\Attribute\Autowired;
use Switon\Core\Attribute\Communal;
use Switon\Redis\ClientInterface;

use function bin2hex;
use function is_string;
use function random_bytes;

/**
 * Default {@see LockInterface}: Redis token lock with NX+EX and token-checked release.
 *
 * @see \Switon\Schedule\LockInterface
 * @see \Switon\Redis\ClientInterface
 */
class Lock implements LockInterface
{
    #[Autowired] protected ClientInterface $redisLock;

    /** @var array<string, string> */
    #[Communal] protected array $ownedTokens = [];

    /** {@inheritDoc} */
    public function tryAcquire(string $key, int $ttlSeconds): bool
    {
        $token = bin2hex(random_bytes(16));
        $ok = $this->redisLock->set($key, $token, ['nx', 'ex' => $ttlSeconds]);

        if ($ok === true) {
            $this->ownedTokens[$key] = $token;
        }

        return $ok === true;
    }

    /** {@inheritDoc} */
    public function release(string $key): void
    {
        $token = $this->ownedTokens[$key] ?? null;
        if ($token === null) {
            return;
        }

        $client = $this->redisLock->getTransient();

        $maxRetries = 3;
        for ($i = 0; $i < $maxRetries; $i++) {
            $client->watch($key);
            $current = $client->get($key);
            if (!is_string($current) || $current !== $token) {
                $client->unwatch();
                unset($this->ownedTokens[$key]);

                return;
            }

            $client->multi();
            $client->del($key);
            $result = $client->exec();
            if ($result !== false) {
                unset($this->ownedTokens[$key]);

                return;
            }
        }

        unset($this->ownedTokens[$key]);
    }
}
