<?php

declare(strict_types=1);

namespace Switon\Captcha\Exception;

/**
 * Exception for CAPTCHA challenges with no remaining verification attempts.
 *
 * @see \Switon\Captcha\Exception\InvalidCaptchaException
 */
class CaptchaAttemptsExhaustedException extends InvalidCaptchaException
{
}
