<?php
declare(strict_types=1);

use Switon\Db\ClientInterface;
use Switon\Di\Factory;

return [
    ClientInterface::class => [
        'class' => Factory::class,
        'default' => [
            'uri' => 'sqlite:' . dirname(__DIR__) . '/database/tutorial.sqlite',
        ],
    ],
];
