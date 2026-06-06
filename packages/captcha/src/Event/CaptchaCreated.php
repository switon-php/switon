<?php

declare(strict_types=1);

namespace Switon\Captcha\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted after CAPTCHA challenge generation succeeds.
 *
 * Log category: <code>switon.captcha.created</code>
 * Payload: generated key, ttl, and optional tag.
 *
 * Guidance: Event only carries generated key and optional tag; fetch IP/userId/endpoint in listeners.
 *
 * @see \Switon\Captcha\CaptchaInterface
 * @see \Switon\Captcha\AbstractCaptcha
 */
#[EventLevel(Severity::DEBUG)]
class CaptchaCreated
{
    public function __construct(
        public string  $key,
        public int     $ttl,
        public ?string $tag
    ) {
    }
}
