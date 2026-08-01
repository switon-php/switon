<?php

declare(strict_types=1);

namespace Switon\Mcp;

use Switon\Core\Attribute\Autowired;
use Switon\Core\ContainerInterface;
use Switon\Core\ServiceProviderInterface;

/**
 * Registers MCP services and runs tool discovery on boot.
 *
 * Road-signs:
 * - Configure {@see Tools} <code>$tools</code> (name => {@see ToolInterface} class);
 *   `call()` takes `name` + `arguments` only
 * - Optional scan: {@see ToolDiscovery} <code>$files</code> (default <code>@app/Mcp/Tool/*Tool.php</code>);
 *   missing dir is a no-op; explicit <code>$tools</code> wins on name clash
 * - Argument prep: {@see ArgumentBinderInterface} (defaults, required, string/integer)
 * - Inject {@see SessionInterface} in tools for <code>getId()</code> / headers / <code>getAccessKey()</code> (streamable HTTP)
 * - Serve over stdio with <code>bin/mcp-serve</code> / {@see Command\McpCommand} via {@see StdioServerInterface}
 * - Streamable HTTP: app controller injects {@see HttpServerInterface}
 * - Tool hooks: {@see Event\ToolCalling}, {@see Event\ToolCalled}
 *
 * @see \Switon\Mcp\ToolsInterface
 * @see \Switon\Mcp\ToolDiscoveryInterface
 * @see \Switon\Mcp\HttpServerInterface
 * @see \Switon\Mcp\StdioServerInterface
 */
class ServiceProvider implements ServiceProviderInterface
{
    #[Autowired] protected ToolDiscoveryInterface $toolDiscovery;

    public function register(ContainerInterface $container): void
    {
    }

    public function boot(): void
    {
        $this->toolDiscovery->discover();
    }
}
