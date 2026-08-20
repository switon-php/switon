<?php

declare(strict_types=1);

namespace Switon\McpClient;

use Switon\Core\Attribute\Autowired;
use Switon\Core\FilesystemInterface;
use Switon\Core\MakerInterface;
use Switon\McpClient\Transport\HttpTransportInterface;
use Switon\McpClient\Transport\StdioTransportInterface;
use Switon\Yaml\YamlReaderInterface;

use function array_key_exists;
use function array_keys;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function json_encode;
use function ksort;
use function str_contains;

class ClientDiscovery implements ClientDiscoveryInterface
{
    #[Autowired] protected FilesystemInterface $filesystem;
    #[Autowired] protected YamlReaderInterface $yamlReader;
    #[Autowired] protected MakerInterface $maker;
    #[Autowired] protected ClientsInterface $clients;

    /** MCP server map YAML; missing file → no clients. PathAlias expands `~/`. */
    #[Autowired] protected string $file = '~/.switon/mcp.yml';
    #[Autowired] protected string $mcpClientInfo = 'switon-agent';

    /** @var array<string, string> */
    protected array $fingerprints = [];

    public function discover(): void
    {
        $desired = $this->readEntries();
        foreach ($desired as $id => $entry) {
            if (!isset($entry['command']) && !isset($entry['url'])) {
                Exception::raise('MCP client "{name}" must define command or url.', ['name' => $id]);
            }
        }

        $next = [];
        $registered = $this->clients->all();
        foreach ($desired as $id => $entry) {
            $fingerprint = $this->entryFingerprint($entry);
            $next[$id] = $fingerprint;
            if (isset($registered[$id]) && ($this->fingerprints[$id] ?? '') === $fingerprint) {
                continue;
            }
            $this->clients->add($id, $this->wireClient($id, $entry));
        }
        foreach (array_keys($this->clients->all()) as $id) {
            if (!isset($desired[$id])) {
                $this->clients->remove($id);
            }
        }
        $this->fingerprints = $next;
    }

    /**
     * @param array<string, mixed> $entry
     */
    protected function brief(array $entry): string
    {
        if (is_string($entry['url'] ?? null) && $entry['url'] !== '') {
            return $entry['url'];
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
     * @return array<string, array<string, mixed>> server id => entry (enabled stripped)
     */
    protected function readEntries(): array
    {
        if (!$this->filesystem->exists($this->file)) {
            return [];
        }

        /** @var array<int|string, mixed> $entries */
        $entries = $this->yamlReader->load($this->file);
        if ($entries === []) {
            return [];
        }

        $desired = [];
        foreach ($entries as $key => $entry) {
            if (!is_string($key) || !is_array($entry)) {
                Exception::raise('MCP config entries must be string keys mapped to objects.');
            }

            if (array_key_exists('enabled', $entry) && $entry['enabled'] === false) {
                continue;
            }

            unset($entry['enabled']);

            if (str_contains($key, '_')) {
                Exception::raise('MCP client server "{name}" must not contain "_".', ['name' => $key]);
            }

            $desired[$key] = $entry;
        }

        return $desired;
    }

    /**
     * @param array<string, mixed> $entry
     */
    protected function entryFingerprint(array $entry): string
    {
        ksort($entry);

        return json_encode($entry, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $entry
     */
    protected function wireClient(string $server, array $entry): ClientInterface
    {
        $clientInfo = ClientInfo::of($this->mcpClientInfo);
        if (isset($entry['command'])) {
            $transport = $this->maker->make(StdioTransportInterface::class, $entry);
        } else {
            $transport = $this->maker->make(HttpTransportInterface::class, ['clientInfo' => $clientInfo] + $entry);
        }

        return $this->maker->make(ClientInterface::class, [
            'server' => $server,
            'brief' => $this->brief($entry),
            'transport' => $transport,
            'clientInfo' => $clientInfo,
        ]);
    }
}
