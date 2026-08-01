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
$request = $transport->read();

echo json_encode($request, JSON_THROW_ON_ERROR);
