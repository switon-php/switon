<?php

declare(strict_types=1);

namespace Switon\Captcha\Exception;

use Switon\Captcha\Exception as BaseException;

/**
 * Raised when CAPTCHA proof input is missing required fields.
 *
 * @see \Switon\Captcha\CaptchaProofResolver
 */
class CaptchaProofRequiredException extends BaseException
{
    public function getStatusCode(): int
    {
        return 400;
    }
}
