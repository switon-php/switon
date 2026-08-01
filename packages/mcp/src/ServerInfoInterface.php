<?php

declare(strict_types=1);

namespace Switon\Mcp;

/**
 * MCP <code>initialize</code> server identity and protocol version.
 *
 * Road-signs:
 * - <code>getName()</code> and <code>getVersion()</code> appear in <code>initialize.serverInfo</code>
 * - <code>getDescription()</code> maps to <code>initialize.instructions</code> when non-empty
 * - <code>getProtocolVersion()</code> is the default when the client omits a version
 * - tools are declared via {@see Tools::$tools}, not this interface
 *
 * @see \Switon\Mcp\ServerInfo
 * @see \Switon\Mcp\ProtocolHandler
 */
interface ServerInfoInterface
{
    public function getProtocolVersion(): string;

    public function getName(): string;

    public function getVersion(): string;

    public function getDescription(): ?string;
}
