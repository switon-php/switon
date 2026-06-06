<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use Switon\Routing\Inspector\RouterCollector;
use Switon\Routing\Tests\TestCase;

class RouterCollectorTest extends TestCase
{
    public function testJsonSerializeReturnsCollectedRoutes(): void
    {
        $this->container->set(\Switon\Routing\RouterInterface::class, $this->router);
        $this->router->addGet('/users', 'UserController::index');
        $this->router->addPost('/users', 'UserController::create');

        $collector = $this->make(RouterCollector::class);

        $expected = [
            ['verb' => 'GET', 'pattern' => '/users', 'handler' => 'UserController::index'],
            ['verb' => 'POST', 'pattern' => '/users', 'handler' => 'UserController::create'],
        ];

        $this->assertSame($expected, $collector->collect());
        $this->assertSame($expected, $collector->jsonSerialize());
    }
}
