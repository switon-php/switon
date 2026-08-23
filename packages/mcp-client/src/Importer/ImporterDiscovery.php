<?php

declare(strict_types=1);

namespace Switon\McpClient\Importer;

use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;
use Switon\McpClient\ImportersInterface;

/**
 * Loads `extra.switon.mcp.importers` (path => class) into {@see ImportersInterface}.
 */
class ImporterDiscovery implements ImporterDiscoveryInterface
{
    #[Autowired] protected ComposerExtraInterface $composerExtra;
    #[Autowired] protected ImportersInterface $importers;

    public function discover(): void
    {
        foreach ($this->composerExtra->collect('switon.mcp.importers') as $path => $class) {
            $this->importers->set($path, $class);
        }
    }
}
