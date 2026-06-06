<?php

declare(strict_types=1);

namespace Switon\Captcha\Adapter;

use Imagick as PhpImagick;
use ImagickDraw;
use ImagickPixel;
use Switon\Captcha\AbstractCaptcha;
use Switon\Core\Attribute\Autowired;
use Switon\Core\PathAliasInterface;

use function ceil;
use function count;
use function floor;
use function max;
use function min;
use function round;
use function strlen;

/**
 * Imagick adapter for rendering CAPTCHA challenge images.
 *
 * @see \Switon\Captcha\AbstractCaptcha
 * @see \Switon\Captcha\CaptchaInterface
 */
class Imagick extends AbstractCaptcha
{
    #[Autowired] protected PathAliasInterface $pathAlias;

    /**
     * Draws a CAPTCHA image with randomized text distortion and noise characters.
     *
     * @param string $code The text code to render in the CAPTCHA
     * @param int $width Image width in pixels
     * @param int $height Image height in pixels
     *
     * @return string Binary JPEG image data
     */
    public function draw(string $code, int $width, int $height): string
    {
        $image = new PhpImagick();
        $draw = new ImagickDraw();
        $background = "rgb({$this->background[0]},{$this->background[1]},{$this->background[2]})";
        $image->newImage($width, $height, new ImagickPixel($background));
        $draw->setFont(
            $this->pathAlias->resolve(
                $this->fonts[$this->random->int(0, count($this->fonts) - 1)]
            )
        );
        $draw->setGravity(PhpImagick::GRAVITY_NORTHWEST);

        $length = strlen($code);
        $cells = max(1, $length);
        $cellWidth = $width / $cells;
        // Keep glyphs readable on the default canvas while still fitting one glyph per cell.
        $referenceFontSize = max(10.0, min($height * 0.72, $cellWidth * 0.86));
        $effectiveYNoise = max(1, (int)($this->y_noise / 2));
        $canvasMargin = 1;
        $font_size = $referenceFontSize;

        for ($i = 0; $i < $length; $i++) {
            $font_size = $referenceFontSize * $this->random->int(900, 1050) / 1000;
            $fgPixel = new ImagickPixel(
                'rgb('
                . $this->random->int(0, 240) . ','
                . $this->random->int(0, 240) . ','
                . $this->random->int(0, 240)
                . ')'
            );
            $angle = $this->random->int(-$this->angle_noise, $this->angle_noise);
            $maxGlyphWidth = max(1, (int)floor($cellWidth) - $canvasMargin * 2);
            $maxGlyphHeight = max(1, $height - $canvasMargin * 2);
            $minMainGlyphHeight = max(10, (int)round($height * 0.58));

            $glyph = null;
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $glyph = $this->renderGlyphImage($draw, $code[$i], $font_size, $fgPixel, $angle);
                $currentWidth = $glyph->getImageWidth();
                $currentHeight = $glyph->getImageHeight();
                if ($currentHeight < $minMainGlyphHeight) {
                    $scaleByHeight = $minMainGlyphHeight / max(1, $currentHeight);
                    $scaleByWidth = $maxGlyphWidth / max(1, $currentWidth);
                    $scale = min($scaleByHeight, $scaleByWidth);
                    if ($scale > 1.0) {
                        $resizedWidth = max(1, (int)round($currentWidth * $scale));
                        $resizedHeight = max(1, (int)round($currentHeight * $scale));
                        $glyph->resizeImage(
                            $resizedWidth,
                            $resizedHeight,
                            PhpImagick::FILTER_LANCZOS,
                            1
                        );
                        $glyph->setImagePage(0, 0, 0, 0);
                    }
                }
                if (
                    $glyph->getImageWidth() <= $maxGlyphWidth
                    && $glyph->getImageHeight() <= $maxGlyphHeight
                ) {
                    break;
                }

                $glyph->clear();
                $glyph = null;
                $font_size = max(9.0, $font_size * 0.92);
            }

            if ($glyph === null) {
                $fgPixel->clear();
                $fgPixel->destroy();
                continue;
            }

            $glyphWidth = $glyph->getImageWidth();
            $glyphHeight = $glyph->getImageHeight();

            $cellLeft = (int)round($i * $cellWidth) + $canvasMargin;
            $cellRight = (int)round(($i + 1) * $cellWidth) - $canvasMargin;
            if ($cellRight <= $cellLeft) {
                $cellLeft = $canvasMargin;
                $cellRight = $width - $canvasMargin;
            }
            $minX = max($canvasMargin, $cellLeft);
            $maxX = min($width - $canvasMargin - $glyphWidth, $cellRight - $glyphWidth);
            if ($maxX < $minX) {
                $fallbackX = max($canvasMargin, min($minX, $width - $canvasMargin - $glyphWidth));
                $minX = $fallbackX;
                $maxX = $fallbackX;
            }
            $targetX = (int)round($i * $cellWidth + $cellWidth * 0.5 - $glyphWidth / 2.0);
            $xJitter = min($this->x_noise, max(0, (int)floor(($maxX - $minX) / 2)));
            $x = $targetX + ($xJitter > 0 ? $this->random->int(-$xJitter, $xJitter) : 0);
            $x = max($minX, min($x, $maxX));

            $minY = $canvasMargin;
            $maxY = $height - $canvasMargin - $glyphHeight;
            if ($maxY < $minY) {
                $maxY = $canvasMargin;
            }
            $baseY = (int)round(($minY + $maxY) / 2.0);
            $yJitter = min($effectiveYNoise, max(0, (int)floor(($maxY - $minY) / 2)));
            $y = $baseY + ($yJitter > 0 ? $this->random->int(-$yJitter, $yJitter) : 0);
            $y = max($minY, min($y, $maxY));

            $image->compositeImage($glyph, PhpImagick::COMPOSITE_OVER, $x, $y);

            $glyph->clear();
            $fgPixel->clear();
            $fgPixel->destroy();
        }

        for ($k = 0; $k < $this->char_noise; $k++) {
            $letter = $this->charset[$this->random->int(0, strlen($this->charset) - 1)];
            $red = $this->random->int(0, 240);
            $green = $this->random->int(0, 240);
            $blue = $this->random->int(0, 240);
            $fgPixel = new ImagickPixel("rgb($red,$green,$blue)");
            $noiseFontSize = max(8.0, $font_size * 0.45 * $this->rand_amplitude(0.1));
            $angle = $this->random->int(-$this->angle_noise, $this->angle_noise);
            $maxGlyphWidth = max(1, $width - $canvasMargin * 2);
            $maxGlyphHeight = max(1, $height - $canvasMargin * 2);

            $glyph = null;
            for ($attempt = 0; $attempt < 6; $attempt++) {
                $glyph = $this->renderGlyphImage($draw, $letter, $noiseFontSize, $fgPixel, $angle);
                if (
                    $glyph->getImageWidth() <= $maxGlyphWidth
                    && $glyph->getImageHeight() <= $maxGlyphHeight
                ) {
                    break;
                }

                $glyph->clear();
                $glyph = null;
                $noiseFontSize = max(6.0, $noiseFontSize * 0.9);
            }

            if ($glyph === null) {
                $fgPixel->clear();
                $fgPixel->destroy();
                continue;
            }

            $noiseMaxX = max($canvasMargin, $width - $canvasMargin - $glyph->getImageWidth());
            $noiseMaxY = max($canvasMargin, $height - $canvasMargin - $glyph->getImageHeight());
            $noiseX = $this->random->int($canvasMargin, $noiseMaxX);
            $noiseY = $this->random->int($canvasMargin, $noiseMaxY);
            $image->compositeImage($glyph, PhpImagick::COMPOSITE_OVER, $noiseX, $noiseY);

            $glyph->clear();
            $fgPixel->clear();
            $fgPixel->destroy();
        }

        $this->applyWaveDistortion($image, $background, $width, $height, $length);

        $image->setImageFormat('jpeg');
        $content = (string)$image;
        $image->clear();
        $draw->destroy();

        return $content;
    }

    /**
     * Applies a light wave distortion and normalizes the canvas back to the requested size.
     */
    protected function applyWaveDistortion(
        PhpImagick $image,
        string     $background,
        int        $width,
        int        $height,
        int        $length
    ): void {
        if ($this->distort <= 0.0) {
            return;
        }

        $backgroundPixel = new ImagickPixel($background);
        $image->setImageBackgroundColor($backgroundPixel);
        $image->waveImage(
            $this->distort,
            max(18.0, $width / max(1, $length) * 1.6)
        );
        $backgroundPixel->clear();
        $backgroundPixel->destroy();

        $distortedWidth = $image->getImageWidth();
        $distortedHeight = $image->getImageHeight();

        if ($distortedWidth > $width || $distortedHeight > $height) {
            $offsetX = max(0, (int)floor(($distortedWidth - $width) / 2));
            $offsetY = max(0, (int)floor(($distortedHeight - $height) / 2));
            $image->cropImage(min($width, $distortedWidth), min($height, $distortedHeight), $offsetX, $offsetY);
            $image->setImagePage(0, 0, 0, 0);
            $distortedWidth = $image->getImageWidth();
            $distortedHeight = $image->getImageHeight();
        }

        if ($distortedWidth < $width || $distortedHeight < $height) {
            $offsetX = (int)round(($width - $distortedWidth) / 2);
            $offsetY = (int)round(($height - $distortedHeight) / 2);
            $image->extentImage($width, $height, $offsetX, $offsetY);
            $image->setImagePage(0, 0, 0, 0);
        }
    }

    /**
     * Renders one glyph to a transparent image and rotates it before compositing.
     */
    protected function renderGlyphImage(
        ImagickDraw  $baseDraw,
        string       $text,
        float        $fontSize,
        ImagickPixel $color,
        int          $angle
    ): PhpImagick {
        $draw = clone $baseDraw;
        $draw->setFontSize($fontSize);
        $draw->setFillColor($color);

        $probe = new PhpImagick();
        $metrics = $probe->queryFontMetrics($draw, $text, false);
        $probe->clear();

        $textWidth = (float)$metrics['textWidth'];
        $textHeight = (float)$metrics['textHeight'];
        $padding = max(1, (int)ceil($fontSize * 0.1));

        $glyphWidth = max(1, (int)ceil($textWidth) + $padding * 2);
        $glyphHeight = max(1, (int)ceil($textHeight) + $padding * 2);

        $glyph = new PhpImagick();
        $glyph->newImage($glyphWidth, $glyphHeight, new ImagickPixel('transparent'), 'png');
        $glyph->annotateImage($draw, $padding, $padding, 0, $text);
        if ($angle !== 0) {
            $glyph->rotateImage(new ImagickPixel('transparent'), $angle);
        }
        // Remove transparent rim created by rotation so fit checks use the real glyph box.
        $glyph->trimImage(0.0);
        $glyph->setImagePage(0, 0, 0, 0);
        $draw->destroy();

        return $glyph;
    }
}
