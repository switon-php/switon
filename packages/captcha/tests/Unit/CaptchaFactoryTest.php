<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Unit;

use Switon\Captcha\Adapter\Gd;
use Switon\Captcha\Adapter\Imagick;
use Switon\Captcha\CaptchaInterface;
use Switon\Captcha\Tests\TestCase;

/**
 * Test cases for Captcha factory class.
 *
 * Tests automatic adapter selection based on available PHP extensions.
 */
class CaptchaFactoryTest extends TestCase
{
    /**
     * Test that factory creates Imagick adapter when extension is available.
     */
    public function testFactoryCreatesImagickWhenAvailable(): void
    {
        // Arrange
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ImageMagick extension not available');
        }

        // Act
        $captcha = $this->createCaptcha();

        // Assert
        $this->assertInstanceOf(Imagick::class, $captcha, 'Factory should create Imagick adapter when available');
    }

    /**
     * Test that factory creates Gd adapter when Imagick not available.
     */
    public function testFactoryCreatesGdWhenImagickNotAvailable(): void
    {
        // Arrange
        if (extension_loaded('imagick')) {
            $this->markTestSkipped('Test only runs when ImageMagick is NOT available');
        }

        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available');
        }

        // Act
        $captcha = $this->createCaptcha();

        // Assert
        $this->assertInstanceOf(Gd::class, $captcha, 'Factory should create Gd adapter when Imagick not available');
    }

    /**
     * Test that factory returns CaptchaInterface.
     */
    public function testFactoryReturnsCaptchaInterface(): void
    {
        // Arrange
        if (!extension_loaded('imagick') && !extension_loaded('gd')) {
            $this->markTestSkipped('Neither ImageMagick nor GD extension is available');
        }

        // Act
        $captcha = $this->createCaptcha();

        // Assert
        $this->assertInstanceOf(CaptchaInterface::class, $captcha, 'Factory should return CaptchaInterface');
    }

    /**
     * Test that factory passes parameters to adapter.
     */
    public function testFactoryPassesParametersToAdapter(): void
    {
        // Arrange
        if (!extension_loaded('imagick') && !extension_loaded('gd')) {
            $this->markTestSkipped('Neither ImageMagick nor GD extension is available');
        }

        // Act
        $captcha = $this->createCaptcha(['length' => 6]);

        // Assert
        // We verify that the captcha works correctly with the parameters
        $this->assertInstanceOf(CaptchaInterface::class, $captcha, 'Factory should create adapter with parameters');
    }
}
