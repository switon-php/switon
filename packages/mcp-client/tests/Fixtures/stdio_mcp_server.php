<?php

declare(strict_types=1);

require __DIR__ . '/monorepo_autoload.php';

use Switon\Mcp\AbstractTool;
use Switon\Mcp\ProtocolHandler;
use Switon\Mcp\ServerInfo;
use Switon\Mcp\StdioServer;
use Switon\Mcp\Tools;
use Switon\Mcp\ToolSchema;
use Switon\Mcp\Transport\StdioTransport;
use Switon\Testing\Container;

$ping = new class () extends AbstractTool {
    public function schema(string $name): ToolSchema
    {
        return $this->schema ??= new ToolSchema(description: 'Health check; returns pong.');
    }

    public function call(string $name, array $arguments): mixed
    {
        return 'pong';
    }
};

$container = new Container();
$tools = $container->make(Tools::class);
$tools->add('ping', $ping);

$stdioServer = $container->make(StdioServer::class, [
    'stdioTransport' => $container->make(StdioTransport::class),
    'protocolHandler' => $container->make(ProtocolHandler::class, [
        'serverInfo' => new ServerInfo(),
    ]),
    'tools' => $tools,
]);
$stdioServer->serve();
