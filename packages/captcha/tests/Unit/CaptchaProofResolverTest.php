<?php

declare(strict_types=1);

namespace Switon\Captcha\Tests\Unit;

use Switon\Captcha\CaptchaProof;
use Switon\Captcha\CaptchaProofResolver;
use Switon\Captcha\Exception\CaptchaProofRequiredException;
use Switon\Captcha\Tests\TestCase;
use Switon\Core\Exception\RuntimeException;
use Switon\Core\InputInterface;
use ReflectionMethod;
use ReflectionParameter;
use stdClass;

class CaptchaProofResolverTest extends TestCase
{
    public function testResolveThrowsWhenTypeIsNotCaptchaProof(): void
    {
        $input = $this->createStub(InputInterface::class);
        $this->container->replace(InputInterface::class, $input);
        $resolver = $this->make(CaptchaProofResolver::class);

        $parameter = $this->getCaptchaProofParameter();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CaptchaProofResolver only supports');

        $resolver->resolve($parameter, stdClass::class);
    }

    public function testResolveThrowsWhenCaptchaKeyIsMissing(): void
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('get')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return $key === 'captcha_key' ? '   ' : 'ABCD';
            });
        $this->container->replace(InputInterface::class, $input);
        $resolver = $this->make(CaptchaProofResolver::class);

        $this->expectException(CaptchaProofRequiredException::class);
        $this->expectExceptionMessage('Missing captcha_key');

        $resolver->resolve($this->getCaptchaProofParameter(), CaptchaProof::class);
    }

    public function testResolveThrowsWhenCaptchaCodeIsMissing(): void
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('get')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return $key === 'captcha_key' ? 'key-1' : '   ';
            });
        $this->container->replace(InputInterface::class, $input);
        $resolver = $this->make(CaptchaProofResolver::class);

        $this->expectException(CaptchaProofRequiredException::class);
        $this->expectExceptionMessage('Missing captcha_code');

        $resolver->resolve($this->getCaptchaProofParameter(), CaptchaProof::class);
    }

    public function testResolveBuildsCaptchaProofAndTrimsInput(): void
    {
        $input = $this->createStub(InputInterface::class);
        $input->method('get')
            ->willReturnCallback(static function (string $key, mixed $default = null): mixed {
                return $key === 'captcha_key' ? '  k-123  ' : '  aBc9  ';
            });
        $this->container->replace(InputInterface::class, $input);
        $resolver = $this->make(CaptchaProofResolver::class);

        $proof = $resolver->resolve($this->getCaptchaProofParameter(), CaptchaProof::class);

        $this->assertInstanceOf(CaptchaProof::class, $proof);
        $this->assertSame('k-123', $proof->captcha_key);
        $this->assertSame('aBc9', $proof->captcha_code);
    }

    protected function getCaptchaProofParameter(): ReflectionParameter
    {
        $method = new ReflectionMethod(self::class, 'resolverTarget');

        return $method->getParameters()[0];
    }

    protected function resolverTarget(CaptchaProof $proof): void
    {
    }
}
