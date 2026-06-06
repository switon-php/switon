<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Unit;

use Switon\Captcha\Adapter\Gd;
use Switon\Captcha\Tests\TestCase;

use function extension_loaded;
use function file_exists;
use function function_exists;
use function strlen;

final class AdapterGdCoverageTest extends TestCase
{
    public function testGdDrawWithNoiseAndTinyCanvasStillReturnsJpeg(): void
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
            'char_noise' => 3,
            'x_noise' => 6,
            'y_noise' => 6,
            'angle_noise' => 15,
            'size' => 30,
            'size_noise' => 10,
        ]);

        // Very small canvas relative to glyphs to force fallback branches.
        $content = $captcha->draw('ABCDEFGH', 30, 14);

        $this->assertIsString($content);
        $this->assertGreaterThan(10, strlen($content));
        $this->assertSame("\xFF\xD8\xFF", $content[0] . $content[1] . $content[2]);
    }
}
