<?php

declare(strict_types=1);

namespace Switon\Captcha;

use ReflectionParameter;
use Switon\Binding\ValueResolverInterface;
use Switon\Captcha\Exception\CaptchaProofRequiredException;
use Switon\Core\Attribute\Autowired;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\InputInterface;

use function trim;

/**
 * Resolves CaptchaProof from request input.
 *
 * Guidance: Keep resolver strict; missing captcha input must fail fast with a bad-request exception.
 *
 * Road-signs:
 * - ValueResolverInterface
 * - requires captcha_key
 * - requires captcha_code
 * - returns a strict proof object
 * - CaptchaProof boundary
 *
 * @see \Switon\Binding\ValueResolverInterface
 * @see \Switon\Captcha\CaptchaProof
 * @see \Switon\Captcha\Exception\CaptchaProofRequiredException
 */
class CaptchaProofResolver implements ValueResolverInterface
{
    #[Autowired] protected InputInterface $input;

    public function resolve(ReflectionParameter $parameter, string $type): CaptchaProof
    {
        if ($type !== CaptchaProof::class) {
            RuntimeException::raise(
                'CaptchaProofResolver only supports "{expected}", got "{actual}".',
                ['expected' => CaptchaProof::class, 'actual' => $type]
            );
        }

        $key = trim((string)$this->input->get('captcha_key', ''));
        $code = trim((string)$this->input->get('captcha_code', ''));
        if ($key === '') {
            CaptchaProofRequiredException::raise(
                'Missing captcha_key: request payload must include captcha_key.'
            );
        }
        if ($code === '') {
            CaptchaProofRequiredException::raise(
                'Missing captcha_code: please enter the captcha code.'
            );
        }

        return CaptchaProof::of($key, $code);
    }
}
