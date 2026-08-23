<?php

declare(strict_types=1);

namespace Switon\McpClient\Importer;

use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;

use function explode;
use function is_bool;
use function is_int;
use function is_string;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function stripcslashes;
use function substr;
use function trim;

/**
 * Codex `[mcp_servers.*]` tables in `config.toml`. Typical path: `~/.codex/config.toml`.
 */
class CodexTomlImporter implements ImporterInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;

    public function import(string $path): array
    {
        if (!$this->filesystem->exists($path)) {
            return [];
        }

        $servers = $this->parseMcpServers($this->filesystem->read($path));
        $out = [];
        foreach ($servers as $id => $raw) {
            $entry = Entries::from($raw);
            if ($entry !== null) {
                $out[$id] = $entry;
            }
        }

        return $out;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function parseMcpServers(string $toml): array
    {
        /** @var array<string, array<string, mixed>> $servers */
        $servers = [];
        $current = null;
        $inEnv = false;

        foreach (explode("\n", $toml) as $line) {
            $trim = trim($line);
            if ($trim === '' || str_starts_with($trim, '#')) {
                continue;
            }

            if (preg_match('/^\[mcp_servers\.([^\].]+)\]$/', $trim, $m) === 1) {
                $current = $m[1];
                $inEnv = false;
                $servers[$current] ??= [];
                continue;
            }
            if (preg_match('/^\[mcp_servers\.([^\].]+)\.env\]$/', $trim, $m) === 1) {
                $current = $m[1];
                $inEnv = true;
                $servers[$current] ??= [];
                $servers[$current]['env'] ??= [];
                continue;
            }
            if (str_starts_with($trim, '[')) {
                $current = null;
                $inEnv = false;
                continue;
            }
            if ($current === null || !str_contains($trim, '=')) {
                continue;
            }

            [$key, $value] = array_map(trim(...), explode('=', $trim, 2));
            if ($key === '') {
                continue;
            }
            $decoded = $this->decodeTomlValue($value);
            if ($inEnv) {
                if (is_string($decoded) || is_int($decoded) || is_bool($decoded)) {
                    $servers[$current]['env'][$key] = $decoded;
                }
                continue;
            }
            if ($key === 'env_vars') {
                continue;
            }
            $servers[$current][$key] = $decoded;
        }

        return $servers;
    }

    protected function decodeTomlValue(string $value): mixed
    {
        $value = trim($value);
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }
        if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
            return stripcslashes(substr($value, 1, -1));
        }
        if (str_starts_with($value, "'") && str_ends_with($value, "'")) {
            return substr($value, 1, -1);
        }
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $inner = trim(substr($value, 1, -1));
            if ($inner === '') {
                return [];
            }
            $items = [];
            foreach (explode(',', $inner) as $part) {
                $items[] = $this->decodeTomlValue(trim($part));
            }

            return $items;
        }

        return $value;
    }
}
