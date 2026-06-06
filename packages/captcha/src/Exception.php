<?php

declare(strict_types=1);

namespace Switon\Captcha;

/**
 * Base exception for the Captcha component.
 *
 * Use this as the parent for CAPTCHA generation, verification, and storage failures.
 *
 * @see \Switon\Captcha\AbstractCaptcha
 */
class Exception extends \Switon\Core\Exception
{
}
