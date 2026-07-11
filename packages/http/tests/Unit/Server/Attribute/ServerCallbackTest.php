<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit\Server\Attribute;

use Switon\Core\Attributes;
use Switon\Http\Server\Attribute\ServerCallback;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ServerCallbackTest extends TestCase
{
    /**
     * Server adapters register only the methods carrying #[ServerCallback].
     *
     * Registration walks public methods and asks {@see Attributes::has()}, so that lookup is the
     * contract a lifecycle hook depends on.
     */
    public function testServerCallbackMarksOnlyAnnotatedMethodsForRegistration(): void
    {
        $annotated = new ReflectionMethod(ServerCallbackProbe::class, 'onServerStart');
        $plain = new ReflectionMethod(ServerCallbackProbe::class, 'notACallback');

        $this->assertTrue(Attributes::has($annotated, ServerCallback::class));
        $this->assertFalse(Attributes::has($plain, ServerCallback::class));
    }
}

class ServerCallbackProbe
{
    #[ServerCallback]
    public function onServerStart(): void
    {
    }

    public function notACallback(): void
    {
    }
}
