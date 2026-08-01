# Switon Captcha Package

[![CI](https://img.shields.io/github/actions/workflow/status/switon-php/captcha/ci.yml?branch=main&label=CI)](https://github.com/switon-php/captcha/actions/workflows/ci.yml) [![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777BB4)](https://www.php.net/)

Switon's CAPTCHA challenge, proof, and action-guard layer.

## Highlights

- **Challenge and verification flow:** generate a CAPTCHA, then verify the returned proof.
- **Standard request fields:** `CaptchaProofResolver` reads the usual CAPTCHA key and code fields.
- **Protected actions:** `#[CaptchaRequired]` blocks an action until proof is valid.
- **Image backends:** the package includes GD and Imagick adapters for image generation.
- **Observable checks:** creation, verification, and failure activity can be observed.

## Installation

```bash
composer require switon/captcha
```

## Quick Start

```php
use Switon\Captcha\CaptchaInterface;
use Switon\Captcha\Attribute\CaptchaRequired;
use Switon\Captcha\CaptchaProof;
use Switon\Core\Attribute\Autowired;

class LoginController
{
    #[Autowired] protected CaptchaInterface $captcha;

    public function getCaptcha(): array
    {
        return $this->captcha->create();
    }

    public function verifyAction(CaptchaProof $proof): void
    {
        $this->captcha->verify($proof);
    }

    #[CaptchaRequired]
    public function submitAction(): void
    {
    }
}
```

Docs: https://github.com/switon-php/captcha/blob/main/docs/README.md

## License

MIT.
