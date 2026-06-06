<?php

declare(strict_types=1);

namespace Switon\Captcha\Exception;

/**
 * Exception for malformed or corrupted CAPTCHA payload data.
 *
 * @see \Switon\Captcha\Exception\InvalidCaptchaException
 */
class CaptchaDataInvalidException extends InvalidCaptchaException
{
}
