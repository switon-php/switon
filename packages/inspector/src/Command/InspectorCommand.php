<?php

declare(strict_types=1);

namespace Switon\Inspector\Command;

use Switon\Core\AppInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;
use Switon\Core\Lazy;
use Switon\Redis\ClientInterface;

use function explode;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_starts_with;

/**
 * Watches published inspector links from Redis pub/sub channels.
 *
 * @see \Switon\Inspector\InspectorInterface
 * @see \Switon\Inspector\StorageInterface
 */
class InspectorCommand
{
    #[Autowired] protected ConsoleInterface $console;
    #[Autowired] protected AppInterface $app;
    #[Autowired] protected ClientInterface|Lazy $redisCache;

    /**
     * Subscribes to inspector broadcasts and prints links that match the requested filters.
     *
     * @param string|null $app_id App ID to watch, or null to use the current app ID.
     * @param string $handlerPrefix Only prints links whose handler starts with this prefix.
     * @param string $ip Only prints links for this exact client IP or trailing last-octet suffix.
     */
    public function watchAction(?string $app_id = null, string $handlerPrefix = '', string $ip = ''): void
    {
        $app_id = $app_id ?? $this->app->id();
        $key = "__inspector:$app_id:*";
        $this->console->writeLn('subscribe to ' . $key);

        $this->redisCache->psubscribe(
            [$key],
            function ($redis, $pattern, $channel, $msg) use ($handlerPrefix, $ip) {
                [, , $_ip, $_handler] = explode(':', $channel, 4);
                if ($handlerPrefix !== '' && !str_starts_with($_handler, $handlerPrefix)) {
                    return;
                }

                if ($ip !== '' && $ip !== $_ip) {
                    if (str_contains($ip, '.')) {
                        return;
                    } elseif (!str_ends_with($_ip, ".$ip")) {
                        return;
                    }
                }

                $this->console->writeLn(sprintf('[%s][%s]: %s', $_ip, $_handler, $msg));
            }
        );
    }
}
