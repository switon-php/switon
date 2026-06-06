<?php

declare(strict_types=1);

namespace Switon\Captcha\Exception;

/**
 * Exception for missing CAPTCHA payload in the configured store.
 *
 * @see \Switon\Captcha\Exception\InvalidCaptchaException
 */
class CaptchaNotFoundException extends InvalidCaptchaException
{
}
