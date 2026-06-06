<?php

declare(strict_types=1);

namespace Switon\Captcha\Attribute;

use Attribute;
use Switon\Captcha\CaptchaInterface;
use Switon\Captcha\CaptchaProof;
use Switon\Http\RequestInterface;
use Switon\Invocation\Attribute\GuardInterface;

/**
 * Action guard that requires one valid CAPTCHA proof before the action body runs.
 *
 * Guidance: Use on HTTP actions that must gate entry with the standard captcha_key/captcha_code proof pair.
 *
 * @see \Switon\Invocation\Attribute\GuardInterface
 * @see \Switon\Captcha\CaptchaInterface::verify()
 * @see \Switon\Captcha\CaptchaProof
 */
#[Attribute(Attribute::TARGET_METHOD)]
class CaptchaRequired implements GuardInterface
{
    public function __construct(
        public string  $keyField = 'captcha_key',
        public string  $codeField = 'captcha_code',
        public ?string $tag = null,
    ) {
    }

    public function handle(CaptchaInterface $captcha, RequestInterface $request): void
    {
        $captcha->verify(CaptchaProof::of(
            (string)$request->post($this->keyField, ''),
            (string)$request->post($this->codeField, ''),
        ), $this->tag);
    }
}
