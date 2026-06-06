<?php

declare(strict_types=1);

namespace Switon\Inspector\Collector;

use JsonSerializable;
use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;

use function is_array;

/**
 * Collect registered service providers for inspector output.
 *
 * @see \Switon\Core\CollectorInterface
 * @see \Switon\ComposerExtra\ComposerExtraInterface
 */
class ProviderCollector implements CollectorInterface, JsonSerializable
{
    #[Autowired] protected ComposerExtraInterface $composerExtra;

    public function boot(): void
    {
        // No initialization needed
    }

    public function collect(): array
    {
        $providers = [];

        foreach ($this->composerExtra->all() as $package => $extra) {
            if (!isset($extra['switon']['providers']) || !is_array($extra['switon']['providers'])) {
                continue;
            }

            foreach ($extra['switon']['providers'] as $providerClass) {
                $providers[$providerClass] = $package;
            }
        }

        return $providers;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [];
    }
}
