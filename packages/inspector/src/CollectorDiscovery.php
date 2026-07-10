<?php

declare(strict_types=1);

namespace Switon\Inspector;

use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;
use Switon\Core\CollectorInterface;
use Switon\Core\ContainerInterface;

use function array_merge;
use function is_array;
use function is_string;
use function iterator_to_array;

/**
 * Discovers and resolves inspector collectors from metadata and app config.
 *
 * Use when packages contribute collectors through <code>extra.switon.collectors</code>
 * and applications need to override or disable entries.
 *
 * Configure with:
 * - <code>collectors</code> for explicit add/override/disable definitions
 * - <code>autoDiscover</code> to enable composer metadata discovery
 *
 * @see \Switon\Inspector\CollectorDiscoveryInterface
 * @see \Switon\ComposerExtra\ComposerExtraInterface
 */
class CollectorDiscovery implements CollectorDiscoveryInterface
{
    #[Autowired] protected ComposerExtraInterface $composerExtra;
    /** Container used for runtime collector class resolution. */
    #[Autowired] protected ContainerInterface $container;

    /** @var iterable<string, string|null> Raw collector definitions; null disables discovered keys. */
    #[Autowired] protected iterable $collectors = [];
    /** Whether to auto-discover collectors from composer metadata. */
    #[Autowired] protected bool $autoDiscover = true;

    /** @var array<string, CollectorInterface>|null Cached collector instances. */
    protected ?array $resolved = null;

    /** {@inheritDoc} */
    public function resolve(): array
    {
        if ($this->resolved !== null) {
            return $this->resolved;
        }

        $discovered = $this->autoDiscover ? $this->discoverFromComposer() : [];
        $definitions = array_merge($discovered, $this->normalizeCollectorDefinitions());
        $this->resolved = [];
        foreach ($definitions as $key => $definition) {
            if ($definition === null) {
                continue;
            }

            $this->resolved[$key] = $this->container->get($definition);
        }

        return $this->resolved;
    }

    /**
     * Returns raw collector definitions from config without dropping null disable markers.
     *
     * @return array<string, string|null>
     */
    protected function normalizeCollectorDefinitions(): array
    {
        return is_array($this->collectors)
            ? $this->collectors
            : iterator_to_array($this->collectors);
    }

    /**
     * Discovers collector definitions from installed package metadata.
     *
     * @return array<string, string> Collector key to class name map
     */
    protected function discoverFromComposer(): array
    {
        $collectors = [];

        $allExtra = $this->composerExtra->all();
        foreach ($allExtra as $extra) {
            if (!isset($extra['switon']['collectors']) || !is_array($extra['switon']['collectors'])) {
                continue;
            }

            foreach ($extra['switon']['collectors'] as $key => $collectorClass) {
                if (!is_string($key) || !is_string($collectorClass) || empty($collectorClass)) {
                    continue;
                }

                $collectors[$key] = $collectorClass;
            }
        }

        return $collectors;
    }
}
