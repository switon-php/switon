<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Unit;

use Switon\Captcha\Adapter\Gd;
use Switon\Captcha\Adapter\Imagick;
use Switon\Captcha\Tests\TestCase;

use function extension_loaded;
use function file_exists;
use function function_exists;
use function strlen;

final class AdapterDrawTest extends TestCase
{
    public function testGdDefaultPresetReturnsJpegBinary(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available');
        }

        if (!function_exists('imagettftext') || !function_exists('imagettfbbox')) {
            $this->markTestSkipped('GD freetype functions not available (imagettftext/imagettfbbox)');
        }

        $captcha = $this->make(Gd::class);

        $content = $captcha->draw('ABCDE', 120, 40);

        $this->assertIsString($content);
        $this->assertGreaterThan(10, strlen($content));
        $this->assertSame("\xFF\xD8\xFF", $content[0] . $content[1] . $content[2]);
    }

    public function testGdDrawReturnsJpegBinary(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available');
        }

        if (!function_exists('imagettftext') || !function_exists('imagettfbbox')) {
            $this->markTestSkipped('GD freetype functions not available (imagettftext/imagettfbbox)');
        }

        $font = $this->getFontPath();
        if (!file_exists($font)) {
            $this->markTestSkipped('Captcha font file not found: ' . $font);
        }

        $captcha = $this->make(Gd::class, [
            'fonts' => [$font],
            'char_noise' => 0,
            'x_noise' => 0,
            'y_noise' => 0,
            'angle_noise' => 0,
        ]);

        $content = $captcha->draw('ABCD', 120, 40);

        $this->assertIsString($content);
        $this->assertGreaterThan(10, strlen($content));
        $this->assertSame("\xFF\xD8\xFF", $content[0] . $content[1] . $content[2]);
    }

    public function testImagickDefaultPresetReturnsJpegBinary(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ImageMagick extension not available');
        }

        $captcha = $this->make(Imagick::class);

        $content = $captcha->draw('ABCDE', 120, 40);

        $this->assertIsString($content);
        $this->assertGreaterThan(10, strlen($content));
        $this->assertSame("\xFF\xD8\xFF", $content[0] . $content[1] . $content[2]);
    }

    public function testImagickDrawReturnsJpegBinary(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ImageMagick extension not available');
        }

        $font = $this->getFontPath();
        if (!file_exists($font)) {
            $this->markTestSkipped('Captcha font file not found: ' . $font);
        }

        $captcha = $this->make(Imagick::class, [
            'fonts' => [$font],
            'char_noise' => 0,
            'x_noise' => 0,
            'y_noise' => 0,
            'angle_noise' => 0,
        ]);

        $content = $captcha->draw('ABCD', 120, 40);

        $this->assertIsString($content);
        $this->assertGreaterThan(10, strlen($content));
        $this->assertSame("\xFF\xD8\xFF", $content[0] . $content[1] . $content[2]);
    }
}
