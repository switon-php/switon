<?php

declare(strict_types=1);

namespace Switon\McpClient\Importer;

use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Yaml\YamlReaderInterface;

use function is_array;
use function is_string;

/**
 * Native `~/.switon/mcp.yml` (id => command|url entry). Preserves `enabled` and other fields.
 */
class SwitonYamlImporter implements ImporterInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected YamlReaderInterface $yamlReader;

    public function import(string $path): array
    {
        if (!$this->filesystem->exists($path)) {
            return [];
        }

        $entries = $this->yamlReader->load($path);
        if ($entries === []) {
            return [];
        }

        $out = [];
        foreach ($entries as $id => $raw) {
            if (!is_string($id) || $id === '' || !is_array($raw)) {
                continue;
            }
            $hasUrl = is_string($raw['url'] ?? null) && $raw['url'] !== '';
            $hasCommand = is_string($raw['command'] ?? null) && $raw['command'] !== '';
            if (!$hasUrl && !$hasCommand) {
                continue;
            }
            $out[$id] = $raw;
        }

        return $out;
    }
}
