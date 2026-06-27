<?php

declare(strict_types=1);

namespace Switon\Captcha\Event;

use Switon\Core\SenderAwareInterface;
use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted before CAPTCHA verification starts.
 *
 * Log category: <code>switon.captcha.verifying</code>
 * Payload: captcha key and optional tag.
 *
 * Guidance: Event only carries verification intent and optional tag; fetch IP/userId/endpoint in listeners.
 *
 * @see \Switon\Captcha\CaptchaInterface
 * @see \Switon\Captcha\AbstractCaptcha
 */
#[EventLevel(Severity::DEBUG)]
class CaptchaVerifying implements SenderAwareInterface
{
    public function __construct(
        public \Switon\Captcha\CaptchaInterface $sender,
        public string  $key,
        public ?string $tag
    ) {
    }

    public function getSender(): \Switon\Captcha\CaptchaInterface
    {
        return $this->sender;
    }
}
