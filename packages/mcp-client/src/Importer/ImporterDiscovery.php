<?php

declare(strict_types=1);

namespace Switon\McpClient\Importer;

use Switon\ComposerExtra\ComposerExtraInterface;
use Switon\Core\Attribute\Autowired;
use Switon\McpClient\Exception;
use Switon\McpClient\ImportersInterface;

use function is_string;

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
            if (!is_string($path) || !is_string($class)) {
                Exception::raise('MCP importer extra entries must be path => class strings.');
            }
            /** @var class-string<ImporterInterface> $class */
            $this->importers->set($path, $class);
        }
    }
}
