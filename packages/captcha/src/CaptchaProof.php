<?php

declare(strict_types=1);

namespace Switon\Captcha;

use Switon\Binding\Attribute\ResolvedBy;

/**
 * CAPTCHA verification payload object.
 *
 * Guidance: Use this object as the verify input boundary instead of loose captcha key/code parameters.
 *
 * Road-signs:
 * - action param CaptchaProof
 * - CaptchaProofResolver fills this from request input
 * - key and code are the only public fields
 * - verify() consumes this object
 * - CaptchaInterface verify
 *
 * @see \Switon\Binding\Attribute\ResolvedBy
 * @see \Switon\Captcha\CaptchaProofResolver
 * @see \Switon\Captcha\CaptchaInterface::verify()
 *
 * @phpstan-consistent-constructor
 */
#[ResolvedBy(CaptchaProofResolver::class)]
class CaptchaProof
{
    public string $captcha_key = '';
    public string $captcha_code = '';

    public static function of(string $key, string $code): static
    {
        $proof = new static();
        $proof->captcha_key = $key;
        $proof->captcha_code = $code;
        return $proof;
    }
}
