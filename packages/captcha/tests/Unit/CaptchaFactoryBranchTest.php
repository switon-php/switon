<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Unit;

use Switon\Captcha\Adapter\Gd;
use Switon\Captcha\Adapter\Imagick;
use Switon\Captcha\Captcha;
use PHPUnit\Framework\TestCase;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\MakerInterface;

class CaptchaFactoryBranchTest extends TestCase
{
    public function testInvokePrefersImagickWhenExtensionIsLoaded(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $maker->expects($this->once())
            ->method('make')
            ->with(Imagick::class, ['size' => 42])
            ->willReturn($this->createStub(\Switon\Captcha\CaptchaInterface::class));

        $captcha = new CaptchaFactoryBranchFixture($maker, true, false);

        $captcha(['size' => 42]);

        $this->addToAssertionCount(1);
    }

    public function testInvokeFallsBackToGdWhenImagickIsUnavailable(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $maker->expects($this->once())
            ->method('make')
            ->with(Gd::class, ['length' => 6])
            ->willReturn($this->createStub(\Switon\Captcha\CaptchaInterface::class));

        $captcha = new CaptchaFactoryBranchFixture($maker, false, true);

        $captcha(['length' => 6]);

        $this->addToAssertionCount(1);
    }

    public function testInvokeReturnsExactInstanceProducedByMaker(): void
    {
        $expected = $this->createStub(\Switon\Captcha\CaptchaInterface::class);

        $maker = $this->createMock(MakerInterface::class);
        $maker->expects($this->once())
            ->method('make')
            ->with(Imagick::class, ['width' => 160])
            ->willReturn($expected);

        $captcha = new CaptchaFactoryBranchFixture($maker, true, true);

        $actual = $captcha(['width' => 160]);

        $this->assertSame($expected, $actual);
    }

    public function testInvokeThrowsWhenNoSupportedExtensionsAreLoaded(): void
    {
        $maker = $this->createMock(MakerInterface::class);
        $maker->expects($this->never())->method('make');

        $captcha = new CaptchaFactoryBranchFixture($maker, false, false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No supported CAPTCHA adapter found');

        $captcha([]);
    }

    public function testGdProbeReadsTheLoadedExtension(): void
    {
        $captcha = new class($this->createStub(MakerInterface::class)) extends Captcha {
            public function __construct(MakerInterface $maker)
            {
                $this->maker = $maker;
            }

            public function gdLoaded(): bool
            {
                return $this->hasGdExtension();
            }
        };

        $this->assertTrue($captcha->gdLoaded());
    }
}

class CaptchaFactoryBranchFixture extends Captcha
{
    public function __construct(MakerInterface $maker, protected bool $imagickLoaded, protected bool $gdLoaded)
    {
        $this->maker = $maker;
    }

    protected function hasImagickExtension(): bool
    {
        return $this->imagickLoaded;
    }

    protected function hasGdExtension(): bool
    {
        return $this->gdLoaded;
    }
}
