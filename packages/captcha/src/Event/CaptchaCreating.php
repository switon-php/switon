<?php

declare(strict_types=1);

namespace Switon\Captcha\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted before CAPTCHA challenge generation starts.
 *
 * Log category: <code>switon.captcha.creating</code>
 * Payload: width, height, ttl, and optional tag.
 *
 * Guidance: Event only carries generation intent and optional tag; fetch IP/userId/endpoint in listeners.
 *
 * @see \Switon\Captcha\CaptchaInterface
 * @see \Switon\Captcha\AbstractCaptcha
 */
#[EventLevel(Severity::DEBUG)]
class CaptchaCreating
{
    public function __construct(
        public int     $width,
        public int     $height,
        public int     $ttl,
        public ?string $tag
    ) {
    }
}
