<?php

declare(strict_types=1);

namespace Switon\Agency\Tests;

use ArrayIterator;
use Switon\Agency\ProfileDiscovery;
use Switon\Agency\ProfileDiscoveryInterface;
use Switon\Agency\ProfilesInterface;
use Switon\Agency\SegmentsInterface;
use Switon\Testing\TestCase as BaseTestCase;

/**
 * Catalog tests rebind profile discovery to a fixed set of roots.
 */
abstract class CatalogTestCase extends BaseTestCase
{
    /**
     * Rebind profile discovery to these roots and load a fresh catalog.
     *
     * @param list<string> $paths
     */
    protected function useProfilePaths(array $paths): void
    {
        $this->container->replace(ProfileDiscoveryInterface::class, [
            'class' => ProfileDiscovery::class,
            'paths' => new ArrayIterator($paths),
        ]);
        $this->container->remove(ProfilesInterface::class);
        $this->container->remove(SegmentsInterface::class);
        $this->container->get(ProfileDiscoveryInterface::class)->discover();
    }
}
