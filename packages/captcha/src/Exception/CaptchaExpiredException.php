<?php

declare(strict_types=1);

namespace Switon\Captcha\Exception;

/**
 * Exception for CAPTCHA challenges that exceeded their TTL.
 *
 * @see \Switon\Captcha\Exception\InvalidCaptchaException
 */
class CaptchaExpiredException extends InvalidCaptchaException
{
}
