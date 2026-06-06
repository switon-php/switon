<?php
declare(strict_types=1);

use Switon\Core\Contract\AppInterface;

return [
    AppInterface::class => [
        'id' => 'tutorial-mainline-app',
        'name' => 'Switon Tutorial Mainline Proof App',
        'env' => env('APP_ENV', 'dev'),
        'debug' => env('APP_DEBUG', true),
        'timezone' => 'Asia/Shanghai',
        'version' => 'v0.1',
    ],
];
