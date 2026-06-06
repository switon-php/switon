<?php

declare(strict_types=1);

namespace Switon\Captcha\Adapter;

use Switon\Captcha\AbstractCaptcha;
use Switon\Core\Attribute\Autowired;
use Switon\Core\PathAliasInterface;

use function ceil;
use function count;
use function floor;
use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function imagettfbbox;
use function imagettftext;
use function max;
use function min;
use function ob_get_clean;
use function ob_start;
use function round;
use function strlen;

/**
 * GD adapter for rendering CAPTCHA challenge images.
 *
 * @see \Switon\Captcha\AbstractCaptcha
 * @see \Switon\Captcha\CaptchaInterface
 */
class Gd extends AbstractCaptcha
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
        $width = max(1, $width);
        $height = max(1, $height);
        $image = imagecreatetruecolor($width, $height);

        $bgColor = imagecolorallocate($image, $this->background[0], $this->background[1], $this->background[2]);

        imagefilledrectangle($image, 0, 0, $width, $height, $bgColor);

        $font_file = $this->pathAlias->resolve($this->fonts[$this->random->int(0, count($this->fonts) - 1)]);

        $length = strlen($code);
        $cells = max(1, $length);
        $cellWidth = $width / $cells;
        $effective_y_noise = max(1, (int)($this->y_noise / 2));
        $canvas_margin = 1.0;
        $min_font_by_height = max(10, (int)round($height * 0.58));
        $max_font_by_height = max(10, (int)round($height * 0.72));
        $max_font_by_width = max(10, (int)round($cellWidth * 0.82));
        for ($i = 0; $i < $length; $i++) {
            $font_size = max($min_font_by_height, $this->size + $this->random->int(-$this->size_noise, $this->size_noise));
            $font_size = min($font_size, $max_font_by_height, $max_font_by_width);
            $angle = $this->random->int(-$this->angle_noise, $this->angle_noise);
            $fg_color = imagecolorallocate(
                $image,
                $this->random->int(0, 240),
                $this->random->int(0, 240),
                $this->random->int(0, 240)
            );
            $bbox = imagettfbbox($font_size, $angle, $font_file, $code[$i]);
            if ($bbox === false) {
                continue;
            }

            $minX = min($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
            $maxX = max($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
            $minY = min($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
            $maxY = max($bbox[1], $bbox[3], $bbox[5], $bbox[7]);

            $cellLeft = $i * $cellWidth + $canvas_margin;
            $cellRight = ($i + 1) * $cellWidth - $canvas_margin;
            if ($cellRight <= $cellLeft) {
                $cellLeft = $canvas_margin;
                $cellRight = $width - $canvas_margin;
            }

            $minOriginX = max(
                $cellLeft - $minX,
                $canvas_margin - $minX
            );
            $maxOriginX = min(
                $cellRight - $maxX,
                $width - $canvas_margin - $maxX
            );
            if ($maxOriginX < $minOriginX) {
                $fallbackX = max($canvas_margin - $minX, min($minOriginX, $width - $canvas_margin - $maxX));
                $minOriginX = $fallbackX;
                $maxOriginX = $fallbackX;
            }

            $targetOriginX = $i * $cellWidth + $cellWidth * 0.5 - (($minX + $maxX) / 2.0);
            $xJitter = min($this->x_noise, max(0, (int)floor(($maxOriginX - $minOriginX) / 2.0)));
            $x = $targetOriginX + ($xJitter > 0 ? $this->random->int(-$xJitter, $xJitter) : 0);
            $x = max($minOriginX, min($x, $maxOriginX));

            $minOriginY = $canvas_margin - $minY;
            $maxOriginY = $height - $canvas_margin - $maxY;
            if ($maxOriginY < $minOriginY) {
                $fallbackY = max($canvas_margin - $minY, min($minOriginY, $height - $canvas_margin - $maxY));
                $minOriginY = $fallbackY;
                $maxOriginY = $fallbackY;
            }

            $baseOriginY = ($minOriginY + $maxOriginY) / 2.0;
            $yJitter = min($effective_y_noise, max(0, (int)floor(($maxOriginY - $minOriginY) / 2.0)));
            $y = $baseOriginY + ($yJitter > 0 ? $this->random->int(-$yJitter, $yJitter) : 0);
            $y = max($minOriginY, min($y, $maxOriginY));

            $x = (int)round($x);
            $y = (int)round($y);
            imagettftext($image, $font_size, $angle, $x, $y, $fg_color, $font_file, $code[$i]);
        }

        for ($k = 0; $k < $this->char_noise; $k++) {
            $font_file = $this->pathAlias->resolve($this->fonts[$this->random->int(0, count($this->fonts) - 1)]);

            $letter = $this->charset[$this->random->int(0, strlen($this->charset) - 1)];
            $fg_color = imagecolorallocate(
                $image,
                $this->random->int(0, 240),
                $this->random->int(0, 240),
                $this->random->int(0, 240)
            );
            $font_size = max(8, (int)($this->size / 2 + $this->random->int(-$this->size_noise, $this->size_noise)));
            $font_size = min($font_size, max(8, (int)round($height * 0.45)));
            $angle = $this->random->int(-$this->angle_noise, $this->angle_noise);
            $bbox = imagettfbbox($font_size, $angle, $font_file, $letter);
            if ($bbox === false) {
                continue;
            }

            $minX = min($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
            $maxX = max($bbox[0], $bbox[2], $bbox[4], $bbox[6]);
            $minY = min($bbox[1], $bbox[3], $bbox[5], $bbox[7]);
            $maxY = max($bbox[1], $bbox[3], $bbox[5], $bbox[7]);

            $minOriginX = (int)ceil($canvas_margin - $minX);
            $maxOriginX = (int)floor($width - $canvas_margin - $maxX);
            $minOriginY = (int)ceil($canvas_margin - $minY);
            $maxOriginY = (int)floor($height - $canvas_margin - $maxY);
            if ($minOriginX > $maxOriginX || $minOriginY > $maxOriginY) {
                continue;
            }

            $x = $this->random->int($minOriginX, $maxOriginX);
            $y = $this->random->int($minOriginY, $maxOriginY);
            imagettftext($image, $font_size, $angle, $x, $y, $fg_color, $font_file, $letter);
        }

        ob_start();
        imagejpeg($image, null, 90);
        $content = ob_get_clean();

        return $content === false ? '' : $content;
    }
}
