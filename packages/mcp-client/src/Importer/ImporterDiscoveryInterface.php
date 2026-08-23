<?php

declare(strict_types=1);

namespace Switon\McpClient\Importer;

use Switon\McpClient\ImportersInterface;

/**
 * Loads `extra.switon.mcp.importers` (path => class) into {@see ImportersInterface}.
 *
 * Guidance: `discover()` registers every extra entry via {@see ImportersInterface::set()}; it does not import or write `mcp.yml`.
 *
 * @see \Switon\McpClient\Importer\ImporterDiscovery
 */
interface ImporterDiscoveryInterface
{
    public function discover(): void;
}
