<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Unit;

use Imagick as PhpImagick;
use ImagickDraw;
use ImagickPixel;
use Switon\Captcha\Adapter\Imagick;
use Switon\Captcha\Tests\TestCase;

use function extension_loaded;
use function file_exists;
use function strlen;

final class AdapterImagickCoverageTest extends TestCase
{
    public function testImagickDrawWithDistortionKeepsRequestedCanvasSize(): void
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
            'char_noise' => 1,
            'x_noise' => 4,
            'y_noise' => 4,
            'angle_noise' => 15,
            'distort' => 2.5,
        ]);

        $content = $captcha->draw('ABCDE', 120, 40);

        $image = new PhpImagick();
        $image->readImageBlob($content);

        $this->assertSame(120, $image->getImageWidth());
        $this->assertSame(40, $image->getImageHeight());

        $image->clear();
        $image->destroy();
    }

    public function testImagickDrawWithNoiseAndTinyCanvasStillReturnsJpeg(): void
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
            'char_noise' => 3,
            'x_noise' => 6,
            'y_noise' => 6,
            'angle_noise' => 25,
            'size' => 30,
            'size_noise' => 10,
        ]);

        // Small-but-safe canvas: still exercises scaling/jitter/noise paths without triggering Imagick edge failures.
        $content = $captcha->draw('ABCDEFGH', 90, 30);

        $this->assertIsString($content);
        $this->assertGreaterThan(10, strlen($content));
        $this->assertSame("\xFF\xD8\xFF", $content[0] . $content[1] . $content[2]);
    }

    public function testImagickRenderGlyphImageRotatesAndTrims(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ImageMagick extension not available');
        }

        $font = $this->getFontPath();
        if (!file_exists($font)) {
            $this->markTestSkipped('Captcha font file not found: ' . $font);
        }

        $adapter = $this->make(Imagick::class, [
            'fonts' => [$font],
        ]);

        $draw = new ImagickDraw();
        $draw->setFont($font);
        $draw->setGravity(PhpImagick::GRAVITY_NORTHWEST);

        $color = new ImagickPixel('rgb(10,20,30)');

        // Call protected method via a tiny subclass.
        $probe = new class ($adapter) {
            public function __construct(private Imagick $adapter)
            {
            }

            public function render(ImagickDraw $draw, string $text, float $size, ImagickPixel $color, int $angle): PhpImagick
            {
                /** @noinspection PhpUndefinedMethodInspection */
                return (function (ImagickDraw $d, string $t, float $s, ImagickPixel $c, int $a): PhpImagick {
                    return $this->renderGlyphImage($d, $t, $s, $c, $a);
                })->call($this->adapter, $draw, $text, $size, $color, $angle);
            }
        };

        $glyph = $probe->render($draw, 'A', 24.0, $color, 15);

        $this->assertGreaterThan(0, $glyph->getImageWidth());
        $this->assertGreaterThan(0, $glyph->getImageHeight());

        $glyph->clear();
        $glyph->destroy();
        $color->clear();
        $color->destroy();
        $draw->destroy();
    }
}
