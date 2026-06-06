<?php
declare(strict_types=1);

use Switon\Http\RequestHandlerInterface;
use Switon\Http\Filter\CorsFilter;

return [
    RequestHandlerInterface::class => [
        'filters' => [
            'cors' => CorsFilter::class,
            'accessLog' => null,
        ],
    ],
];
