<?php

declare(strict_types=1);

namespace Switon\Mcp;

use Switon\Core\Attribute\Autowired;

/**
 * Autowired MCP server identity for <code>initialize</code>.
 *
 * @see \Switon\Mcp\ServerInfoInterface
 * @see \Switon\Mcp\ProtocolHandler
 */
class ServerInfo implements ServerInfoInterface
{
    #[Autowired] protected string $protocolVersion = '2024-11-05';
    #[Autowired] protected string $name = 'switon';
    #[Autowired] protected string $version = '1.0.0';
    #[Autowired] protected ?string $description = null;

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }
}
