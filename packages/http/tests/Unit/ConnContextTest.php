<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Http\Ws\ConnContext;

class ConnContextTest extends TestCase
{
    public function testBindGetAndRemoveAreKeyedByFd(): void
    {
        $context = new ConnContext();

        $this->assertNull($context->get(3));

        $context->bind(3, 'App\\Ws\\ChatController', ['room' => '1'], '/chat');
        $this->assertSame([
            'controller' => 'App\\Ws\\ChatController',
            'variables' => ['room' => '1'],
            'path' => '/chat',
        ], $context->get(3));

        $context->bind(3, 'App\\Ws\\OtherController');
        $this->assertSame([
            'controller' => 'App\\Ws\\OtherController',
            'variables' => [],
            'path' => '',
        ], $context->get(3));

        $context->remove(3);
        $this->assertNull($context->get(3));
    }
}
