<?php

declare(strict_types=1);

namespace Switon\Beacon\Command;

use Switon\Command\Attribute\Hidden;
use Switon\Command\Attribute\Tool;
use Switon\ComposerExtra\ComposerExtra;
use Switon\Core\Attribute\Autowired;
use Switon\Core\ConsoleInterface;

/**
 * Diagnose Composer extra cache health.
 */
#[Hidden]
class ComposerExtraCommand
{
    #[Autowired] protected ConsoleInterface $console;

    #[Autowired] protected ComposerExtra $composerExtra;

    /**
     * Check composer-extra cache health.
     */
    #[Tool('[--json]. Exit 0 = healthy cache; exit 1 = unhealthy cache.')]
    public function healthAction(bool $json = false): int
    {
        $health = $this->composerExtra->health();

        if ($json) {
            $this->console->writeLn(json_encode($health, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
            return $health['ok'] ? 0 : 1;
        }

        if ($health['ok']) {
            $this->console->writeLn('OK');
            return 0;
        }

        return $this->console->error(
            'Composer extra cache unhealthy: {reason} ({cacheFile}). Check allow-plugins and rerun composer install.',
            [
                'reason' => $health['reason'],
                'cacheFile' => $health['cacheFile'],
            ]
        );
    }
}
