<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Fixtures;

use Switon\Captcha\AbstractCaptcha;

/**
 * Test CAPTCHA implementation that returns dummy image data.
 *
 * Used for testing AbstractCaptcha functionality without requiring
 * GD or ImageMagick extensions.
 */
class TestCaptcha extends AbstractCaptcha
{
    /**
     * Last drawn code for testing.
     */
    protected ?string $lastDrawnCode = null;

    /**
     * Last drawn dimensions for testing.
     */
    protected ?array $lastDrawnDimensions = null;

    /**
     * {@inheritDoc}
     */
    public function draw(string $code, int $width, int $height): string
    {
        $this->lastDrawnCode = $code;
        $this->lastDrawnDimensions = ['width' => $width, 'height' => $height];

        // Return dummy JPEG-like content
        return "FAKE_IMAGE:{$code}:{$width}x{$height}";
    }

    /**
     * Get the last drawn code.
     */
    public function getLastDrawnCode(): ?string
    {
        return $this->lastDrawnCode;
    }

    /**
     * Get the last drawn dimensions.
     *
     * @return array{width: int, height: int}|null
     */
    public function getLastDrawnDimensions(): ?array
    {
        return $this->lastDrawnDimensions;
    }

    /**
     * Get background color for testing.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    public function getBackground(): array
    {
        return $this->background;
    }

    /**
     * Set background color for testing.
     *
     * @param array{0: int, 1: int, 2: int} $background
     */
    public function setBackground(array $background): void
    {
        $this->background = $background;
    }

    /**
     * Get charset for testing.
     */
    public function getCharset(): string
    {
        return $this->charset;
    }

    /**
     * Get length for testing.
     */
    public function getLength(): int
    {
        return $this->length;
    }

    /**
     * Get min_interval for testing.
     */
    public function getMinInterval(): int
    {
        return $this->min_interval;
    }

    /**
     * Set min_interval for testing.
     */
    public function setMinInterval(int $interval): void
    {
        $this->min_interval = $interval;
    }

    /**
     * Get attempts for testing.
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }

    /**
     * Set attempts for testing.
     */
    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }
}
