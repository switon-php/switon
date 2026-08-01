<?php

declare(strict_types=1);

$packageVendor = dirname(__DIR__) . '/../vendor/autoload.php';
if (file_exists($packageVendor)) {
    require $packageVendor;
} else {
    $repoRoot = dirname(__DIR__, 4);
    require $repoRoot . '/vendor/autoload.php';
    require $repoRoot . '/tests/bootstrap.php';
}

use Switon\Mcp\Transport\StdioTransport;
use Switon\Testing\Container;

$transport = (new Container())->make(StdioTransport::class);
$request = ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping'];
$response = ['jsonrpc' => '2.0', 'id' => 7, 'result' => []];
$transport->write($request, $response);
