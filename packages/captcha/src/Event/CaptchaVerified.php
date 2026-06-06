<?php

declare(strict_types=1);

namespace Switon\Captcha\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted after successful CAPTCHA verification.
 *
 * Log category: <code>switon.captcha.verified</code>
 * Payload: captcha key, optional tag, and remaining attempts.
 *
 * Guidance: Event only carries verification result and optional tag; fetch IP/userId/endpoint in listeners.
 *
 * @see \Switon\Captcha\CaptchaInterface
 * @see \Switon\Captcha\AbstractCaptcha
 */
#[EventLevel(Severity::DEBUG)]
class CaptchaVerified
{
    public function __construct(
        public string  $key,
        public ?string $tag,
        public int     $attemptsRemaining
    ) {
    }
}
