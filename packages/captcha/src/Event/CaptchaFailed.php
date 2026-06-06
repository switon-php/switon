<?php

declare(strict_types=1);

namespace Switon\Captcha\Event;

use Switon\Eventing\Attribute\EventLevel;
use Switon\Eventing\Severity;

/**
 * Event emitted when CAPTCHA verification fails for any reason.
 *
 * Log category: <code>switon.captcha.failed</code>
 * Payload: captcha key, optional tag, failure reason, and remaining attempts when known.
 *
 * Guidance: Match <code>$reason</code> against <code>REASON_*</code> constants; fetch IP/userId/endpoint in listeners.
 *
 * @see \Switon\Captcha\CaptchaInterface
 * @see \Switon\Captcha\AbstractCaptcha
 * @see \Switon\Captcha\Exception\InvalidCaptchaException
 */
#[EventLevel(Severity::INFO)]
class CaptchaFailed
{
    public const string REASON_NOT_FOUND = 'not_found';
    public const string REASON_DATA_INVALID = 'data_invalid';
    public const string REASON_TOO_FREQUENT = 'too_frequent';
    public const string REASON_EXPIRED = 'expired';
    public const string REASON_ATTEMPTS_EXHAUSTED = 'attempts_exhausted';
    public const string REASON_ALREADY_VERIFIED = self::REASON_ATTEMPTS_EXHAUSTED;
    public const string REASON_MISMATCH = 'mismatch';

    /**
     * @param string $reason One of the <code>REASON_*</code> constants
     */
    public function __construct(
        public string  $key,
        public ?string $tag,
        public string  $reason,
        public ?int    $attemptsRemaining
    ) {
    }
}
