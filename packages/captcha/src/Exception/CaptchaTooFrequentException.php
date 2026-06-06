<?php

declare(strict_types=1);

namespace Switon\Captcha\Exception;

/**
 * Exception for CAPTCHA checks submitted before the minimum interval.
 *
 * @see \Switon\Captcha\Exception\InvalidCaptchaException
 */
class CaptchaTooFrequentException extends InvalidCaptchaException
{
}
