<?php

declare(strict_types=1);

namespace Switon\Captcha;

use Switon\Captcha\Adapter\Gd;
use Switon\Captcha\Adapter\Imagick;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\MakerInterface;

use function extension_loaded;

/**
 * Factory that selects the available CAPTCHA renderer adapter.
 *
 * Guidance: Use automatic adapter selection unless deployment requires a fixed renderer.
 *
 * Road-signs:
 * - imagick is preferred when loaded
 * - gd is the fallback renderer
 * - parameters are forwarded to the chosen adapter
 *
 * @see \Switon\Captcha\CaptchaInterface
 * @see \Switon\Captcha\AbstractCaptcha
 * @see \Switon\Captcha\Adapter\Imagick
 * @see \Switon\Captcha\Adapter\Gd
 */
class Captcha
{
    #[Autowired] protected MakerInterface $maker;

    /**
     * Creates a CAPTCHA adapter based on installed PHP extensions.
     *
     * @param array<string, mixed> $parameters Adapter constructor overrides from the container
     *
     * @return CaptchaInterface Imagick adapter when available, otherwise GD adapter
     *
     * @throws RuntimeException When neither <code>imagick</code> nor <code>gd</code> is available
     */
    public function __invoke(array $parameters): CaptchaInterface
    {
        if ($this->hasImagickExtension()) {
            /** @var CaptchaInterface $captcha */
            $captcha = $this->maker->make(Imagick::class, $parameters);

            return $captcha;
        }

        if ($this->hasGdExtension()) {
            /** @var CaptchaInterface $captcha */
            $captcha = $this->maker->make(Gd::class, $parameters);

            return $captcha;
        }

        RuntimeException::raise(
            'No supported CAPTCHA adapter found. Please enable either the "imagick" or "gd" PHP extension.'
        );
    }

    protected function hasImagickExtension(): bool
    {
        return extension_loaded('imagick');
    }

    protected function hasGdExtension(): bool
    {
        return extension_loaded('gd');
    }
}
