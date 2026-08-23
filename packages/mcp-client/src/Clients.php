<?php

declare(strict_types=1);

namespace Switon\McpClient;

use Switon\Core\Attribute\Autowired;
use Switon\Core\MakerInterface;
use Switon\McpClient\Importer\Entries;
use Switon\McpClient\Transport\HttpTransportInterface;
use Switon\McpClient\Transport\StdioTransportInterface;
use Switon\Sync\CloseableInterface;
use Switon\Sync\TerminationInterface;

use function json_encode;
use function ksort;

class Clients implements ClientsInterface
{
    #[Autowired] protected MakerInterface $maker;
    #[Autowired] protected TerminationInterface $termination;

    #[Autowired] protected string $mcpClientInfo = 'switon-agent';

    /** @var array<string, ClientInterface> */
    protected array $clients = [];

    /** @var array<string, string> */
    protected array $fingerprints = [];

    public function add(string $id, array $entry): void
    {
        $fingerprint = $this->entryFingerprint($entry);
        if (isset($this->clients[$id]) && ($this->fingerprints[$id] ?? '') === $fingerprint) {
            return;
        }

        $this->fingerprints[$id] = $fingerprint;
        $this->clients[$id] = $this->wireClient($id, $entry);
    }

    public function get(string $id): ClientInterface
    {
        if (isset($this->clients[$id])) {
            return $this->clients[$id];
        }

        Exception::raise('Unknown MCP server "{server}".', ['server' => $id]);
    }

    public function all(): array
    {
        return $this->clients;
    }

    public function remove(string $id): void
    {
        unset($this->clients[$id], $this->fingerprints[$id]);
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

        $client = $this->maker->make(ClientInterface::class, [
            'server' => $server,
            'brief' => Entries::brief($entry),
            'transport' => $transport,
            'clientInfo' => $clientInfo,
        ]);
        if ($client instanceof CloseableInterface) {
            $this->termination->register($client);
        }

        return $client;
    }
}
