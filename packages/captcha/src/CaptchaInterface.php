<?php

declare(strict_types=1);

namespace Switon\Captcha;

use Switon\Captcha\Exception\InvalidCaptchaException;

/**
 * Contract for CAPTCHA challenge generation and verification.
 *
 * Guidance: Return <code>create()</code> payload to the client, then send its key back to <code>verify()</code>.
 *
 * Road-signs:
 * - create() returns key + image blob
 * - verify() consumes proof input object
 * - events cover create / verify / failure / success
 * - verification failures raise InvalidCaptchaException subclasses
 *
 * @see \Switon\Captcha\Captcha
 * @see \Switon\Captcha\AbstractCaptcha
 * @see \Switon\Captcha\StoreInterface
 * @see \Switon\Captcha\Exception\InvalidCaptchaException
 * @see \Switon\Captcha\Event\CaptchaCreating
 * @see \Switon\Captcha\Event\CaptchaFailed
 * @see \Switon\Captcha\Event\CaptchaVerifying
 * @see \Switon\Captcha\Event\CaptchaVerified
 */
interface CaptchaInterface
{
    /**
     * Creates a new CAPTCHA challenge.
     *
     * @param int $width Image width in pixels (default: 120)
     * @param int $height Image height in pixels (default: 40)
     * @param int $ttl Time-to-live in seconds (default: 300)
     * @param string|null $tag Optional business tag included in CAPTCHA events for monitoring and analytics
     *
     * @return array{key: string, blob: string} Unique key and image data URI (base64 JPEG)
     */
    public function create(int $width = 120, int $height = 40, int $ttl = 300, ?string $tag = null): array;

    /**
     * Verifies user input for a previously created challenge.
     *
     * Code comparison is case-insensitive.
     *
     * @param CaptchaProof $proof Proof object containing key and user-provided code
     * @param string|null $tag Optional business tag included in CAPTCHA events for monitoring and analytics
     *
     * @throws InvalidCaptchaException When validation fails
     */
    public function verify(CaptchaProof $proof, ?string $tag = null): void;
}
