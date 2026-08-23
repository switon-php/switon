<?php

declare(strict_types=1);

namespace Switon\McpClient;

use Switon\Core\Attribute\Autowired;
use Switon\McpClient\Importer\SwitonYamlImporter;

use function array_key_exists;
use function array_keys;

/**
 * Reads `~/.switon/mcp.yml` and registers entries on {@see ClientsInterface}.
 */
class ClientDiscovery implements ClientDiscoveryInterface
{
    #[Autowired] protected SwitonYamlImporter $switonYamlImporter;
    #[Autowired] protected ClientsInterface $clients;

    /** MCP server map YAML; missing file → no clients. PathAlias expands `~/`. */
    #[Autowired] protected string $file = '~/.switon/mcp.yml';

    public function discover(): void
    {
        $desired = [];
        foreach ($this->switonYamlImporter->import($this->file) as $id => $entry) {
            if (array_key_exists('enabled', $entry) && $entry['enabled'] === false) {
                continue;
            }
            unset($entry['enabled']);
            $desired[$id] = $entry;
            $this->clients->add($id, $entry);
        }
        foreach (array_keys($this->clients->all()) as $id) {
            if (!isset($desired[$id])) {
                $this->clients->remove($id);
            }
        }
    }
}
