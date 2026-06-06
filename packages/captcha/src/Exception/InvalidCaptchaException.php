<?php

declare(strict_types=1);

namespace Switon\Captcha\Exception;

use JetBrains\PhpStorm\ArrayShape;
use Switon\Captcha\Exception as BaseException;

/**
 * Base exception for CAPTCHA verification failures returned as field-level validation errors.
 *
 * Use when the caller should receive a validation-style response instead of a transport-level error.
 *
 * @see \Switon\Captcha\AbstractCaptcha
 */
class InvalidCaptchaException extends BaseException
{
    /** Default translation message key. */
    protected const string MESSAGE_KEY = 'captcha.invalid';

    /** Default fallback message. */
    protected const string MESSAGE_DEFAULT = 'Invalid captcha code';

    /** @return int HTTP 200 for validation feedback. */
    public function getStatusCode(): int
    {
        return 200;
    }

    /** @return array<string, mixed> JSON payload with error code, message key, and field. */
    #[ArrayShape(['code' => 'int', 'msg' => 'string', 'field' => 'string'])]
    public function getJson(): array
    {
        return ['code' => -1, 'msg' => static::MESSAGE_KEY, 'field' => 'captcha'];
    }
}
