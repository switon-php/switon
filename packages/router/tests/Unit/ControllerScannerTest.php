<?php

declare(strict_types=1);

namespace Switon\Routing\Tests\Unit;

use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use Switon\Core\ClassScannerInterface;
use Switon\Routing\Attribute\RequestMapping;
use Switon\Routing\ControllerScanner;
use Switon\Routing\Tests\TestCase;

#[AllowMockObjectsWithoutExpectations]
class ControllerScannerTest extends TestCase
{
    protected ClassScannerInterface&MockObject $classScanner;

    protected function setUpContainer(): void
    {
        parent::setUpContainer();

        $this->classScanner = $this->createMock(ClassScannerInterface::class);
        $this->container->replace(ClassScannerInterface::class, $this->classScanner);
    }

    public function testGetControllersScansEveryTime(): void
    {
        $this->classScanner->expects($this->exactly(2))
            ->method('scan')
            ->with(
                [
                    '@app/Controller/*Controller.php' => 'App\\Controller\\*Controller',
                    '@app/Areas/*/Controller/*Controller.php' => 'App\\Areas\\*\\Controller\\*Controller',
                ],
                RequestMapping::class
            )
            ->willReturnOnConsecutiveCalls(
                ['App\\Controller\\FirstController'],
                ['App\\Controller\\SecondController']
            );

        $scanner = $this->container->make(ControllerScanner::class);

        $this->assertSame(['App\\Controller\\FirstController'], $scanner->getControllers());
        $this->assertSame(['App\\Controller\\SecondController'], $scanner->getControllers());
    }
}
