<?php

declare(strict_types=1);

namespace Switon\McpClient\Importer;

use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\Json;
use Throwable;

use function is_array;
use function is_string;

/**
 * JSON whose servers live under `mcpServers` (Cursor, Claude, Copilot CLI, …) or VS Code's `servers`.
 *
 * Guidance: missing file, bad JSON, or neither object → `[]`. Prefer `mcpServers` when both exist. Unknown per-server shapes are skipped.
 */
class McpServersJsonImporter implements ImporterInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;

    public function import(string $path): array
    {
        if (!$this->filesystem->exists($path)) {
            return [];
        }

        try {
            $data = Json::parse($this->filesystem->read($path));
        } catch (Throwable) {
            return [];
        }
        if (!is_array($data)) {
            return [];
        }
        $servers = $data['mcpServers'] ?? null;
        if (!is_array($servers)) {
            $servers = $data['servers'] ?? null;
        }
        if (!is_array($servers)) {
            return [];
        }

        $out = [];
        foreach ($servers as $id => $raw) {
            if (!is_string($id) || $id === '' || !is_array($raw)) {
                continue;
            }
            $entry = Entries::from($raw);
            if ($entry !== null) {
                $out[$id] = $entry;
            }
        }

        return $out;
    }
}
