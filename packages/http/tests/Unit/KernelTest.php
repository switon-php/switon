<?php

declare(strict_types=1);

namespace Switon\Http\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Switon\Core\InputInterface;
use Switon\Http\RequestInterface;
use Switon\Http\Tests\Fixtures\HttpKernelProbe;

class KernelTest extends TestCase
{
    public function testKernelServicesBindInputToRequest(): void
    {
        $kernel = new HttpKernelProbe(__DIR__);

        $this->assertSame(
            [InputInterface::class => RequestInterface::class],
            $kernel->defaultServicesForTest()
        );
    }
}
