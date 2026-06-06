<?php

declare(strict_types=1);

use Switon\Http\Kernel;

ini_set('memory_limit', '-1');
ini_set('default_socket_timeout', '-1');

require __DIR__ . '/../bootstrap/autoload.php';

$kernel = new Kernel(dirname(__DIR__));
$kernel->start();
