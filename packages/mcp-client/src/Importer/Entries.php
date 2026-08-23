<?php

declare(strict_types=1);

namespace Switon\McpClient\Importer;

use function implode;
use function is_array;
use function is_bool;
use function is_int;
use function is_string;
use function strpos;
use function substr;

/**
 * Normalize foreign MCP server objects into mcp.yml entry shapes.
 */
final class Entries
{
    /**
     * Catalog brief for an mcp.yml-shaped entry (URL without query, or command + args).
     *
     * @param array<string, mixed> $entry
     */
    public static function brief(array $entry): string
    {
        if (is_string($entry['url'] ?? null) && $entry['url'] !== '') {
            $url = $entry['url'];
            $cut = strpos($url, '?');

            return $cut === false ? $url : substr($url, 0, $cut);
        }
        if (!is_string($entry['command'] ?? null) || $entry['command'] === '') {
            return '';
        }
        $parts = [$entry['command']];
        if (is_array($entry['args'] ?? null)) {
            foreach ($entry['args'] as $arg) {
                if (is_string($arg) || is_int($arg)) {
                    $parts[] = (string) $arg;
                }
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>|null
     */
    public static function from(array $raw): ?array
    {
        if (array_key_exists('enabled', $raw) && $raw['enabled'] === false) {
            return null;
        }
        if (array_key_exists('disabled', $raw) && $raw['disabled'] === true) {
            return null;
        }

        if (is_string($raw['url'] ?? null) && $raw['url'] !== '') {
            $entry = ['url' => $raw['url']];
            if (is_array($raw['headers'] ?? null)) {
                $headers = self::stringMap($raw['headers']);
                if ($headers !== []) {
                    $entry['headers'] = $headers;
                }
            }

            return $entry;
        }

        if (is_string($raw['command'] ?? null) && $raw['command'] !== '') {
            $entry = ['command' => $raw['command']];
            if (is_array($raw['args'] ?? null)) {
                $args = [];
                foreach ($raw['args'] as $arg) {
                    if (is_string($arg) || is_int($arg)) {
                        $args[] = (string) $arg;
                    }
                }
                if ($args !== []) {
                    $entry['args'] = $args;
                }
            }
            $env = $raw['env'] ?? $raw['environment'] ?? null;
            if (is_array($env)) {
                $map = self::stringMap($env);
                if ($map !== []) {
                    $entry['env'] = $map;
                }
            }
            if (is_string($raw['cwd'] ?? null) && $raw['cwd'] !== '') {
                $entry['cwd'] = $raw['cwd'];
            }

            return $entry;
        }

        return null;
    }

    /**
     * @param array<mixed> $map
     *
     * @return array<string, string>
     */
    protected static function stringMap(array $map): array
    {
        $out = [];
        foreach ($map as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if (is_string($value) || is_int($value) || is_bool($value)) {
                $out[$key] = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
            }
        }

        return $out;
    }
}
